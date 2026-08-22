<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Support;

/**
 * Reading a value out of somebody else's payload.
 *
 * ── Why this is not just data_get() ──────────────────────────────────────────
 *
 * Laravel's data_get walks objects and lists by key and index, which handles
 * `billing.phone` and stops dead at the thing that actually matters here.
 *
 * Custom fields — the whole point of the requirement — are not stored as keys on
 * an object. Every platform in this market stores them as a *list of pairs*:
 *
 *     WooCommerce  meta_data:        [{id: 9, key: 'delivery_slot', value: 'AM'}]
 *     Shopify      note_attributes:  [{name: 'delivery_slot', value: 'AM'}]
 *     Shopify      metafields:       [{namespace: 'custom', key: 'slot', value: 'AM'}]
 *
 * To data_get, the path to that value is `meta_data.0.value` — an index that is
 * different for every order, and therefore useless as a mapping. What somebody
 * configuring this needs to write is `meta_data.delivery_slot`, and that is what
 * this resolves: at a list, the segment is matched against each item's key or
 * name rather than treated as an index.
 *
 * ── Missing is not null ──────────────────────────────────────────────────────
 *
 * A field a shop did not send and a field it sent as empty mean different
 * things, and collapsing them is how a push overwrites a real value with a blank
 * one. `resolve()` answers with a default, `has()` answers the question honestly,
 * and the sync layer asks the second before writing anything back.
 */
final class FieldPath
{
    /**
     * Stands for "there was nothing here".
     *
     * An object rather than null or a magic string, because both of those are
     * values a payload could legitimately contain — and the one time somebody's
     * custom field literally holds the string "__missing__" is not the day to
     * discover the sentinel was guessable.
     */
    private static ?object $missing = null;

    /** How deep flatten() will walk before it stops describing and summarises. */
    private const MAX_DEPTH = 6;

    /**
     * The containers that hold pairs rather than properties, and what each one
     * calls the name half.
     *
     * Reading, a pair list is recognised by its shape — see isPairList. Writing,
     * there is no shape to inspect yet, so the container has to be known by
     * name: building `meta_data` as an object would produce something Woo
     * accepts silently and stores as nothing.
     *
     * The name half differs between them. Woo says `key`; Shopify says `name`
     * in note_attributes and `key` under a `namespace` in metafields. Sending
     * the wrong one is a field that vanishes without an error.
     */
    private const PAIR_CONTAINERS = [
        'meta_data' => 'key',
        'metafields' => 'key',
        'custom_fields' => 'key',
        'note_attributes' => 'name',
        'attributes' => 'name',
        'properties' => 'name',
    ];

    public static function isPairContainer(string $segment): bool
    {
        return array_key_exists($segment, self::PAIR_CONTAINERS);
    }

    /**
     * Build one pair, in the shape its container expects.
     *
     * A name of 'custom.slot' inside metafields is a namespaced field and comes
     * apart again into the two keys Shopify stores it under.
     *
     * @return array<string, mixed>
     */
    public static function pair(string $container, string $name, mixed $value): array
    {
        $nameKey = self::PAIR_CONTAINERS[$container] ?? 'key';

        if ($container === 'metafields' && str_contains($name, '.')) {
            [$namespace, $key] = explode('.', $name, 2);

            return ['namespace' => $namespace, 'key' => $key, 'value' => $value];
        }

        return [$nameKey => $name, 'value' => $value];
    }

    /** Read one value, or the default when the path leads nowhere. */
    public static function resolve(array $data, string $path, mixed $default = null): mixed
    {
        $value = self::walk($data, $path);

        return $value === self::missing() ? $default : $value;
    }

    /** Did the payload actually carry this field, whatever its value? */
    public static function has(array $data, string $path): bool
    {
        return self::walk($data, $path) !== self::missing();
    }

    private static function missing(): object
    {
        return self::$missing ??= new class {};
    }

