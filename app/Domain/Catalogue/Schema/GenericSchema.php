<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Schema;

use App\Domain\Catalogue\Models\Product;

/**
 * The driver for a storefront nobody has written a driver for.
 *
 * ── Why this is the important one ────────────────────────────────────────────
 *
 * Named platforms are the easy case and the smaller one. The subscriber this
 * has to serve is the one whose shop is a Laravel API, a Next.js backend, or
 * something a cousin built — and for them there is no documentation to encode.
 *
 * So it learns. Given one real product read back from their endpoint, it infers
 * a field for every key, guesses each type from the value, and recognises the
 * handful of names that mean the same thing everywhere — title/name,
 * price/amount, sku/code — so those map to Prism's own fields instead of being
 * asked for twice.
 *
 * Everything it infers is a guess presented as one. The fields are editable and
 * sampleVia() reports where they came from, because a mapping the subscriber
 * confirmed and a mapping we invented behave identically right up until a sync
 * fails.
 */
final class GenericSchema implements SchemaDriver
{
    private ?string $sampleVia = null;

    public static function handles(): array
    {
        return ['generic', 'custom', 'api'];
    }

    public function label(): string
    {
        return 'Custom or self-hosted store';
    }

    /**
     * Keys that mean the same thing on nearly every platform.
     *
     * @var array<string, string>
     */
    private const KNOWN = [
        'title' => 'name', 'name' => 'name', 'product_name' => 'name',
        'description' => 'description', 'body_html' => 'description', 'content' => 'description',
        'sku' => 'sku', 'code' => 'sku', 'item_code' => 'sku',
        'price' => 'price', 'unit_price' => 'price', 'regular_price' => 'price', 'amount' => 'price',
        'barcode' => 'barcode', 'ean' => 'barcode', 'upc' => 'barcode', 'gtin' => 'barcode',
        'weight' => 'weight', 'stock' => 'stock', 'quantity' => 'stock', 'inventory_quantity' => 'stock',
        'brand' => 'brand', 'vendor' => 'brand', 'manufacturer' => 'brand',
        'category' => 'category', 'product_type' => 'category', 'collection' => 'category',
    ];

    public function discover(array $connection): array
    {
        $sample = $connection['sample'] ?? null;

        if (! is_array($sample) || $sample === []) {
            // Nothing to learn from. Offer the fields every shop has rather
            // than an empty form somebody cannot get past.
            $this->sampleVia = null;

            return $this->fallbackFields();
        }

        $this->sampleVia = $connection['sample_via'] ?? 'api';

        $fields = [];

        foreach ($this->flatten($sample) as $key => $value) {
            // Arrays of arrays are variants or images, not fields. Handled by
            // discoverVariantFields and the media mapping respectively.
            if (is_array($value) && $value !== [] && is_array(reset($value))) {
                continue;
            }

            $fields[] = new FieldDefinition(
                key: $key,
                label: $this->labelFor($key),
                type: $this->typeFor($value),
                mapsTo: $this->mapping($key),
                hint: $this->sampleVia === null ? null : 'Read from your store',
                group: str_contains($key, '.') ? explode('.', $key)[0] : null,
            );
        }

        return $fields;
    }

    public function discoverVariantFields(array $connection): array
    {
        $sample = $connection['sample'] ?? [];

        // Whichever key holds a list of objects is the variants. Guessing by
        // shape rather than by name, because the name is exactly the thing that
        // differs between platforms.
        foreach (['variants', 'variations', 'skus', 'items', 'options'] as $candidate) {
            $rows = $sample[$candidate] ?? null;

            if (is_array($rows) && $rows !== [] && is_array(reset($rows))) {
                $first = reset($rows);

                return array_map(
                    fn (string $key, mixed $value) => new FieldDefinition(
                        key: $key,
                        label: $this->labelFor($key),
                        type: $this->typeFor($value),
                        mapsTo: self::KNOWN[strtolower($key)] ?? null,
                        group: 'variant',
                    ),
                    array_keys($first),
                    array_values($first),
                );
            }
        }

        return [];
    }

    public function sampleEndpoint(array $connection): ?string
    {
        $base = rtrim((string) ($connection['base_url'] ?? ''), '/');

        return $base === '' ? null : $base.'/'.ltrim((string) ($connection['products_path'] ?? 'products'), '/');
    }

    public function sampleVia(): ?string
    {
        return $this->sampleVia;
    }

