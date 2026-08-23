<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Observers;

use App\Domain\Catalogue\Models\ProductVariant;
use App\Domain\Integrations\PushDispatcher;
use App\Domain\Integrations\Support\SyncMute;

/**
 * A price, SKU or barcode changed — which is a product change to a shop.
 *
 * ── Why watching products alone is not enough ────────────────────────────────
 *
 * What we send for a product includes its default variant: price, SKU, barcode,
 * weight. Changing a price writes a variant row and leaves the product row
 * untouched, so an observer on products alone would miss the single commonest
 * catalogue edit there is — and miss it silently, which is the worst way.
 */
final class ProductVariantObserver
{
    public function saved(ProductVariant $variant): void
    {
        if (SyncMute::active()) {
            return;
        }

        if (! $variant->wasRecentlyCreated && ! $variant->wasChanged()) {
            return;
        }

        $product = $variant->product;

        if ($product === null) {
            return;
        }

        // Pushed as the product, because that is the thing a shop holds. A
        // variant is not separately addressable in most platforms' APIs.
        app(PushDispatcher::class)->product($product);
    }
}
