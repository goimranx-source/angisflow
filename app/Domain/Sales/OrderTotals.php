<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use App\Domain\Sales\Models\Order;

/**
 * What an order comes to, worked out from what is in it.
 *
 * ── Why these stopped being typed in ─────────────────────────────────────────
 *
 * The edit form offered six money boxes: subtotal, discount, shipping, tax,
 * total and paid. Four of those are not decisions. They are the arithmetic of
 * the lines above them, and offering them as boxes has three costs.
 *
 * It invites disagreement. Nothing stopped somebody entering a total of 500 on
 * an order whose lines came to 440, and once entered, the order says two
 * different things about what it is worth and every report has to pick one.
 *
 * It goes stale. Change a quantity and the six figures below it are all wrong
 * until somebody remembers to redo the sums by hand.
 *
 * And it is work nobody should be doing. The lines already say all of it.
 *
 * ── The rule, stated once ────────────────────────────────────────────────────
 *
 *     subtotal  what the items normally sell for
 *     discount  how much less than that was actually charged
 *     total     subtotal − discount + shipping + tax
 *
 * Shipping and tax stay typed, because they are real decisions: what was
 * charged for delivery is not derivable from a list of products. Paid is left
 * alone entirely — it is the sum of the payments recorded against the order,
 * and a form that let somebody type it would be a form for lying about money
 * that did or did not arrive.
 *
 * ── Where "normally sells for" comes from ────────────────────────────────────
 *
 * The variant behind the line. A line typed by hand with no product behind it
 * has no list price, and is taken to have been sold at its own price — which
 * makes its discount zero rather than inventing one.
 */
final class OrderTotals
{
    /**
     * Recalculate an order's money from its lines and save it.
     *
     * Shipping and tax are read from the order as it stands, so this can be
     * called after either has been edited.
     */
    public static function recalculate(Order $order): void
    {
        $lines = $order->lines()->with('variant:id,price_minor')->get();

        $atList = 0;
        $charged = 0;

        foreach ($lines as $line) {
            $quantity = (float) $line->quantity;
            $unit = (int) $line->unit_price_minor;

            /*
             * The list price, where there is one to compare against.
             *
             * Below what was charged it is ignored: a variant repriced downward
             * since the order was placed would otherwise report a negative
             * discount, which reads as the customer having paid extra.
             */
            $list = (int) ($line->variant?->price_minor ?? $unit);
            $list = max($list, $unit);

            $atList += (int) round($list * $quantity);
            $charged += (int) round($unit * $quantity);
        }

        $shipping = (int) $order->shipping_minor;
        $tax = (int) $order->tax_minor;

        $order->forceFill([
            'subtotal_minor' => $atList,
            'discount_minor' => $atList - $charged,
            'total_minor' => $charged + $shipping + $tax,
        ])->save();
    }
}
