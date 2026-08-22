<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Contracts;

use App\Domain\Integrations\Models\Integration;
use App\Domain\Integrations\Support\PullPage;
use Carbon\CarbonInterface;

/**
 * A platform that can be read from.
 *
 * ── Why this is separate from PlatformDriver ─────────────────────────────────
 *
 * Not every connection can be polled. A shop that only posts to us over a
 * webhook, or one whose API is write-only, is an ordinary case rather than a
 * broken one — and a base interface demanding a pull() would force those drivers
 * to carry a method that throws.
 *
 * Opting in instead means the question "can this be read?" is answered by
 * `instanceof`, which the compiler checks, rather than by a capability flag
 * somebody has to remember to keep true.
 */
interface PullsRecords
{
    /**
     * One page of records, as the platform sent them.
     *
     * ── Why a page and not everything ────────────────────────────────────────
     *
     * A three-year-old shop has forty thousand orders. Fetching them into an
     * array is a request that dies on memory somewhere around the fifteen
     * thousandth, having accomplished nothing and left no record of how far it
     * got. A page at a time can be processed, committed and resumed.
     *
     * ── Why the driver owns the cursor ───────────────────────────────────────
     *
     * Because no two of them page the same way. WooCommerce counts pages,
     * Shopify hands back an opaque `page_info` that must be sent verbatim, and a
     * bespoke site does whatever its author chose. The caller passes the cursor
     * back without ever looking inside it.
     *
     * @param  string  $entity  'order' | 'product' | 'customer'
     * @param  CarbonInterface|null  $since  only records changed after this, where the
     *                                       platform can answer that. Null means everything.
     * @param  string|null  $cursor  whatever the previous page returned as `next`
     */
    public function pull(Integration $integration, string $entity, ?CarbonInterface $since = null, ?string $cursor = null): PullPage;

    /**
     * Where this platform keeps an entity's records, or null if it does not.
     *
     * Asked before a sync runs so an entity a connection has no path for is
     * skipped quietly rather than attempted and failed — which matters for the
     * generic driver, where an entity is only available if somebody filled that
     * field in.
     */
    public function supportsEntity(Integration $integration, string $entity): bool;
}
