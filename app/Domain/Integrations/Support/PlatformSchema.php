<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Support;

/**
 * What a shop says about its own fields, before it has sent a single record.
 *
 * ── The problem this solves ──────────────────────────────────────────────────
 *
 * Field mapping used to be taught entirely by example: keep the last order the
 * shop sent, list the paths found in it, and offer those. That works, and it
 * has three failures that are invisible until somebody hits them.
 *
 * A field that is *empty on that one order* cannot be mapped at all. An order
 * with no coupon makes `coupon_lines` an empty array, so nothing beneath it —
 * the code, the discount, the description — exists to be pointed at, and the
 * shop looks as though it has no coupons rather than none on that order. On the
 * catalogue this is worse: one simple product makes `attributes` and
 * `variations` empty, and every variable product's Size and Colour become
 * invisible.
 *
 * A field that is *writable* is indistinguishable from one that is not. This
 * cost real money once already: WooCommerce accepts a write to a product's
 * `price`, answers 200, and silently discards it, because price is computed
 * from `regular_price` and `sale_price`. Nothing in a record says so. The
 * schema says so plainly.
 *
 * A field with a *fixed set of values* looks like free text. An order's status
 * is one of eleven words and a product's type is one of four, and reading one
 * record shows exactly one of them.
 *
 * ── What it does not solve ───────────────────────────────────────────────────
 *
 * Custom meta. WooCommerce describes `meta_data` as a list of `{id, key,
 * value}` and stops — the keys a shop's plugins actually use are not in the
 * schema and never will be, because no two shops have the same ones. Those
 * still come from records, which is the right way round: the standard fields
 * are known in advance and the invented ones are discovered.
 *
 * So the two sources are complementary, not competing, and the mapping screen
 * offers the union.
 */
final class PlatformSchema
{
    /**
     * One field, as the shop describes it.
     *
     * @param  list<string>  $options  A fixed set of values, if it has one.
     * @param  array<string, self>  $children  Sub-fields, for a field holding records.
     */
    public function __construct(
        public string $path,
        public string $label,
        public string $type,
        public bool $readonly = false,
        public array $options = [],
        public array $children = [],
        public ?string $description = null,
    ) {}

    /**
     * Read a JSON Schema `properties` block into field descriptions.
     *
     * The shape is the one WordPress, and every other REST API that answers
     * OPTIONS, returns: a map of name to `{type, description, readonly, enum,
     * properties, items}`.
     *
     * @param  array<string, mixed>  $properties
     * @return array<string, self>
     */
    public static function fromJsonSchema(array $properties, string $prefix = '', int $depth = 0): array
    {
        $fields = [];

        foreach ($properties as $name => $spec) {
            if (! is_array($spec) || ! is_string($name)) {
                continue;
            }

            $path = $prefix === '' ? $name : $prefix.'.'.$name;

            /*
             * Nested records are described one of two ways: an object states
             * its `properties`, a list of records states them under `items`.
             * Read both, at a depth that stops well short of the recursion a
             * self-referential schema would otherwise cause.
             */
            $children = [];

            if ($depth < 3) {
                $nested = $spec['properties'] ?? null;

                /*
                 * A list's sub-fields are numbered, because that is how a real
                 * record reads.
                 *
                 * The schema describes a coupon's code at `coupon_lines.code`,
                 * while flattening an actual order produces
                 * `coupon_lines.0.code`. Left alone, the two never meet: every
                 * described sub-field would appear a second time as an unused
                 * duplicate of one already listed, and a mapping saved against
                 * the schema's spelling would read nothing at sync time.
                 */
                $childPrefix = $path;

                if ($nested === null && isset($spec['items']['properties']) && is_array($spec['items']['properties'])) {
                    $nested = $spec['items']['properties'];
                    $childPrefix = $path.'.0';
                }

                if (is_array($nested)) {
                    $children = self::fromJsonSchema($nested, $childPrefix, $depth + 1);
                }
            }

            $fields[$path] = new self(
                path: $path,
                label: self::labelFor($name),
                type: self::typeFor($spec),
                readonly: (bool) ($spec['readonly'] ?? false),
                options: self::optionsFor($spec),
                children: $children,
                description: is_string($spec['description'] ?? null) && $spec['description'] !== ''
                    ? $spec['description']
                    : null,
            );
        }

        return $fields;
    }

    /**
     * Flatten a tree of descriptions to a lookup by path.
     *
     * @param  array<string, self>  $fields
     * @return array<string, self>
     */
    public static function flatten(array $fields): array
    {
        $flat = [];

        foreach ($fields as $field) {
            $flat[$field->path] = $field;

            if ($field->children !== []) {
                $flat += self::flatten($field->children);
            }
        }

        return $flat;
    }

