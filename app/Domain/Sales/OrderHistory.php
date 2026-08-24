<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use App\Domain\Activity\Activity;
use App\Domain\Sales\Models\Order;

/**
 * The parts of an order's history that are not columns on the order.
 *
 * ── Why this exists at all ───────────────────────────────────────────────────
 *
 * An observer watches the orders table, so every change to a column on an order
 * writes itself into the history without anybody remembering to. Its items are
 * a different table with nobody watching it, and the practical result was that
 * an order's items — most of what an order actually is — could be doubled,
 * halved, swapped or deleted and the history would show nothing whatever.
 *
 * There are two places that change them, a person editing an order and a shop
 * sending its own version of one, and both need to say the same thing the same
 * way. That is the whole reason this is a class rather than a method on either
 * of them.
 */
final class OrderHistory
{
    /**
     * Note that an order's items are not what they were.
     *
     * @param  list<string>  $added    descriptions of lines that appeared
     * @param  list<string>  $changed  descriptions of lines that moved
     * @param  list<string>  $removed  descriptions of lines that went
     */
    public static function itemsChanged(Order $order, array $added, array $changed, array $removed): void
    {
        if ($added === [] && $changed === [] && $removed === []) {
            // The ordinary case, and the reason this checks: a shop's sync
            // re-sends every line of every order on every pass, and a history
            // that noted each pass would be a history of the sync rather than
            // of the order.
            return;
        }

        $phrase = implode(', ', array_filter([
            $added === [] ? null : count($added).' added',
            $changed === [] ? null : count($changed).' changed',
            $removed === [] ? null : count($removed).' removed',
        ]));

        /*
         * Folded into whatever act is underway, if one is.
         *
         * A shop sync that rewrites three lines has already earned itself a
         * line saying so; a second one underneath saying the items changed is
         * the same news twice. When nothing is underway — somebody editing the
         * order by hand — this is the news, and gets its own line.
         */
        if (Activity::consequence(Activity::ORDER, (int) $order->id, 'items', null, $phrase)) {
            return;
        }

        Activity::record(Activity::ORDER, (int) $order->id, 'items.changed', array_filter([
            'summary' => $phrase,
            'added' => self::few($added),
            'changed' => self::few($changed),
            'removed' => self::few($removed),
        ], static fn (mixed $value): bool => $value !== null));
    }

    /**
     * A few of them, not all.
     *
     * An import of a fifty-line order would otherwise write fifty product names
     * into a row that is never edited and never deleted. The count is already
     * in the summary, so what is wanted here is enough to recognise which
     * items, not a second copy of the order.
     *
     * @param  list<string>  $names
     * @return list<string>|null
     */
    private static function few(array $names): ?array
    {
        return $names === [] ? null : array_slice($names, 0, 4);
    }
}
