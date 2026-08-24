<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use App\Domain\Sales\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * This business's own name for an order.
 *
 * ── Why an order needs one at all ────────────────────────────────────────────
 *
 * `number` is whatever identifies the order where it came from: this
 * application's own for a counter sale, the shop's for a pulled one. That is
 * the number to quote when ringing the shop, so it stays exactly as the shop
 * wrote it — and it is no use as this business's own reference, because two
 * shops will eventually both send an order 1001 and there is nowhere to put the
 * second.
 *
 * ── SO26-0001 ────────────────────────────────────────────────────────────────
 *
 * Any format putting a four-digit year between two separators reads as a date,
 * whichever separator is used -- SO-2026-0001 is a long word with two stumbles
 * in it, and SO/2026/0001 is worse, because that is how a date is written.
 *
 * Two digits of year, fused to the prefix, and one separator. "SO26" becomes a
 * single token that reads as a label rather than as a number in its own right,
 * and what is left is plainly a label and a counter. Short enough to read back
 * over the phone in one go, and impossible to mistake for a date.
 *
 * Restarting each year is what accountants expect and what keeps the counter
 * short; the year in the label is what stops two years colliding.
 */
final class OrderReference
{
    /**
     * The next reference for a business, in the year the order was placed.
     *
     * ── Why it is read straight from the table ───────────────────────────────
     *
     * A counter row would be faster and would need its own reset every January,
     * its own row per business, and a migration to seed it from what already
     * exists. The orders table already knows the answer, and the volume that
     * would make the scan cost anything is far past the volume at which this
     * business is doing something other than reading a list.
     */
    public static function next(int $businessId, ?string $orderedOn = null): string
    {
        // The last two digits of the year the order was placed in.
        $year = substr($orderedOn ?: now()->toDateString(), 2, 2);
        $prefix = 'SO'.$year.'-';

        /*
         * Ordered by length first.
         *
         * These are strings, and a string sort puts SO26-10000 before
         * SO26-9999 — so at the ten-thousandth order of a year the sequence
         * would quietly start again at 10000 and collide with itself until
         * somebody noticed. Shorter strings first, then alphabetically, is
         * numeric order for a zero-padded sequence of any width.
         */
        $last = DB::table('orders')
            ->where('business_id', $businessId)
            ->where('reference', 'like', $prefix.'%')
            ->orderByRaw('LENGTH(reference) DESC')
            ->orderByDesc('reference')
            ->value('reference');

        $next = $last === null ? 1 : ((int) substr((string) $last, strlen($prefix))) + 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Give an order a reference if it has not got one.
     *
     * Called from the model rather than from each place an order is made. There
     * are four of those — the counter, the importer, the shop reconciler and
     * whatever is written next — and a reference that depends on remembering to
     * ask for it is one that will be missing from the fourth.
     */
    public static function assign(Order $order): void
    {
        if (trim((string) $order->reference) !== '') {
            return;
        }

        $businessId = (int) $order->business_id;

        if ($businessId === 0) {
            return;
        }

        $order->reference = self::next(
            $businessId,
            $order->ordered_on instanceof \DateTimeInterface
                ? $order->ordered_on->format('Y-m-d')
                : (is_string($order->ordered_on) ? $order->ordered_on : null),
        );
    }
}
