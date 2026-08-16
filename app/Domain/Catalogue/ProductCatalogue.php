<?php

declare(strict_types=1);

namespace App\Domain\Catalogue;

use App\Domain\Catalogue\Models\Product;
use App\Domain\Catalogue\Models\ProductOption;
use App\Domain\Catalogue\Models\ProductOptionValue;
use App\Domain\Catalogue\Models\ProductVariant;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Creating products, and keeping the variant invariant true.
 *
 * ── The one rule this exists to hold ─────────────────────────────────────────
 *
 * A product always has at least one variant. Everything downstream is written
 * on that assumption — the till, stock, order lines, price lists — so a product
 * that briefly has none is a product that breaks whatever touches it first.
 * Creating a product therefore creates its variant in the same transaction, and
 * there is no way to remove the last one.
 *
 * ── Why generating the grid is a method and not a loop at the call site ──────
 *
 * Three sizes and four colours is twelve variants, each needing a SKU, a name
 * built from its option values, and a link to each value it is. Written by hand
 * at each call site it is a nested loop somebody gets subtly wrong — usually by
 * regenerating everything on edit and destroying the SKUs, prices and stock of
 * the combinations that already existed. This adds what is missing and leaves
 * what is there alone.
 */
final class ProductCatalogue
{
    public function __construct(
        private readonly TenantContext $tenant,
    ) {}

    /**
     * Create a product and its first variant together.
     *
     * @param  array<string, mixed>  $attributes  product fields
     * @param  array<string, mixed>  $variant     overrides for the first variant
     */
    public function create(array $attributes, array $variant = []): Product
    {
        $business = $this->tenant->business();

        if ($business === null) {
            throw new RuntimeException('No set of books is open, so there is nowhere to put a product.');
        }

        $currency = $variant['currency'] ?? $business->base_currency;

        return DB::transaction(function () use ($attributes, $variant, $currency) {
            $product = Product::create([
                ...$attributes,
                'slug' => $attributes['slug'] ?? $this->uniqueSlug($attributes['name']),
            ]);

            $this->makeVariant($product, [
                'sku' => $variant['sku'] ?? $this->nextSku($product),
                'currency' => $currency,
                'is_default' => true,
                'position' => 1,
                ...$variant,
            ]);

            return $product->load('variants');
        });
    }

    /**
     * Add an axis of variation, with its values.
     *
     * @param  list<string>  $values
     */
    public function addOption(Product $product, string $name, array $values): ProductOption
    {
        return DB::transaction(function () use ($product, $name, $values) {
            $option = ProductOption::create([
                'product_id' => $product->id,
                'name' => $name,
                'position' => (int) $product->options()->max('position') + 1,
            ]);

            foreach (array_values($values) as $index => $value) {
                ProductOptionValue::create([
                    'product_option_id' => $option->id,
                    'value' => $value,
                    'position' => $index + 1,
                ]);
            }

            return $option->load('values');
        });
    }

    /**
     * Build every combination of the product's options that does not exist yet.
     *
     * Additive on purpose. Adding "XL" to a product that already sells S, M and
     * L should create four new variants and leave the existing twelve exactly
     * as they are — with their SKUs, their prices, and whatever stock is
     * sitting against them. A regenerate-everything implementation is the
     * commonest way a catalogue loses its history.
     *
     * @return list<ProductVariant> only the ones created
     */
    public function generateVariants(Product $product, array $defaults = []): array
    {
        $product->loadMissing('options.values', 'variants.optionValues');

        if ($product->options->isEmpty()) {
            return [];
        }

        $axes = $product->options
            ->map(fn (ProductOption $o) => $o->values->all())
            ->filter(fn (array $values) => $values !== [])
            ->values()
            ->all();

        if ($axes === []) {
            return [];
        }

        // What already exists, as a sorted set of value ids per variant, so a
        // combination is recognised however its values happen to be ordered.
        $existing = $product->variants
            ->map(fn (ProductVariant $v) => $this->signature($v->optionValues->pluck('id')->all()))
            ->all();

        $base = $product->variants->first();
        $created = [];
        $position = (int) $product->variants->max('position');

        foreach ($this->combinations($axes) as $combination) {
            $ids = array_map(fn (ProductOptionValue $v) => $v->id, $combination);

            if (in_array($this->signature($ids), $existing, true)) {
                continue;
            }

            $label = implode(' / ', array_map(fn (ProductOptionValue $v) => $v->value, $combination));

            $variant = $this->makeVariant($product, [
                'sku' => $defaults['sku'] ?? $this->nextSku($product, $label),
                'name' => $label,
                'currency' => $defaults['currency'] ?? $base?->currency ?? $this->tenant->business()->base_currency,
                // A new size costs and sells for what its siblings do until
                // somebody says otherwise. Zero would be a price somebody
                // eventually sells at.
                'price_minor' => $defaults['price_minor'] ?? $base?->price_minor ?? 0,
                'cost_minor' => $defaults['cost_minor'] ?? $base?->cost_minor ?? 0,
                'position' => ++$position,
            ]);

            $variant->optionValues()->attach($ids);
            $created[] = $variant;
        }

        // The single option-less variant a product starts life with is not one
        // of the combinations, and leaving it beside them means a shirt that is
        // neither small nor large sitting in the list forever.
        $this->retireBlankVariant($product);

        return $created;
    }

