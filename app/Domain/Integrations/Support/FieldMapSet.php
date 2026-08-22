<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Support;

use App\Domain\Integrations\Models\Integration;

/**
 * Every mapping rule for one connection and one entity, and the act of applying
 * them.
 *
 * ── Where these are stored ───────────────────────────────────────────────────
 *
 * In the connection's own `field_mappings` column, as JSON, rather than in a
 * table of their own. Mappings are always read whole — a sync needs all of an
 * entity's rules or none of them — never queried individually, and never joined
 * against anything. A table would buy ordering and querying that nothing asks
 * for, and cost a join on every record plus a save that can half-succeed.
 *
 * ── Why a connection with no mappings still works ────────────────────────────
 *
 * An empty set falls back to the platform's defaults. Somebody connecting a
 * WooCommerce shop should get their orders without first being made to explain
 * that `billing.phone` is a phone number — the standard fields of a standard
 * platform are known, and asking would be a form standing between the subscriber
 * and the thing they came for.
 *
 * Custom fields are the part nobody can guess, and those are what the mapping
 * screen is really for.
 */
final readonly class FieldMapSet
{
    /** @param list<FieldMap> $maps */
    private function __construct(
        public string $entity,
        public array $maps,
    ) {}

    /**
     * The rules in force for this connection and entity.
     *
     * Configured rules replace the defaults for the targets they mention and
     * sit alongside the rest, so somebody who only wanted to re-point one field
     * does not lose the twenty they never touched.
     */
    public static function for(Integration $integration, string $entity): self
    {
        $defaults = DefaultFieldMaps::for((string) $integration->provider, $entity);
        $configured = self::configured($integration, $entity);

        $byTarget = [];

        foreach ([...$defaults, ...$configured] as $map) {
            // Keyed by target: two rules writing the same field is a conflict
            // with no sensible resolution, and the configured one — the one
            // somebody chose on purpose — is the one that should win.
            $byTarget[$map->target] = $map;
        }

        return new self($entity, array_values(array_filter(
            $byTarget,
            fn (FieldMap $map): bool => $map->isUsable(),
        )));
    }

    /** Only what was configured, with no defaults behind it. */
    public static function configuredOnly(Integration $integration, string $entity): self
    {
        return new self($entity, self::configured($integration, $entity));
    }

    /** @return list<FieldMap> */
    private static function configured(Integration $integration, string $entity): array
    {
        $rows = data_get($integration->field_mappings ?? [], $entity, []);

        if (! is_array($rows)) {
            return [];
        }

        $maps = [];

        foreach ($rows as $row) {
            if (is_array($row)) {
                $maps[] = FieldMap::fromArray($row, $entity);
            }
        }

        return $maps;
    }

    /**
     * Translate one of their records into ours.
     *
     * ── What it will not do ──────────────────────────────────────────────────
     *
     * It will not invent a value. A mapping whose source is absent from this
     * payload is recorded as skipped and contributes nothing — so a webhook
     * carrying six fields produces six, and the reconciler is free to leave the
     * other fourteen exactly as they are. The alternative, filling absences with
     * null, means every partial payload is a destructive one.
     *
     * It will not write a field that failed its transform, either: "N/A" where a
     * number was expected is not a reason to store zero.
     *
     * @param  array<string, mixed>  $payload  as the platform sent it
     * @param  array<string, mixed>  $context  'currency' for money fields
     */
    public function apply(array $payload, array $context = []): MappedRecord
    {
        $attributes = [];
        $custom = [];
        $skipped = [];

        foreach ($this->maps as $map) {
            if (! $map->readsFromPlatform()) {
                continue;
            }

            $value = $map->isComposite()
                ? $this->join($payload, $map, $context)
                : $this->single($payload, $map, $context);

            /*
             * Nothing usable: either the shop did not send this field at all, or
             * it sent something the transform could not read — "N/A" where a
             * number was expected. Both mean leave our value alone. Writing null
             * or zero here is how a partial payload becomes a destructive one.
             */
            if ($value === null) {
                $skipped[] = $map->source;

                continue;
            }

            if ($map->isCustom()) {
                $custom[$map->customKey()] = $value;

                continue;
            }

            $attributes[$map->target] = $value;
        }

        return new MappedRecord($attributes, $custom, $skipped);
    }

    /**
     * One path, transformed.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context
     */
    private function single(array $payload, FieldMap $map, array $context): mixed
    {
        if (! FieldPath::has($payload, $map->source)) {
            return null;
        }

        return Transform::apply(FieldPath::resolve($payload, $map->source), $map->transform, $context);
    }

    /**
     * Several of their paths, joined into one of ours.
     *
     * Each piece is transformed before joining rather than after, so trimming
     * applies to the parts and a missing surname does not leave a trailing
     * space on every name in the shop. Blank pieces drop out entirely; all of
     * them blank is nothing at all, not an empty string.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context
     */
    private function join(array $payload, FieldMap $map, array $context): ?string
    {
        $pieces = [];

        foreach ($map->sources() as $path) {
            if (! FieldPath::has($payload, $path)) {
                continue;
            }

            $piece = Transform::apply(FieldPath::resolve($payload, $path), $map->transform, $context);

            if ($piece !== null && trim((string) $piece) !== '') {
                $pieces[] = trim((string) $piece);
            }
        }

        return $pieces === [] ? null : implode(' ', $pieces);
    }

    /**
     * The reverse: our record, as a payload for them.
     *
     * ── Two filters, not one ─────────────────────────────────────────────────
     *
     * A row must say it writes outward, *and* its target must not be something
     * the shop owns. The second is not redundant. Direction is stored per
     * connection and can be got wrong — a save that flattened every row to
     * "both" once armed a push that would have rewritten live order totals,
     * numbers and dates. EntityFields::isPlatformOwned does not depend on
     * stored configuration and so cannot be lost by a bad one.
     *
     * ── And the values are converted back ────────────────────────────────────
     *
     * Through Transform::reverse rather than sent as held. Money here is minor
     * units; a shop expects "2320.00", and 232000 is not that amount written
     * differently, it is a hundred times it.
     *
     * Nested by source path — `billing.phone` becomes a nested object, which is
     * the shape every one of these APIs expects on the way in.
     *
     * @param  array<string, mixed>  $attributes  from our own record
     * @param  array<string, mixed>  $custom  from the link
     * @param  array<string, mixed>  $context  'currency' for money
     * @return array<string, mixed>
     */
    public function reverse(array $attributes, array $custom = [], array $context = []): array
    {
        $payload = [];

        foreach ($this->maps as $map) {
            if (! $map->writesToPlatform() || EntityFields::isPlatformOwned($this->entity, $map->target)) {
                continue;
            }

            if ($map->isCustom()) {
                $key = $map->customKey();

                if (! array_key_exists($key, $custom)) {
                    continue;
                }

                $this->write($payload, $map->source, Transform::reverse($custom[$key], $map->transform, $context));

                continue;
            }

            if (! array_key_exists($map->target, $attributes)) {
                continue;
            }

            $this->write($payload, $map->source, Transform::reverse($attributes[$map->target], $map->transform, $context));
        }

        return $payload;
    }

    /**
     * Put one value at its path, respecting how the container stores things.
     *
     * An ordinary path nests. A path into a pair container — `meta_data.slot` —
     * appends a {key, value} pair instead, because that is the only shape these
     * APIs accept there. Written as an object it is not rejected; it is accepted
     * and stored as nothing, which is considerably harder to notice.
     *
     * @param  array<string, mixed>  $payload
     */
    private function write(array &$payload, string $path, mixed $value): void
    {
        $segments = explode('.', $path, 2);

        if (count($segments) === 2 && FieldPath::isPairContainer($segments[0])) {
            [$container, $name] = $segments;

            $payload[$container] ??= [];
            $payload[$container][] = FieldPath::pair($container, $name, $value);

            return;
        }

        data_set($payload, $path, $value);
    }

    /** @return list<FieldMap> */
    public function customs(): array
    {
        return array_values(array_filter($this->maps, fn (FieldMap $m): bool => $m->isCustom()));
    }

    public function isEmpty(): bool
    {
        return $this->maps === [];
    }

    /** @return list<array<string, mixed>> for the mapping screen */
    public function toArray(): array
    {
        return array_map(
            fn (FieldMap $map): array => $map->toArray() + ['display_label' => $map->displayLabel()],
            $this->maps,
        );
    }
}
