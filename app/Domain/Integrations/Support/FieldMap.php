<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Support;

/**
 * One rule: "their field there becomes our field here".
 *
 * ── Why one rule serves both directions ──────────────────────────────────────
 *
 * A mapping describes a correspondence, not an operation. Once somebody has
 * said that `billing.phone` over there is `shipping_phone` over here, that fact
 * is as true going out as coming in — so a pull reads the source and writes the
 * target, and a push reads the target and writes the source, from the same row.
 *
 * Keeping two lists instead would let them disagree, and a shop whose inbound
 * and outbound mappings disagree writes a value to one field and reads it back
 * from another until somebody notices the data drifting.
 *
 * Direction exists because some correspondences are genuinely one-way. An order
 * total is calculated by the shop and must never be pushed back over; a
 * fulfilment status is decided here and pushed out. Saying so on the rule is
 * what stops a two-way sync from fighting itself.
 */
final readonly class FieldMap
{
    /** Read from the shop; never written back. */
    public const IN = 'in';

    /** Written to the shop; never read from it. */
    public const OUT = 'out';

    /** Kept the same in both places. */
    public const BOTH = 'both';

    public function __construct(
        /** 'order' | 'product' | 'customer' */
        public string $entity,
        /** Where it lives in their payload — 'meta_data.delivery_slot'. */
        public string $source,
        /** Where it lives here — 'shipping_phone', or 'custom.delivery_slot'. */
        public string $target,
        public string $transform = 'trim',
        public string $direction = self::BOTH,
        public ?string $label = null,
        public bool $enabled = true,
        /**
         * Further paths joined onto the first, for a value they keep in pieces.
         *
         * Every one of these platforms stores a person's name as `first_name`
         * and `last_name`, and this application stores it as a name. Mapping
         * one of the two imports half of everybody's name, which is the kind of
         * defect that is noticed once the shop has three hundred orders in it.
         *
         * Not an expression language and not a template — a list of paths,
         * joined by a space, blanks dropped. That covers the case that actually
         * occurs without becoming a place where arbitrary logic can be typed.
         *
         * @var list<string>
         */
        public array $also = [],

        /**
         * The choices, for a type that has any.
         *
         * ── Why these are entered rather than discovered ─────────────────────
         *
         * An order carries the one value it happens to have. An order whose
         * priority is Medium says nothing whatsoever about Low and High
         * existing, and no amount of reading that order will reveal them —
         * a shop's own definition of its dropdown lives in the plugin that
         * created it, which is not something any API exposes.
         *
         * So the options are stated here, once, against the mapping. Every
         * order thereafter renders a real dropdown instead of a text box, and
         * a value that arrives from the shop is matched against them.
         *
         * Shape is [['label' => 'Facebook', 'value' => 'facebook'], ...] —
         * label for a person, value for the shop, because a shop that stores
         * 'facebook' should still read "Facebook" on screen.
         *
         * @var list<array{label: string, value: string}>
         */
        public array $options = [],

        /**
         * Show this field on the order or product edit screen.
         *
         * Not everything that syncs is worth editing. A shop sends bookkeeping
         * meta, plugin internals and audit trails alongside the fields somebody
         * actually fills in, and putting all of it on one screen buries the
         * eight fields that matter under forty that do not.
         *
         * Unchecking hides the field from the form. It keeps syncing — this
         * governs what is shown, never what is exchanged.
         */
        public bool $visible = true,
    ) {}

    /**
     * Rebuild one from stored JSON.
     *
     * Tolerant on the way in because these rows outlive the code that wrote
     * them: a saved mapping whose transform has since been retired should lose
     * the transform, not the mapping.
     *
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row, string $entity): self
    {
        $target = trim((string) ($row['target'] ?? ''));
        $transform = (string) ($row['transform'] ?? '');

        return new self(
            entity: $entity,
            source: trim((string) ($row['source'] ?? '')),
            target: $target,
            transform: Transform::exists($transform)
                ? $transform
                : EntityFields::defaultTransform($entity, $target),
            direction: in_array($row['direction'] ?? '', [self::IN, self::OUT, self::BOTH], true)
                ? (string) $row['direction']
                : self::BOTH,
            label: filled($row['label'] ?? null) ? (string) $row['label'] : null,
            enabled: (bool) ($row['enabled'] ?? true),
            also: array_values(array_filter(
                array_map(strval(...), (array) ($row['also'] ?? [])),
                fn (string $path): bool => trim($path) !== '',
            )),
            options: self::readOptions($row['options'] ?? []),

            // Absent means shown. Every mapping that predates this setting was
            // written when there was no way to hide anything, and defaulting
            // them to hidden would empty the edit screen of every shop.
            visible: (bool) ($row['visible'] ?? true),
        );
    }

    /**
     * Clean a stored or submitted option list.
     *
     * Accepts either the full shape or a bare list of strings, so a mapping
     * hand-written as ['low', 'medium'] still works and simply reads back with
     * the value doing duty as the label.
     *
     * Blank values are dropped and duplicates collapse, because a dropdown with
     * an empty choice in it is a dropdown somebody will pick by accident.
     *
     * @param  mixed  $raw
     * @return list<array{label: string, value: string}>
     */
    public static function readOptions(mixed $raw): array
    {
        $clean = [];

        foreach ((array) $raw as $row) {
            if (is_array($row)) {
                $value = trim((string) ($row['value'] ?? ''));
                $label = trim((string) ($row['label'] ?? ''));
            } else {
                $value = $label = trim((string) $row);
            }

            if ($value === '') {
                continue;
            }

            // Keyed by value so a list pasted twice does not double, and the
            // first spelling wins — a duplicate arriving later must not quietly
            // relabel a choice already saved against live orders.
            $clean[$value] ??= ['label' => $label !== '' ? $label : $value, 'value' => $value];
        }

        return array_values($clean);
    }

    /** Does this mapping's type want options that have not been given? */
    public function needsOptions(): bool
    {
        return Transform::needsOptions($this->transform) && $this->options === [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'target' => $this->target,
            'transform' => $this->transform,
            'direction' => $this->direction,
            'label' => $this->label,
            'enabled' => $this->enabled,
            'also' => $this->also,
            'options' => $this->options,
            'visible' => $this->visible,
        ];
    }

    /** Built from several of their fields, and so readable only one way. */
    public function isComposite(): bool
    {
        return $this->also !== [];
    }

    /** Every path this rule reads, in order. @return list<string> */
    public function sources(): array
    {
        return [$this->source, ...$this->also];
    }

    /**
     * Is this rule safe and complete enough to run?
     *
     * Checked at apply time and not only at save time, because a mapping saved
     * by an older version of this code — or edited in the database — must not be
     * trusted on the strength of having once passed a form validator.
     */
    public function isUsable(): bool
    {
        return $this->enabled
            && $this->source !== ''
            && $this->target !== ''
            && EntityFields::isEntity($this->entity)
            && EntityFields::isWritable($this->entity, $this->target);
    }

    public function isCustom(): bool
    {
        return EntityFields::isCustom($this->target);
    }

    /** The key a custom value lands under, or '' for an ordinary column. */
    public function customKey(): string
    {
        return $this->isCustom() ? EntityFields::customKey($this->target) : '';
    }

    public function readsFromPlatform(): bool
    {
        return $this->direction === self::IN || $this->direction === self::BOTH;
    }

    /**
     * Never for a composite rule, whatever its direction says.
     *
     * Joining two of their fields into one of ours is not reversible: given
     * "Ada Byron King" there is no honest way to decide which words were the
     * first name. Pushing it would write the whole string into `first_name` and
     * quietly empty `last_name` on their side.
     */
    public function writesToPlatform(): bool
    {
        return ! $this->isComposite()
            && ($this->direction === self::OUT || $this->direction === self::BOTH);
    }

    /** What to call this field on screen, in the owner's words where they gave any. */
    public function displayLabel(): string
    {
        return $this->label ?? EntityFields::label($this->entity, $this->target);
    }
}