    /**
     * Remove a variant, unless it is the last one.
     *
     * @throws RuntimeException if it is the only variant left
     */
    public function removeVariant(ProductVariant $variant): void
    {
        $siblings = ProductVariant::where('product_id', $variant->product_id)->count();

        if ($siblings <= 1) {
            throw new RuntimeException(
                'A product must keep at least one variant. Archive the whole product instead.'
            );
        }

        DB::transaction(function () use ($variant) {
            $variant->delete();

            // The default going means nothing is default, and every screen that
            // opens on "the" variant then opens on nothing.
            if ($variant->is_default) {
                ProductVariant::where('product_id', $variant->product_id)
                    ->orderBy('position')
                    ->first()
                    ?->forceFill(['is_default' => true])
                    ->save();
            }
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeVariant(Product $product, array $attributes): ProductVariant
    {
        return ProductVariant::create([
            'product_id' => $product->id,
            'price_minor' => 0,
            'cost_minor' => 0,
            ...$attributes,
        ]);
    }

    /**
     * Drop the placeholder variant once real combinations exist.
     *
     * Only when it has no option values of its own, is not the only one left,
     * and nothing has happened to it — a variant somebody has already priced or
     * sold is theirs, not ours to tidy away.
     */
    private function retireBlankVariant(Product $product): void
    {
        $blank = $product->variants()
            ->whereDoesntHave('optionValues')
            ->orderBy('position')
            ->first();

        if ($blank === null || $product->variants()->count() <= 1) {
            return;
        }

        $wasDefault = $blank->is_default;
        $blank->delete();

        if ($wasDefault) {
            $product->variants()->orderBy('position')->first()?->forceFill(['is_default' => true])->save();
        }
    }

    /**
     * @param  list<list<ProductOptionValue>>  $axes
     * @return list<list<ProductOptionValue>>
     */
    private function combinations(array $axes): array
    {
        $out = [[]];

        foreach ($axes as $values) {
            $next = [];

            foreach ($out as $prefix) {
                foreach ($values as $value) {
                    $next[] = [...$prefix, $value];
                }
            }

            $out = $next;
        }

        return $out;
    }

    /** @param list<int> $ids */
    private function signature(array $ids): string
    {
        sort($ids);

        return implode('-', $ids);
    }

    /**
     * A SKU nobody else in this book is using.
     *
     * Derived from the name so it means something to whoever reads a picking
     * list, and suffixed until it is free rather than failing on the unique
     * index — two products called "Blue Shirt" is not an error.
     */
    private function nextSku(Product $product, ?string $suffix = null): string
    {
        $stem = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $product->name) ?? 'SKU', 0, 8));
        $stem = $stem === '' ? 'SKU' : $stem;

        if ($suffix !== null) {
            $stem .= '-'.strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $suffix) ?? '', 0, 6));
        }

        $candidate = $stem;
        $n = 1;

        while (ProductVariant::withTrashed()->where('business_id', $product->business_id)->where('sku', $candidate)->exists()) {
            $candidate = $stem.'-'.(++$n);
        }

        return $candidate;
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'product';
        $slug = $base;
        $n = 1;

        while (Product::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$n);
        }

        return $slug;
    }
}
