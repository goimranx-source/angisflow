<?php

declare(strict_types=1);

namespace App\Domain\Delivery;

use App\Domain\Delivery\Models\CourierConnection;
use App\Domain\Delivery\Models\Shipment;
use App\Domain\Delivery\Models\ShipmentEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Applying what a courier tells us to what we believe.
 *
 * ── Events arrive out of order, and that is normal ───────────────────────────
 *
 * A webhook fails and is retried twenty minutes later, by which time two newer
 * ones have already landed. A courier's night batch replays a day's events in
 * whatever order its queue felt like. A rider's app syncs when it finds signal.
 *
 * So an event is not simply applied. Its place in the natural order is compared
 * with where the parcel already is, and one that would drag a delivered parcel
 * back to "in transit" is recorded and ignored. Recorded, because it is still
 * evidence of what they sent; ignored, because the customer has the parcel and
 * no amount of late paperwork changes that.
 *
 * The exception is anything final. A delivered parcel that later goes to
 * "returned" is a real sequence — the customer sent it back — so finals are
 * allowed to follow finals, and only backwards movement into an in-flight state
 * is refused.
 */
final class ShipmentTracker
{
    public function __construct(
        private readonly StatusTranslator $translator,
    ) {}

    /**
     * Record one event and move the shipment if it should.
     *
     * @param  array<string, mixed>  $payload  exactly what arrived
     */
    public function record(
        Shipment $shipment,
        string $rawStatus,
        array $payload = [],
        array $options = [],
    ): ShipmentEvent {
        $connection = $shipment->courierConnection
            ?? CourierConnection::find($shipment->courier_connection_id);

        $resolved = $connection === null
            ? ['status' => ShipmentStatus::UNKNOWN, 'raw' => $rawStatus, 'mapped' => false]
            : $this->translator->translate($connection, $rawStatus);

        /** @var ShipmentStatus $incoming */
        $incoming = $resolved['status'];
        $occurredAt = isset($options['occurred_at'])
            ? Carbon::parse($options['occurred_at'])
            : now();

        return DB::transaction(function () use ($shipment, $incoming, $resolved, $payload, $options, $occurredAt, $connection) {
            $current = $shipment->status();
            $ignored = $this->reasonToIgnore($shipment, $current, $incoming, $occurredAt);

            $event = ShipmentEvent::create([
                'shipment_id' => $shipment->id,
                'business_id' => $shipment->business_id,
                'status' => $incoming->value,
                'raw_status' => $resolved['raw'],
                'description' => $options['description'] ?? null,
                'location' => $options['location'] ?? null,
                'occurred_at' => $occurredAt,
                'received_at' => now(),
                'source' => $options['source'] ?? 'webhook',
                'payload' => $payload === [] ? null : $payload,
                'applied' => $ignored === null,
                'ignored_reason' => $ignored,
            ]);

            // Heard from, whatever the event said. A connection delivering
            // nothing but unrecognised statuses is still alive, and marking it
            // silent would send somebody hunting for the wrong problem.
            $connection?->forceFill(['last_seen_at' => now()])->save();

            if ($ignored !== null) {
                return $event;
            }

            $this->apply($shipment, $incoming, $occurredAt, $options);

            return $event;
        });
    }

