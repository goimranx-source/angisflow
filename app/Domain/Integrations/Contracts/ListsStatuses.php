<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Contracts;

use App\Domain\Integrations\Models\Integration;

/**
 * A platform that can be asked what its own statuses are.
 *
 * ── Why asking beats knowing ─────────────────────────────────────────────────
 *
 * A status mapping screen is only as good as the list it offers on the shop's
 * side, and there were two ways to build that list before this: the words this
 * connection had already been seen to send, and a list of the platform's
 * documented statuses written into this application.
 *
 * Both miss the same thing, and it is the thing that matters. Observation only
 * knows a status once an order has been in it — so a shop's rarest and most
 * important states are the last to appear, and a new connection offers almost
 * nothing. The documented list knows only what the platform ships with, and
 * every serious shop adds to it: WooCommerce lets a plugin register an order
 * status, and shops use that for the parts of their process that are actually
 * theirs — "Shipped", "Follow-up", "Awaiting courier".
 *
 * Those custom statuses are the whole reason a mapping screen exists. Leaving
 * them out meant the screen could not express the one thing it was built for,
 * and there was no way to tell from looking at it that anything was missing.
 *
 * The platform knows the answer exactly and will say so if asked.
 */
interface ListsStatuses
{
    /**
     * Every status this shop has registered, custom ones included.
     *
     * Returns labels as well as values because a slug is not what anybody calls
     * it. The shop says `checkout-draft` and its own admin says "Draft"; a
     * selector offering the former asks somebody to translate machine names
     * back into their own vocabulary in their head.
     *
     * An empty list means the platform could not be asked — a network failure,
     * a key without permission — and never that the shop has no statuses. The
     * caller falls back rather than presenting emptiness as an answer.
     *
     * @param  string  $entity  'order' | 'product'
     * @return list<array{value: string, label: string}>
     */
    public function platformStatuses(Integration $integration, string $entity): array;
}
