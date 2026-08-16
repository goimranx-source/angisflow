<?php

declare(strict_types=1);

namespace App\Domain\Delivery;

use App\Domain\Delivery\Models\CourierConnection;
use App\Domain\Delivery\Models\Shipment;
use App\Domain\Delivery\Models\WebhookDelivery;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * One dashboard, however many couriers.
 *
 * ── Nothing in this class knows what a courier is called ─────────────────────
 *
 * That is the whole payoff of the canonical model. Every question below is
 * asked once, against `shipments`, and answered identically whether the
 * subscriber has one courier with a REST API or nine with none. Connecting a
 * tenth changes no query, no column and no line of this file.
 *
 * A dashboard written per courier — the obvious way, and how most tools do it —
 * has to be extended for each, so the tenth courier is a release rather than a
 * settings page. That is why this design was worth the two tasks it took.
 *
 * ── Why comparison is the point, not the totals ──────────────────────────────
 *
 * "Two hundred parcels out" is a number anybody can get from a courier's own
 * portal. What no portal can tell you is that Courier A delivers in 1.8 days
 * with a 4% return rate and Courier B takes 3.4 days with 11% — because each
 * portal only knows about itself. Putting them side by side is the reason a
 * unified dashboard is worth building at all, and it is what byCourier() is
 * for.
 */
final class CourierDashboard
{
    public function __construct(
        private readonly TenantContext $tenant,
    ) {}

    /**
     * Everything the screen needs, in one call.
     *
     * @return array<string, mixed>
     */
    public function overview(?string $from = null, ?string $to = null): array
    {
        return [
            'board' => $this->board(),
            'money' => $this->moneyAtRisk(),
            'couriers' => $this->byCourier($from, $to),
            'attention' => $this->attention(),
            'connections' => $this->connectionHealth(),
        ];
    }

    /**
     * How many parcels are in each state right now, across everything.
     *
     * Every canonical status appears, including the ones with nothing in them.
     * A board that hides empty columns reflows every time something moves,
     * which makes it unreadable at exactly the moment somebody is scanning it
     * quickly.
     *
     * @return list<array<string, mixed>>
     */
    public function board(): array
    {
        $counts = Shipment::query()
            ->where('business_id', $this->businessId())
            ->groupBy('status')
            ->selectRaw('status, COUNT(*) AS total')
            ->pluck('total', 'status');

        $out = [];

        foreach (ShipmentStatus::cases() as $status) {
            if ($status === ShipmentStatus::DRAFT) {
                continue;
            }

            $out[] = [
                'status' => $status->value,
                'label' => $status->label(),
                'count' => (int) ($counts[$status->value] ?? 0),
                'is_final' => $status->isFinal(),
                'needs_action' => $status === ShipmentStatus::UNKNOWN,
            ];
        }

        return $out;
    }

    /**
     * Cash the couriers are holding, and how long they have held it.
     *
     * The number nobody can produce from a courier's portal: money that has
     * left the customer, is not in the bank, and belongs to us. Aged, because
     * a courier who is four days behind their stated seven is normal and one
     * who is thirty days behind is a conversation.
     *
     * @return array<string, mixed>
     */
    public function moneyAtRisk(): array
    {
        $rows = Shipment::query()
            ->where('business_id', $this->businessId())
            ->awaitingSettlement()
            ->with('courierConnection.courier')
            ->get();

        $currency = $this->currency();
        $buckets = ['0_7' => 0, '8_14' => 0, '15_30' => 0, 'over_30' => 0];
        $byCourier = [];

        foreach ($rows as $shipment) {
            $outstanding = $shipment->codOutstanding()->minor;

            if ($outstanding <= 0) {
                continue;
            }

            $days = $shipment->delivered_at === null
                ? 0
                : (int) $shipment->delivered_at->diffInDays(now());

            $bucket = match (true) {
                $days <= 7 => '0_7',
                $days <= 14 => '8_14',
                $days <= 30 => '15_30',
                default => 'over_30',
            };

            $buckets[$bucket] += $outstanding;

            $key = $shipment->courierConnection?->public_id ?? 'none';
            $byCourier[$key] ??= [
                'courier' => $shipment->courierConnection?->label
                    ?? $shipment->courierConnection?->courier?->name
                    ?? 'Unassigned',
                // What they said they would take to pay, so "late" is measured
                // against their own promise rather than an arbitrary number.
                'settlement_days' => $shipment->courierConnection?->settlement_days,
                'total' => 0,
                'oldest_days' => 0,
                'parcels' => 0,
            ];

            $byCourier[$key]['total'] += $outstanding;
            $byCourier[$key]['parcels']++;
            $byCourier[$key]['oldest_days'] = max($byCourier[$key]['oldest_days'], $days);
        }

        uasort($byCourier, fn ($a, $b) => $b['total'] <=> $a['total']);

        return [
            'currency' => $currency,
            'total' => (new Money(array_sum($buckets), $currency))->jsonSerialize(),
            'buckets' => array_map(
                fn (int $minor) => (new Money($minor, $currency))->jsonSerialize(),
                $buckets,
            ),
            'by_courier' => array_values(array_map(fn (array $c) => [
                'courier' => $c['courier'],
                'parcels' => $c['parcels'],
                'total' => (new Money($c['total'], $currency))->jsonSerialize(),
                'oldest_days' => $c['oldest_days'],
                'settlement_days' => $c['settlement_days'],
                // Overdue against their own promise, not ours.
                'is_overdue' => $c['settlement_days'] !== null && $c['oldest_days'] > $c['settlement_days'],
            ], $byCourier)),
        ];
    }

