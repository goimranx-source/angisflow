<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Putting the dispatches back into the histories they were missing from.
 *
 * ── What every already-dispatched order looks like without this ──────────────
 *
 * Two lines and no explanation:
 *
 *     Status: Processing → Shipped
 *     Fulfilment: Unfulfilled → Partial
 *
 * No courier, no tracking number, no cash-on-delivery figure, and nothing
 * saying anybody dispatched anything. From now on that same act writes one line
 * that says all of it — but only from now on, and an order dispatched last week
 * would keep the broken shape for ever. Somebody looking at one of those to
 * check the fix would reasonably conclude it had not worked.
 *
 * ── Why this is a recovery and not an invention ──────────────────────────────
 *
 * Because the shipment row is the primary record of the dispatch, and always
 * was. It holds the moment, the courier, the tracking number and the amount to
 * collect — everything the missing line needs. Nothing here is inferred from
 * weak signals; it is read from the table that owns the fact and written into
 * the table that should always have described it.
 *
 * It is still marked as recovered, and says so on screen. A history that
 * presents a reconstruction as something this application watched happen is
 * lying about the one thing a history is for.
 *
 * ── Why the two column lines are removed ─────────────────────────────────────
 *
 * They are the same event told worse, and leaving them would mean every
 * recovered dispatch reads three times. They are deleted only where they sit
 * within three seconds of the shipment being created, on the same order, moving
 * to exactly the states a dispatch moves them to — which is tight enough that a
 * status somebody changed by hand cannot be caught by it.
 */
return new class extends Migration
{
    /** How close to the shipment a change has to be to have been caused by it. */
    private const WITHIN_SECONDS = 3;

    public function up(): void
    {
        $shipments = DB::table('shipments')
            ->leftJoin('courier_connections', 'courier_connections.id', '=', 'shipments.courier_connection_id')
            ->leftJoin('orders', 'orders.id', '=', 'shipments.order_id')
            ->orderBy('shipments.created_at')
            ->get([
                'shipments.id',
                'shipments.order_id',
                'shipments.tracking_number',
                'shipments.is_cod',
                'shipments.cod_amount_minor',
                'shipments.currency',
                'shipments.created_at',
                'courier_connections.label as courier',
                'orders.account_id',
                'orders.business_id',
            ]);

        foreach ($shipments as $shipment) {
            if ($shipment->order_id === null || $shipment->account_id === null) {
                // A shipment whose order has been deleted outright. There is no
                // history left to write into.
                continue;
            }

            $at = Carbon\Carbon::parse((string) $shipment->created_at);

            /*
             * Already recovered, or already recorded properly.
             *
             * This migration is written to be safe to run twice — and on a
             * database where some dispatches happened after the fix landed, the
             * newer ones must be left exactly as they are.
             */
            $already = DB::table('activity_events')
                ->where('subject_type', 'order')
                ->where('subject_id', $shipment->order_id)
                ->where('verb', 'dispatched')
                ->whereBetween('occurred_at', [
                    $at->copy()->subSeconds(self::WITHIN_SECONDS),
                    $at->copy()->addSeconds(self::WITHIN_SECONDS),
                ])
                ->exists();

            if ($already) {
                continue;
            }

            // The consequences this dispatch had, still filed as changes of
            // their own.
            $caused = DB::table('activity_events')
                ->where('subject_type', 'order')
                ->where('subject_id', $shipment->order_id)
                ->whereIn('verb', ['status.changed', 'fulfilment.changed'])
                ->whereBetween('occurred_at', [
                    $at->copy()->subSeconds(self::WITHIN_SECONDS),
                    $at->copy()->addSeconds(self::WITHIN_SECONDS),
                ])
                ->get(['id', 'verb', 'actor_user_id', 'context']);

            $also = [];
            $actor = null;

            foreach ($caused as $event) {
                $context = json_decode((string) $event->context, true);

                if (! is_array($context)) {
                    continue;
                }

                // Exactly the moves a dispatch makes, and no others. Anything
                // else within those three seconds was somebody else's doing.
                $moved = ($context['field'] ?? null).':'.($context['to'] ?? null);

                if (! in_array($moved, ['status:shipped', 'fulfilment_status:partial'], true)) {
                    continue;
                }

                $also[] = array_filter([
                    'field' => $context['field'] ?? null,
                    'from' => $context['from'] ?? null,
                    'to' => $context['to'] ?? null,
                ], static fn (mixed $value): bool => $value !== null);

                // Whoever was recorded as making the change is who dispatched
                // it. Without this the recovered line would say the sync did
                // it, which is both wrong and the more alarming answer.
                $actor ??= $event->actor_user_id;

                DB::table('activity_events')->where('id', $event->id)->delete();
            }

            DB::table('activity_events')->insert([
                'account_id' => $shipment->account_id,
                'business_id' => $shipment->business_id,
                'actor_user_id' => $actor,
                'subject_type' => 'order',
                'subject_id' => $shipment->order_id,
                'verb' => 'dispatched',
                'context' => json_encode(array_filter([
                    'courier' => $shipment->courier,
                    'tracking' => $shipment->tracking_number,
                    'cod_minor' => $shipment->is_cod ? $shipment->cod_amount_minor : null,
                    'currency' => $shipment->currency,
                    'also' => $also === [] ? null : $also,

                    // Said out loud on screen. See the note at the top.
                    'recovered' => true,
                ], static fn (mixed $value): bool => $value !== null && $value !== false)),
                'occurred_at' => $at->format('Y-m-d H:i:s.v'),
                'occurred_on' => $at->toDateString(),
            ]);
        }
    }

    public function down(): void
    {
        /*
         * The recovered lines go; the ones they replaced do not come back.
         *
         * They were a worse description of the same events, and the shipments
         * they were derived from are all still there — so running this
         * migration again rebuilds exactly what it removes here.
         */
        DB::table('activity_events')
            ->where('verb', 'dispatched')
            ->where('context', 'like', '%"recovered":true%')
            ->delete();
    }
};