    public function buildPayload(Product $product, array $variants, array $values): array
    {
        // Whatever the subscriber mapped, plus the Prism values for the fields
        // they mapped to ours. Their explicit values win: a mapping is a
        // default, not a rule.
        $payload = $values;

        foreach ($values as $key => $value) {
            if ($value !== null && $value !== '') {
                continue;
            }

            $payload[$key] = match (self::KNOWN[strtolower($key)] ?? null) {
                'name' => $product->name,
                'description' => $product->description,
                'brand' => $product->brand,
                'category' => $product->category?->name,
                default => $value,
            };
        }

        if ($variants !== []) {
            $payload['variants'] = array_map(fn ($v) => [
                'sku' => $v->sku,
                'barcode' => $v->barcode,
                'title' => $v->name,
                // Major units as a string: the platforms overwhelmingly expect
                // "12.50", and going through the string keeps it exact rather
                // than dividing by 100 into a float.
                'price' => $v->price()->toDecimalString(),
            ], $variants);
        }

        return $payload;
    }

    public function parsePayload(array $remote): array
    {
        $product = [];

        foreach ($this->flatten($remote) as $key => $value) {
            $target = $this->mapping($key);

            if ($target !== null && ! isset($product[$target]) && ! is_array($value)) {
                $product[$target] = $value;
            }
        }

        $variants = [];

        foreach (['variants', 'variations', 'skus', 'items'] as $candidate) {
            $rows = $remote[$candidate] ?? null;

            if (! is_array($rows) || $rows === [] || ! is_array(reset($rows))) {
                continue;
            }

            foreach ($rows as $row) {
                $one = [];

                foreach ($row as $key => $value) {
                    $target = self::KNOWN[strtolower((string) $key)] ?? null;

                    if ($target !== null && ! is_array($value)) {
                        $one[$target] = $value;
                    }
                }

                $variants[] = $one;
            }

            break;
        }

        // A platform with no variant concept still yields one item here, so the
        // importer has the same shape to work with either way.
        if ($variants === []) {
            $variants[] = array_intersect_key($product, array_flip(['sku', 'price', 'barcode', 'stock', 'weight']));
        }

        return ['product' => $product, 'variants' => $variants];
    }

    public function writeEndpoint(array $connection, ?string $externalId = null): ?string
    {
        $base = $this->sampleEndpoint($connection);

        if ($base === null) {
            return null;
        }

        return $externalId === null ? $base : $base.'/'.$externalId;
    }

    /** @return list<FieldDefinition> */
    private function fallbackFields(): array
    {
        return [
            new FieldDefinition('title', 'Title', 'text', true, mapsTo: 'name'),
            new FieldDefinition('description', 'Description', 'textarea', mapsTo: 'description'),
            new FieldDefinition('sku', 'SKU', 'text', mapsTo: 'sku'),
            new FieldDefinition('price', 'Price', 'money', true, mapsTo: 'price'),
            new FieldDefinition('barcode', 'Barcode', 'text', mapsTo: 'barcode'),
            new FieldDefinition('stock', 'Stock', 'number', mapsTo: 'stock'),
        ];
    }

    /**
     * Nested objects become dotted keys, so `seo.title` is one field.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function flatten(array $data, string $prefix = ''): array
    {
        $out = [];

        foreach ($data as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value) && $value !== [] && ! array_is_list($value)) {
                $out += $this->flatten($value, $path);

                continue;
            }

            $out[$path] = $value;
        }

        return $out;
    }

    /**
     * The Prism field a key maps to, if any.
     *
     * Top-level keys only. `seo.title` is not the product's name and
     * `shipping.weight` is not its weight — matching on the last segment made
     * both of those claim fields they have no business claiming, which is worse
     * than no mapping at all: an unmapped field is asked for once, while a
     * wrongly mapped one silently publishes the SEO blurb as the product title.
     */
    private function mapping(string $key): ?string
    {
        return str_contains($key, '.')
            ? null
            : (self::KNOWN[strtolower($key)] ?? null);
    }

    private function labelFor(string $key): string
    {
        return ucfirst(str_replace(['_', '.', '-'], ' ', $key));
    }

    private function typeFor(mixed $value): string
    {
        return match (true) {
            is_bool($value) => 'boolean',
            is_int($value) || is_float($value) => 'number',
            is_array($value) => 'multiselect',
            is_string($value) && strlen($value) > 120 => 'textarea',
            is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}/', $value) === 1 => 'date',
            default => 'text',
        };
    }
}
