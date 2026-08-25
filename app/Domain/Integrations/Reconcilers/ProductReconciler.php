<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Reconcilers;

use App\Domain\Catalogue\Models\Product;
use App\Domain\Catalogue\Models\ProductVariant;
use App\Domain\Integrations\Models\Integration;
use App\Domain\Integrations\Support\FieldPath;
use App\Domain\Integrations\Support\MappedRecord;
use App\Domain\Integrations\Support\Transform;
use Illuminate\Support\Str;

/**
 * A shop's product, becoming ours.
 *
 * ── Why a variant always comes with it ───────────────────────────────────────
 *
 * Here a product is a thing you sell and a variant is the thing that has a SKU,
 * a price and a stock level. Most shops have no concept of the distinction —
 * a WooCommerce simple product is one row with one SKU — so importing only the
 * product half would create a catalogue entry that cannot be sold, cannot be
 * priced, and cannot be put on an order line.
 *
 * So every imported product gets at least a default variant, and that variant
 * is what carries the SKU the rest of the sync matches on.
 */
class ProductReconciler
{

    /**
     * The address of the picture a shop serves for this record.
     *
     * ── Why it is read from the payload and not mapped ───────────────────────
     *
     * Every other field on this screen is mapped, on purpose: a shop names
     * things its own way and only somebody looking at it knows which of its
     * fields is the weight. An image is not like that. Every platform worth
     * syncing puts it in the same two or three places under the same two or
     * three names, and asking a person to map "images.0.src" is asking them to
     * do a job that has one right answer.
     *
     * WooCommerce sends `images` on a product and `image` on a variation;
     * Shopify sends `image` with `src`; several send a bare string. All of them
     * are tried, and anything that is not an http address is ignored — a
     * payload carrying a local file path or a placeholder id would otherwise
     * become a broken picture on every order line.
     *
     * @param  array<string, mixed>  $payload
     */
    private function pictureFrom(array $payload): ?string
    {
        $candidates = [
            $payload['images'][0]['src'] ?? null,
            $payload['image']['src'] ?? null,
            $payload['image'] ?? null,
            $payload['images'][0] ?? null,
            $payload['featured_image'] ?? null,
            $payload['thumbnail'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }

            $url = trim($candidate);

            // Only something a browser can actually fetch. Left looser, a feed
            // sending "0" or "no-image" would be stored and rendered as a
            // broken image on every line it appears on.
            if ($url !== '' && str_starts_with($url, 'http')) {
                return mb_substr($url, 0, 1024);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function apply(Integration $integration, MappedRecord $record, array $payload, ?int $localId): ?Product
    {
        /*
         * The product's own fields, and the variant's.
         *
         * `variant.price_minor` is a real mapping target but not a product
         * column: left in, fill() throws on mass assignment, and dropped
         * silently the mapping would look configured while doing nothing.
         */
        $attributes = $this->part($record->attributes, false);
        $variantFields = $this->part($record->attributes, true);

        $name = trim((string) ($attributes['name'] ?? ''));

        if ($localId !== null) {
            $product = Product::query()->find($localId);

            if ($product !== null) {
                $product->fill($attributes)->save();

                /*
                 * Filled in, and refreshed when the shop changes it.
                 *
                 * Written outside fill() because it is not a mapped attribute —
                 * see pictureFrom. forceFill because nothing should have to add
                 * it to $fillable for a picture to appear.
                 */
                $picture = $this->pictureFrom($payload);

                if ($picture !== null && $picture !== $product->image_url) {
                    $product->forceFill(['image_url' => $picture])->save();
                }

                $this->syncDefaultVariant($integration, $product, $payload, $variantFields);

                return $product;
            }
        }

        if ($name === '') {
            // Nothing to call it. Creating "Untitled" rows from a malformed
            // feed pollutes a catalogue somebody has to clean by hand.
            return null;
        }

        $product = Product::query()->create([
            'account_id' => $integration->account_id,
            'business_id' => $integration->business_id,
            'name' => $name,
            'slug' => $this->uniqueSlug($integration, $name),
            'kind' => 'good',
            'is_stocked' => $attributes['is_stocked'] ?? true,
            'is_active' => true,
            ...$attributes,
        ]);

        // The same picture as on the update path, on the pass that creates it —
        // otherwise a product looks right only after its second sync.
        $picture = $this->pictureFrom($payload);

        if ($picture !== null) {
            $product->forceFill(['image_url' => $picture])->save();
        }

        $this->syncDefaultVariant($integration, $product, $payload, $variantFields);

        return $product;
    }

    /**
     * Make sure something sellable exists behind this product.
     *
     * The SKU and price are read from the payload rather than the mapped record
     * because they belong to the variant, not the product, and so are not among
     * the product's mappable fields.
     *
     * @param  array<string, mixed>  $payload
     */
    private function syncDefaultVariant(Integration $integration, Product $product, array $payload, array $mapped = []): void
    {
        // A mapped SKU is an instruction; the payload guess below is a
        // convention. The instruction wins.
        $sku = trim((string) ($mapped['sku'] ?? FieldPath::resolve($payload, 'sku', '')));
        $currency = (string) ($product->business?->base_currency ?? 'USD');
        $price = isset($mapped['price_minor'])
            ? (int) $mapped['price_minor']
            : $this->priceMinor($payload, $currency);

        $variant = ProductVariant::query()
            ->where('product_id', $product->id)
            ->orderByDesc('is_default')
            ->first();

        if ($variant !== null) {
            $changes = [];

            // A SKU is only filled in, never overwritten: it is what this
            // application matches on, and a shop renaming one would otherwise
            // silently detach the product from its own stock and order history.
            if ($sku !== '' && trim((string) $variant->sku) === '') {
                $changes['sku'] = $sku;
            }

            if ($price !== null) {
                $changes['price_minor'] = $price;
            }

            // Everything else the mapping named — barcode, cost, weight, unit.
            $changes += array_diff_key($mapped, ['sku' => true]);

            if ($changes !== []) {
                $variant->forceFill($changes)->save();
            }

            return;
        }

        ProductVariant::query()->create([
            'account_id' => $integration->account_id,
            'business_id' => $integration->business_id,
            'product_id' => $product->id,
            'sku' => $sku !== '' ? $sku : $this->fallbackSku($product),
            'name' => $product->name,
            'price_minor' => $price ?? 0,
            'currency' => $currency,
            'is_default' => true,
            'is_active' => true,
            'position' => 0,
            ...$mapped,
        ]);
    }

    /**
     * Split mapped attributes into the product's and the variant's.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function part(array $attributes, bool $variant): array
    {
        $out = [];

        foreach ($attributes as $key => $value) {
            $isVariant = str_starts_with($key, 'variant.');

            if ($isVariant === $variant) {
                $out[$isVariant ? mb_substr($key, 8) : $key] = $value;
            }
        }

        return $out;
    }

    /**
     * A price, in minor units, from wherever this platform keeps it.
     *
     * @param  array<string, mixed>  $payload
     */
    private function priceMinor(array $payload, string $currency): ?int
    {
        foreach (['price', 'regular_price', 'variants.0.price', 'fieldData.price.value'] as $path) {
            if (! FieldPath::has($payload, $path)) {
                continue;
            }

            $raw = FieldPath::resolve($payload, $path);

            if ($raw === null || $raw === '') {
                continue;
            }

            // Webflow reports minor units already; everyone else reports a
            // decimal string.
            if (is_int($raw) && str_contains($path, '.value')) {
                return $raw;
            }

            $minor = Transform::apply($raw, 'money_minor', ['currency' => $currency]);

            if ($minor !== null) {
                return (int) $minor;
            }
        }

        return null;
    }

    /**
     * A SKU for a shop that keeps none.
     *
     * Plenty of small shops never fill one in. Left blank, every such product
     * matches every other blank-SKU product on the next sync — so one is
     * minted, marked as ours, and stable for that product.
     */
    private function fallbackSku(Product $product): string
    {
        return 'IMP-'.strtoupper(Str::random(4)).'-'.$product->id;
    }

    private function uniqueSlug(Integration $integration, string $name): string
    {
        $base = Str::slug($name) ?: 'product';
        $slug = $base;
        $suffix = 2;

        while (Product::query()
            ->where('business_id', $integration->business_id)
            ->where('slug', $slug)
            ->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
