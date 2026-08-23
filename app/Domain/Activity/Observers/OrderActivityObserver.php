<?php

declare(strict_types=1);

namespace App\Domain\Activity\Observers;

use App\Domain\Activity\Activity;
use App\Domain\Sales\Models\Order;

/**
 * Turning changes to an order into lines somebody can read.
 *
 * ── Why not simply log every changed column ──────────────────────────────────
 *
 * Because "updated_at changed from 14:02:11 to 14:02:12" is not history, and a
 * timeline full of it is worse than no timeline: the three lines that matter get
 * buried under forty that do not, and people stop reading it. Which is the same
 * as not having built it.
 *
 * So the columns are sorted into three kinds.
 *
 *   Told properly.   Status, payment state, courier, totals — the handful
 *                    anybody would actually ask about. Each gets its own verb
 *                    and enough context to render a sentence a year later.
 *
 *   Told in summary. Everything else worth knowing changed, gathered into one
 *                    "edited" line naming the fields, rather than one line each.
 *
 *   Not told at all. Timestamps the framework maintains, and the sync
 *                    bookkeeping that describes this application talking to
 *                    itself rather than anything a person did.
 *
 * ── Why the old value is kept ────────────────────────────────────────────────
 *
 * "Status changed to Shipped" answers half a question. The half people actually
 * arrive with — was it shipped from processing, or reopened from cancelled — is
 * the other value, and it is only available at the moment of the change.
 */
final class OrderActivityObserver
{
    /**
     * Changes that describe the machinery rather than the order.
     *
     * @var list<string>
     */
    private const IGNORED = [
        'updated_at',
        'created_at',
        'deleted_at',

        // Written by the importer to make a retry safe, and meaningless to
        // anybody reading what happened to their order.
        'idempotency_key',
        'search_index',
    ];

    /**
     * Columns worth a line of their own, and what to call it.
     *
     * @var array<string, string>
     */
    private const NOTABLE = [
        'status' => 'status.changed',
        'payment_status' => 'payment.changed',
        'fulfilment_status' => 'fulfilment.changed',
        'total_minor' => 'total.changed',
        'paid_minor' => 'paid.changed',
        'cancelled_reason' => 'cancelled.reason',
    ];

    public function created(Order $order): void
    {
        Activity::record(Activity::ORDER, (int) $order->id, 'created', array_filter([
            'number' => $order->number,
            'status' => $order->status,
            'total_minor' => $order->total_minor,
            'currency' => $order->currency,

            // Where it came from, which is the first thing anybody wants to know
            // about an order they do not recognise.
            'storefront_id' => $order->storefront_id,
        ], fn ($v) => $v !== null));
    }

    public function updated(Order $order): void
    {
        $changes = collect($order->getChanges())
            ->except(self::IGNORED)
            ->keys();

        if ($changes->isEmpty()) {
            return;
        }

        $summarised = [];

        foreach ($changes as $column) {
            /*
             * Archiving reads as an act, not as a column changing.
             *
             * `archived_at` goes from null to a date and back, and "archived_at
             * changed from nothing to 2026-08-24" describes the database rather
             * than the decision. Two verbs say it the way somebody would.
             */
            if ($column === 'archived_at') {
                Activity::record(
                    Activity::ORDER,
                    (int) $order->id,
                    $order->archived_at === null ? 'unarchived' : 'archived',
                    ['status' => $order->status],
                );

                continue;
            }

            $verb = self::NOTABLE[$column] ?? null;

            if ($verb === null) {
                $summarised[] = $column;

                continue;
            }

            Activity::record(Activity::ORDER, (int) $order->id, $verb, [
                'field' => $column,
                'from' => $order->getOriginal($column),
                'to' => $order->{$column},
            ]);
        }

        if ($summarised === []) {
            return;
        }

        /*
         * One line for the rest, naming what changed but not what to.
         *
         * Values are deliberately left out here. A customer's address and phone
         * number would otherwise be copied into an append-only table that is
         * never edited and never deleted — history is exactly the wrong place
         * for personal data, because it is the one place it cannot be corrected
         * or removed later.
         */
        Activity::record(Activity::ORDER, (int) $order->id, 'edited', [
            'fields' => array_values($summarised),
        ]);
    }

    public function deleted(Order $order): void
    {
        // Soft deletes only. A hard delete would take the order's history with
        // it anyway, so there would be nothing left to read this line from.
        Activity::record(Activity::ORDER, (int) $order->id, 'trashed', [
            'status' => $order->status,
        ]);
    }

    public function restored(Order $order): void
    {
        Activity::record(Activity::ORDER, (int) $order->id, 'restored', [
            'status' => $order->status,
        ]);
    }
}
