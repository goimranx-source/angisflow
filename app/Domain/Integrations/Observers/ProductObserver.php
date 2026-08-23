<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Observers;

use App\Domain\Catalogue\Models\Product;
use App\Domain\Integrations\PushDispatcher;
use App\Domain\Integrations\Support\SyncMute;

/**
 * A product changed here, so the shops selling it need telling.
 *
 * ── Why an observer rather than a call from each screen ──────────────────────
 *
 * Because a product is edited from more places than anyone remembers: the
 * catalogue screen, a bulk price change, an importer, a console command, and
 * whatever gets written next. A push wired into each of those is a push missing
 * from the one after it.
 *
 * Orders were done the other way — by hand, at each call site — and the order
 * editor duly forgot it, so an edited quantity saved here and never reached the
 * shop. An observer cannot be forgotten.
 */
final class ProductObserver
{
    public function saved(Product $product): void
    {
        if (SyncMute::active()) {
            // A sync is importing. Pushing now would answer the shop with its
            // own data — see SyncMute.
            return;
        }

        /*
         * Only when something a shop would notice actually changed.
         *
         * A save that touched nothing, or touched only a counter, is not worth
         * a round trip to every connected shop — and during a bulk operation it
         * is the difference between one push and ten thousand.
         */
        if (! $product->wasRecentlyCreated && ! $product->wasChanged()) {
            return;
        }

        app(PushDispatcher::class)->product($product);
    }

    public function deleted(Product $product): void
    {
        /*
         * Deliberately silent.
         *
         * Deleting a product here should not delete it from somebody's shop —
         * that is a destructive act on a system we do not own, triggered by a
         * tidy-up in ours. Removing a listing is its own decision and belongs
         * on its own control.
         */
    }
}