    /**
     * Why this event should not move the parcel, or null if it should.
     */
    private function reasonToIgnore(
        Shipment $shipment,
        ShipmentStatus $current,
        ShipmentStatus $incoming,
        Carbon $occurredAt,
    ): ?string {
        if ($incoming === ShipmentStatus::UNKNOWN) {
            // Deliberately not a reason to ignore when the parcel has not
            // started moving — an unrecognised first status is better shown
            // than hidden. But it must never overwrite a real one.
            return $current === ShipmentStatus::DRAFT
                ? null
                : 'Unrecognised status — mapping needed before this can be applied';
        }

        if ($incoming === $current) {
            // Except an attempt, which is a counter rather than a state. A
            // courier saying "delivery failed" on Tuesday and again on
            // Wednesday is reporting two failed attempts, not repeating
            // itself — and suppressing the second loses the very signal that
            // distinguishes a customer who is never in from a rider who came
            // at lunchtime. Guarded by the timestamp check below, so a genuine
            // duplicate of the same event is still ignored.
            if ($incoming !== ShipmentStatus::ATTEMPTED) {
                return 'Same status as the one already recorded';
            }
        }

        // A courier repeating an old event after a newer one. Comparing rank
        // rather than timestamps because timestamps from a courier are often
        // the time they processed it, not the time it happened.
        if ($incoming->rank() < $current->rank() && ! $incoming->isFinal()) {
            return "Older than the current status ({$current->value})";
        }

        $latest = ShipmentEvent::where('shipment_id', $shipment->id)
            ->where('applied', true)
            ->orderByDesc('occurred_at')
            ->value('occurred_at');

        if ($latest !== null && $occurredAt->lt($latest)) {
            return 'Happened before an event already applied';
        }

        return null;
    }

    private function apply(Shipment $shipment, ShipmentStatus $status, Carbon $at, array $options): void
    {
        $changes = ['status' => $status->value, 'raw_status' => $options['raw_status'] ?? $shipment->raw_status];

        // The timestamps a dashboard sorts and a settlement report filters on.
        // Set once, on the first event that says so — a courier sending
        // "delivered" three times must not keep moving the delivery date.
        $changes += match ($status) {
            ShipmentStatus::BOOKED => ['booked_at' => $shipment->booked_at ?? $at],
            ShipmentStatus::PICKED_UP => ['picked_up_at' => $shipment->picked_up_at ?? $at],
            ShipmentStatus::DELIVERED => ['delivered_at' => $shipment->delivered_at ?? $at],
            ShipmentStatus::RETURNED => ['returned_at' => $shipment->returned_at ?? $at],
            default => [],
        };

        if ($status === ShipmentStatus::ATTEMPTED) {
            // Counted rather than flagged: three failed attempts is a customer
            // who is never in, and one is a rider who came at lunchtime.
            $changes['attempt_count'] = $shipment->attempt_count + 1;
        }

        $shipment->forceFill($changes)->save();
    }

    /**
     * Re-run stored events after a mapping has been corrected.
     *
     * The reason raw payloads are kept. Somebody maps "Delivered to neighbour"
     * a week after it started arriving, and without this every parcel from that
     * week stays unrecognised for ever — each one needing to be fixed by hand,
     * which nobody does, so the figures stay wrong.
     *
     * @return int how many events changed something this time
     */
    public function replay(Shipment $shipment): int
    {
        $events = ShipmentEvent::where('shipment_id', $shipment->id)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        // Back to the beginning, then forward through the history as it now
        // reads. Replaying from where it is stuck would apply the corrected
        // events in the wrong order.
        $shipment->forceFill([
            'status' => ShipmentStatus::DRAFT->value,
            'attempt_count' => 0,
        ])->save();

        // Every event goes back to not-applied before the pass begins.
        // reasonToIgnore() asks "did anything already applied happen after
        // this?", and without clearing, the flags left by the previous run
        // answer yes for everything after the first event — so a replay
        // applied one event and silently stopped.
        ShipmentEvent::where('shipment_id', $shipment->id)->update(['applied' => false]);

        $applied = 0;
        $connection = $shipment->courierConnection ?? CourierConnection::find($shipment->courier_connection_id);

        foreach ($events as $event) {
            $resolved = $connection === null
                ? ['status' => ShipmentStatus::UNKNOWN]
                : $this->translator->translate($connection, (string) $event->raw_status);

            /** @var ShipmentStatus $incoming */
            $incoming = $resolved['status'];
            $shipment->refresh();

            $ignored = $this->reasonToIgnore($shipment, $shipment->status(), $incoming, $event->occurred_at);

            $event->forceFill([
                'status' => $incoming->value,
                'applied' => $ignored === null,
                'ignored_reason' => $ignored,
            ])->save();

            if ($ignored === null) {
                $this->apply($shipment, $incoming, $event->occurred_at, []);
                $applied++;
            }
        }

        return $applied;
    }
}