    /**
     * The paths worth offering on a mapping screen.
     *
     * ── What is dropped, and why ─────────────────────────────────────────────
     *
     * A field that holds other fields is not a mapping target. Pointing at
     * `billing` rather than `billing.city` stores an address as a blob of JSON
     * in a text column, and the screen has no way to say that is a mistake, so
     * it is not offered.
     *
     * Custom-field containers go too, and this one matters more. WooCommerce
     * describes `meta_data` as a list of `{id, key, value}` — a description of
     * the *shape*, not of any actual field. Offered as-is it would put three
     * meaningless rows on the screen, right where somebody is looking for the
     * meta keys their own shop uses, which come from records and are listed
     * separately. Better to show nothing than three decoys.
     *
     * @param  array<string, self>  $flat
     * @return array<string, self>
     */
    public static function mappable(array $flat): array
    {
        $containers = [];

        foreach ($flat as $field) {
            if ($field->children !== []) {
                $containers[$field->path] = true;
            }
        }

        return array_filter(
            $flat,
            fn (self $field): bool => ! isset($containers[$field->path])
                && ! self::isCustomFieldShape($field->path),
        );
    }

    /**
     * The generic {id, key, value} descriptor every platform gives its meta.
     *
     * Matched on whole segments and at any depth, because these containers nest:
     * an order has `meta_data`, and so does each of its line items, which is how
     * `line_items.0.meta_data.0.display_key` reaches a screen looking exactly as
     * unhelpful as the three at the top level.
     */
    private static function isCustomFieldShape(string $path): bool
    {
        $containers = ['meta_data', 'metafields', 'note_attributes', 'custom_fields'];
        $segments = explode('.', $path);

        // The last segment is excluded: the container itself is dropped as a
        // container, and this is about what sits underneath one.
        foreach (array_slice($segments, 0, -1) as $segment) {
            if (in_array($segment, $containers, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The transform this application would use for a field described this way.
     *
     * A first choice, not a ruling — whatever the mapping already says wins,
     * because somebody who changed it had a reason and a schema refreshed next
     * week must not quietly undo them.
     */
    public function transform(): string
    {
        // A fixed set of values is a dropdown, and the schema brought the
        // values with it — the one case where choices need not be typed.
        if ($this->options !== []) {
            return 'select';
        }

        return match ($this->type) {
            'integer' => 'integer',
            'number' => 'decimal',
            'boolean' => 'boolean',
            'date-time' => 'datetime',
            'date' => 'date',
            'uri' => 'url',
            'email' => 'email',
            'object', 'array' => 'json',
            default => 'trim',
        };
    }

    /** @return list<array{label: string, value: string}> */
    public function choices(): array
    {
        return array_map(
            fn (string $value): array => ['label' => self::labelFor($value), 'value' => $value],
            $this->options,
        );
    }

    // ── Reading one field's description ─────────────────────────────────────

    /** @param array<string, mixed> $spec */
    private static function typeFor(array $spec): string
    {
        /*
         * `format` first, because it is the more specific of the two: a
         * timestamp is described as a string of format date-time, and taking
         * the type alone would render it as a text box.
         */
        $format = $spec['format'] ?? null;

        if (is_string($format) && $format !== '') {
            return $format;
        }

        $type = $spec['type'] ?? 'string';

        // A nullable field is described as ['string', 'null']. The null carries
        // no information about what to show, so the first entry is taken.
        if (is_array($type)) {
            $type = array_values(array_filter($type, fn ($t): bool => $t !== 'null'))[0] ?? 'string';
        }

        return is_string($type) ? $type : 'string';
    }

    /**
     * A fixed set of values, if the schema states one.
     *
     * @param  array<string, mixed>  $spec
     * @return list<string>
     */
    private static function optionsFor(array $spec): array
    {
        $enum = $spec['enum'] ?? $spec['items']['enum'] ?? null;

        if (! is_array($enum) || $enum === []) {
            return [];
        }

        $values = array_values(array_filter(
            array_map(fn ($v): string => is_scalar($v) ? (string) $v : '', $enum),
            fn (string $v): bool => $v !== '',
        ));

        /*
         * A very long list is not a dropdown anybody wants to scroll.
         *
         * WooCommerce describes `currency` with all 163 ISO codes, and offering
         * those as choices to be reviewed one at a time on a mapping screen is
         * worse than a text box. The cut is generous enough that every genuine
         * status, type and visibility list — the ones that make this feature
         * worth having — passes it comfortably.
         */
        return count($values) > 60 ? [] : $values;
    }

    /**
     * A field name as a person would write it.
     *
     * `date_created_gmt` is a path; "Date Created Gmt" is a label. Not perfect
     * — no rule turns GMT into the capitals it deserves — but it is the
     * difference between a screen of identifiers and a screen of English, and
     * anything it gets wrong can be typed over.
     */
    private static function labelFor(string $name): string
    {
        $words = trim((string) preg_replace('/[_\-.]+/', ' ', $name));

        return $words === '' ? $name : mb_convert_case($words, MB_CASE_TITLE, 'UTF-8');
    }
}