    /**
     * Couriers side by side, on the things that decide which to use.
     *
     * Delivery rate, speed, failed attempts and returns. Computed from our own
     * records rather than theirs, which matters: a courier's own report of
     * their delivery rate is a marketing document.
     *
     * @return list<array<string, mixed>>
     */
    public function byCourier(?string $from = null, ?string $to = null): array
    {
        $connections = CourierConnection::query()
            ->where('business_id', $this->businessId())
            ->with('courier')
            ->get();

        $out = [];

        foreach ($connections as $connection) {
            $shipments = Shipment::query()
                ->where('courier_connection_id', $connection->id)
                ->when($from !== null, fn ($q) => $q->whereDate('created_at', '>=', $from))
                ->when($to !== null, fn ($q) => $q->whereDate('created_at', '<=', $to))
                ->get(['status', 'attempt_count', 'booked_at', 'delivered_at', 'delivery_fee_minor', 'cod_amount_minor']);

            $total = $shipments->count();

            if ($total === 0) {
                continue;
            }

            $delivered = $shipments->where('status', ShipmentStatus::DELIVERED->value);
            $returned = $shipments->whereIn('status', [
                ShipmentStatus::RETURNED->value, ShipmentStatus::RETURNING->value,
            ]);
            $lost = $shipments->where('status', ShipmentStatus::LOST->value);

            // Only over parcels that finished, so a courier with a lot in
            // flight is not flattered by counting them as neither delivered
            // nor returned.
            $settled = $delivered->count() + $returned->count() + $lost->count();

            $times = $delivered
                ->filter(fn ($s) => $s->booked_at !== null && $s->delivered_at !== null)
                ->map(fn ($s) => $s->booked_at->diffInHours($s->delivered_at) / 24);

            $out[] = [
                'courier' => $connection->label ?? $connection->courier?->name,
                'connection_id' => $connection->public_id,
                'status' => $connection->status,
                'shipments' => $total,
                'delivered' => $delivered->count(),
                'returned' => $returned->count(),
                'lost' => $lost->count(),
                'in_flight' => $shipments->filter(
                    fn ($s) => (ShipmentStatus::tryFrom($s->status) ?? ShipmentStatus::UNKNOWN)->isInFlight()
                )->count(),
                // Null rather than zero when nothing has finished. A courier
                // tried once with the parcel still out has no delivery rate,
                // and 0% would rank them below one that is genuinely bad.
                'delivery_rate' => $settled === 0 ? null : round($delivered->count() / $settled * 100, 1),
                'return_rate' => $settled === 0 ? null : round($returned->count() / $settled * 100, 1),
                'avg_days' => $times->isEmpty() ? null : round($times->avg(), 1),
                // Across every parcel that has left, not only the delivered
                // ones. Averaging over deliveries alone reported zero for a
                // courier whose failures never became deliveries — which is
                // exactly the courier the number exists to expose.
                'avg_attempts' => $total === 0 ? null : round($shipments->avg('attempt_count'), 2),
                'failed_attempts' => (int) $shipments->sum('attempt_count'),
                'fees' => (new Money((int) $shipments->sum('delivery_fee_minor'), $this->currency()))->jsonSerialize(),
            ];
        }

        usort($out, fn ($a, $b) => $b['shipments'] <=> $a['shipments']);

        return $out;
    }