    /**
     * Walk the path, one segment at a time.
     *
     * Ordinary keys first, then pair-lists, then numeric indexes — in that
     * order, because a real key named `0` on an object should win over the first
     * item of something that merely looks like a list.
     */
    private static function walk(array $data, string $path): mixed
    {
        $segments = explode('.', $path);
        $current = $data;

        for ($i = 0; $i < count($segments); $i++) {
            if (! is_array($current)) {
                return self::missing();
            }

            $segment = $segments[$i];

            // A plain key. The common case, and the cheapest.
            if (array_key_exists($segment, $current)) {
                $current = $current[$segment];

                continue;
            }

            if (! array_is_list($current)) {
                return self::missing();
            }

            // A pair list: match the segment against each item's own name.
            $found = self::matchInPairs($current, $segment);

            if ($found !== self::missing()) {
                $current = $found;

                continue;
            }

            /*
             * A namespaced pair list — Shopify's metafields, where one field is
             * identified by two words. Consuming both segments here is what lets
             * a mapping read `metafields.custom.delivery_slot` rather than
             * exposing the namespace as a level of nesting it never was.
             */
            if (isset($segments[$i + 1])) {
                $found = self::matchInPairs($current, $segment.'.'.$segments[$i + 1]);

                if ($found !== self::missing()) {
                    $current = $found;
                    $i++;

                    continue;
                }
            }

            return self::missing();
        }

        return $current;
    }

    /** The value of the pair whose name matches, or the missing marker. */
    private static function matchInPairs(array $list, string $wanted): mixed
    {
        foreach ($list as $item) {
            if (! is_array($item) || ! array_key_exists('value', $item)) {
                continue;
            }

            $name = $item['key'] ?? $item['name'] ?? null;

            if ($name === null) {
                continue;
            }

            // Namespaced metafields are addressed as 'namespace.key'.
            if (isset($item['namespace'])) {
                $name = $item['namespace'].'.'.$name;
            }

            if ((string) $name === $wanted) {
                return $item['value'];
            }
        }

        return self::missing();
    }

    /**
     * Every readable path in a real payload, with the value found at it.
     *
     * ── Why this exists ──────────────────────────────────────────────────────
     *
     * Without it, mapping a custom field means asking somebody to type a path
     * from memory into a text box and find out at the next sync whether they got
     * it right. Nobody knows offhand that their delivery slot lives at
     * `meta_data._delivery_slot` with a leading underscore.
     *
     * With it, the settings screen fetches one real order and offers the paths
     * that actually exist in it, each next to the value it holds — so mapping is
     * recognising something rather than recalling it, and a typo is impossible
     * because nothing was typed.
     *
     * @return array<string, mixed> path => sample value
     */
    public static function flatten(array $data, string $prefix = '', int $depth = 0): array
    {
        $paths = [];

        foreach ($data as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (! is_array($value)) {
                $paths[$path] = $value;

                continue;
            }

            if ($depth >= self::MAX_DEPTH) {
                $paths[$path] = '…';

                continue;
            }

            if (self::isPairList($value)) {
                foreach ($value as $item) {
                    $name = $item['key'] ?? $item['name'] ?? null;

                    if ($name === null) {
                        continue;
                    }

                    if (isset($item['namespace'])) {
                        $name = $item['namespace'].'.'.$name;
                    }

                    $held = $item['value'] ?? null;
                    $paths[$path.'.'.$name] = is_array($held) ? '[list]' : $held;
                }

                continue;
            }

            if (array_is_list($value)) {
                if ($value === []) {
                    $paths[$path] = '[empty]';

                    continue;
                }

                // One sample from a repeating list. Showing all of them would
                // bury the fields somebody is looking for under forty line items
                // that share the same shape anyway.
                if (is_array($value[0])) {
                    $paths += self::flatten($value[0], $path.'.0', $depth + 1);

                    continue;
                }

                $paths[$path] = implode(', ', array_map(strval(...), array_slice($value, 0, 3)));

                continue;
            }

            $paths += self::flatten($value, $path, $depth + 1);
        }

        return $paths;
    }

    /**
     * Is this a list of {key|name, value} pairs rather than ordinary records?
     *
     * Judged on a sample and a majority rather than on all of them: a shop with
     * one odd entry in its meta_data should not lose the other nineteen, and a
     * list of line items — which have a `name` but no `value` — must not be
     * mistaken for custom fields.
     */
    private static function isPairList(array $list): bool
    {
        if ($list === [] || ! array_is_list($list)) {
            return false;
        }

        $sample = array_slice($list, 0, 5);
        $pairs = 0;

        foreach ($sample as $item) {
            if (is_array($item)
                && (isset($item['key']) || isset($item['name']))
                && array_key_exists('value', $item)) {
                $pairs++;
            }
        }

        return $pairs >= (int) ceil(count($sample) / 2);
    }
}