    /**
     * Everything a person should look at today.
     *
     * Ordered by how much it costs to ignore: money first, then parcels that
     * have stopped moving, then the plumbing.
     *
     * @return array<string, mixed>
     */
    public function attention(): array
    {
        $businessId = $this->businessId();

        $unknown = Shipment::query()
            ->where('business_id', $businessId)
            ->where('status', ShipmentStatus::UNKNOWN->value)
            ->with('courierConnection.courier')
            ->limit(25)
            ->get();

        $stuck = Shipment::query()
            ->where('business_id', $businessId)
            ->inFlight()
            ->where('booked_at', '<', now()->subDays(5))
            ->with('courierConnection.courier')
            ->orderBy('booked_at')
            ->limit(25)
            ->get();

        $repeatedlyMissed = Shipment::query()
            ->where('business_id', $businessId)
            ->inFlight()
            ->where('attempt_count', '>=', 2)
            ->with('courierConnection.courier')
            ->orderByDesc('attempt_count')
            ->limit(25)
            ->get();

        return [
            // A parcel whose status nobody recognises is a parcel nothing
            // downstream can act on — no settlement, no stock return, no
            // customer notification.
            'unrecognised' => [
                'count' => $unknown->count(),
                'items' => $unknown->map->toPayload()->all(),
            ],
            'stuck' => [
                'count' => $stuck->count(),
                'items' => $stuck->map->toPayload()->all(),
            ],
            'repeatedly_missed' => [
                'count' => $repeatedlyMissed->count(),
                'items' => $repeatedlyMissed->map->toPayload()->all(),
            ],
            'unmapped_statuses' => (int) CourierConnection::query()
                ->where('business_id', $businessId)
                ->sum('unmapped_count'),
            'webhooks_needing_review' => WebhookDelivery::query()
                ->where('business_id', $businessId)
                ->unresolved()
                ->count(),
        ];
    }

    /**
     * Whether each connection is actually working.
     *
     * A connection that has stopped receiving looks exactly like a quiet week,
     * and the difference matters enormously — one is fine and the other means
     * nobody has known where any parcel is since Tuesday.
     *
     * @return list<array<string, mixed>>
     */
    public function connectionHealth(): array
    {
        return CourierConnection::query()
            ->where('business_id', $this->businessId())
            ->with('courier')
            ->get()
            ->map(function (CourierConnection $connection) {
                $recent = WebhookDelivery::query()
                    ->where('courier_connection_id', $connection->id)
                    ->where('received_at', '>=', now()->subDays(7))
                    ->selectRaw("COUNT(*) AS total, SUM(CASE WHEN status IN ('failed','rejected') THEN 1 ELSE 0 END) AS bad")
                    ->first();

                return [
                    ...$connection->toPayload(),
                    'webhooks_7d' => (int) ($recent->total ?? 0),
                    'webhook_failures_7d' => (int) ($recent->bad ?? 0),
                    // No signature means the endpoint trusts anyone who finds
                    // the URL. Surfaced rather than buried, so the trade-off is
                    // a decision rather than an accident.
                    'is_signed' => $connection->webhook_secret !== null && $connection->webhook_secret !== '',
                ];
            })
            ->all();
    }

    /**
     * The parcels themselves, filtered the way an operator would filter them.
     *
     * @param  array<string, mixed>  $filters
     */
    public function shipments(array $filters = [], int $perPage = 50)
    {
        return Shipment::query()
            ->where('business_id', $this->businessId())
            ->with('courierConnection.courier', 'order')
            ->when(($filters['status'] ?? null) !== null, fn ($q) => $q->where('status', $filters['status']))
            ->when(($filters['connection_id'] ?? null) !== null, fn ($q) => $q->whereHas(
                'courierConnection',
                fn ($c) => $c->where('public_id', $filters['connection_id']),
            ))
            ->when(($filters['cod_only'] ?? false) === true, fn ($q) => $q->where('is_cod', true))
            ->when(($filters['unsettled'] ?? false) === true, fn ($q) => $q->awaitingSettlement())
            ->when(($filters['search'] ?? null) !== null, fn ($q) => $q->where(fn ($w) => $w
                ->where('tracking_number', 'like', '%'.$filters['search'].'%')
                ->orWhere('number', 'like', '%'.$filters['search'].'%')
                ->orWhere('recipient_name', 'like', '%'.$filters['search'].'%')
                ->orWhere('recipient_phone', 'like', '%'.$filters['search'].'%')))
            // Oldest first among things still moving: the dashboard should open
            // on what has been waiting longest, not on what happened last.
            ->orderByRaw('CASE WHEN status IN (?, ?, ?, ?) THEN 0 ELSE 1 END', [
                ShipmentStatus::UNKNOWN->value,
                ShipmentStatus::ATTEMPTED->value,
                ShipmentStatus::RETURNING->value,
                ShipmentStatus::IN_TRANSIT->value,
            ])
            ->orderBy('booked_at')
            ->paginate($perPage);
    }

    private function businessId(): int
    {
        $business = $this->tenant->business();

        if ($business === null) {
            throw new RuntimeException('No set of books is open, so there is nothing to show.');
        }

        return $business->id;
    }

    private function currency(): string
    {
        return strtoupper($this->tenant->business()?->base_currency ?? 'USD');
    }
}
