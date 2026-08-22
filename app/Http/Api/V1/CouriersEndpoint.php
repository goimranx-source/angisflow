<?php

declare(strict_types=1);

namespace App\Http\Api\V1;

use App\Domain\Delivery\Models\Courier;
use App\Domain\Delivery\Models\CourierConnection;
use App\Domain\Delivery\Models\Shipment;
use App\Domain\Delivery\ShipmentStatus;
use App\Domain\Delivery\ShipmentTracker;
use App\Domain\Delivery\StatusTranslator;
use App\Domain\Integrations\Models\Integration;
use App\Domain\Money\Currencies;
use App\Domain\Money\CurrencyService;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Courier connections and analytics.
 */
class CouriersEndpoint
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly CurrencyService $currency,
        private readonly ShipmentTracker $tracker,
        private readonly StatusTranslator $translator,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $business = $this->tenant->business();

        abort_if($business === null, 409, 'No business context.');

        $connections = CourierConnection::query()
            ->where('business_id', $business->id)
            ->with(['courier:id,name,slug', 'integration:id,public_id,status,is_active,configuration'])
            ->orderBy('label')
            ->get();

        $connectionsData = $connections->map(function ($connection) {
            // Check if integration has API credentials configured
            $hasIntegration = $connection->integration !== null;
            $hasConfig = $hasIntegration && !empty($connection->integration->configuration['api_key'] ?? null);
            $isActive = $hasIntegration && $connection->integration->is_active;
            
            return [
                'id' => $connection->public_id,
                'label' => $connection->label,
                'courier' => [
                    'id' => $connection->courier->id,
                    'name' => $connection->courier->name,
                    'slug' => $connection->courier->slug,
                ],
                'status' => $connection->status,
                'is_silent' => $connection->isSilent(),
                'last_seen_at' => $connection->last_seen_at?->toIso8601String(),
                'cod_fee_percent' => (float) $connection->cod_fee_percent,
                'settlement_days' => $connection->settlement_days,
                'unmapped_count' => $connection->unmapped_count,
                'shipments_count' => $connection->shipments()->count(),
                'has_integration' => $hasIntegration,
                'integration_id' => $hasIntegration ? $connection->integration->public_id : null,
                'needs_configuration' => !$hasConfig || !$isActive,
                'is_test' => $connection->courier?->adapter === 'test',
            ];
        });

        // Calculate summary stats
        $totalShipments = Shipment::where('business_id', $business->id)->count();
        $inTransit = Shipment::where('business_id', $business->id)->inFlight()->count();
        $delivered = Shipment::where('business_id', $business->id)
            ->where('status', 'delivered')
            ->whereDate('delivered_at', today())
            ->count();

        // COD outstanding (delivered but not settled)
        $codOutstanding = Shipment::where('business_id', $business->id)
            ->awaitingSettlement()
            ->sum('cod_amount_minor');

        $base = $this->currency->base();
        $scale = 10 ** Currencies::scale($base);

        return response()->json([
            'data' => $connectionsData->all(),
            'summary' => [
                'total_shipments' => $totalShipments,
                'in_transit' => $inTransit,
                'delivered_today' => $delivered,
                'cod_outstanding' => round($codOutstanding / $scale, 2),
                'currency' => $base,
            ],
            'available_couriers' => Courier::query()
                ->availableTo($business->account_id)
                ->orderBy('name')
                ->get()
                ->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'slug' => $c->slug,
                ])->values()->all(),
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $business = $this->tenant->business();

        abort_if($business === null, 409, 'No business context.');

        $connection = CourierConnection::query()
            ->where('business_id', $business->id)
            ->where('public_id', $id)
            ->with('courier:id,name,slug')
            ->firstOrFail();

        // Get shipments for this courier
        $shipments = Shipment::query()
            ->where('business_id', $business->id)
            ->where('courier_connection_id', $connection->id)
            ->with(['order:id,public_id,number'])
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        $base = $this->currency->base();
        $scale = 10 ** Currencies::scale($base);

        // Analytics
        $totalShipments = $shipments->count();
        $delivered = $shipments->where('status', 'delivered')->count();
        $inTransit = $shipments->whereIn('status', ['pending', 'picked_up', 'in_transit', 'out_for_delivery'])->count();
        $failed = $shipments->whereIn('status', ['failed', 'returned'])->count();
        $codOutstanding = $shipments->where('status', 'delivered')
            ->where('is_cod', true)
            ->sum(fn ($s) => max(0, $s->cod_amount_minor - $s->cod_settled_minor));

        return response()->json([
            'connection' => [
                'id' => $connection->public_id,
                'label' => $connection->label,
                'courier' => [
                    'id' => $connection->courier->id,
                    'name' => $connection->courier->name,
                    'slug' => $connection->courier->slug,
                ],
                'status' => $connection->status,
                'cod_fee_percent' => (float) $connection->cod_fee_percent,
                'settlement_days' => $connection->settlement_days,
            ],
            'analytics' => [
                'total_shipments' => $totalShipments,
                'delivered' => $delivered,
                'in_transit' => $inTransit,
                'failed' => $failed,
                'success_rate' => $totalShipments > 0 ? round(($delivered / $totalShipments) * 100, 1) : 0,
                'cod_outstanding' => round($codOutstanding / $scale, 2),
                'currency' => $base,
            ],
            'recent_shipments' => $shipments->map(fn ($s) => [
                'id' => $s->public_id,
                'tracking_number' => $s->tracking_number,
                'order_number' => $s->order?->number,
                'status' => $s->status,
                'recipient_name' => $s->recipient_name,
                'city' => $s->city,
                'is_cod' => $s->is_cod,
                'cod_amount' => $s->is_cod ? round($s->cod_amount_minor / $scale, 2) : 0,
                'created_at' => $s->created_at?->toIso8601String(),
                'delivered_at' => $s->delivered_at?->toIso8601String(),
            ])->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $business = $this->tenant->business();

        abort_if($business === null, 409, 'No business context.');

        $validated = $request->validate([
            'courier_id' => ['nullable', 'integer', 'exists:couriers,id'],
            'courier_name' => ['nullable', 'string', 'max:120', 'required_without:courier_id'],
            'country' => ['nullable', 'string', 'size:2'],
            'label' => ['required', 'string', 'max:255'],
            'cod_fee_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'settlement_days' => ['nullable', 'integer', 'min:0', 'max:30'],
        ]);

        if ($request->filled('courier_name')) {
            $slugBase = Str::slug($validated['courier_name']) ?: 'custom-courier';
            $slug = $slugBase;
            $suffix = 2;

            while (Courier::query()
                ->where('account_id', $business->account_id)
                ->where('slug', $slug)
                ->exists()) {
                $slug = $slugBase.'-'.$suffix++;
            }

            $courier = Courier::create([
                'account_id' => $business->account_id,
                'name' => $validated['courier_name'],
                'slug' => $slug,
                'adapter' => 'generic',
                'country' => isset($validated['country']) ? strtoupper($validated['country']) : null,
            ]);
            $provider = 'generic_courier';
        } else {
            $courier = Courier::query()
                ->availableTo($business->account_id)
                ->whereKey($validated['courier_id'])
                ->firstOrFail();
            $provider = $courier->slug;
        }

        // Create courier connection
        $connection = CourierConnection::create([
            'account_id' => $business->account_id,
            'business_id' => $business->id,
            'courier_id' => $validated['courier_id'],
            'label' => $validated['label'],
            'cod_fee_percent' => $validated['cod_fee_percent'] ?? 0,
            'settlement_days' => $validated['settlement_days'] ?? 3,
            'status' => CourierConnection::ACTIVE,
        ]);

        // Auto-create a linked Integration for API configuration
        $integration = Integration::create([
            'account_id' => $business->account_id,
            'business_id' => $business->id,
            'courier_connection_id' => $connection->id,
            'name' => $validated['label'],
            'type' => 'courier',
            'provider' => $courier->adapter === 'test' ? 'test_courier' : $provider,
            'status' => Integration::STATUS_PAUSED, // Paused until configured
            'is_active' => false,
            'bidirectional' => false,
            'configuration' => [], // Empty until user configures
            'created_by' => $request->user()?->id,
        ]);

        // Link back
        $connection->integration_id = $integration->id;
        $connection->save();

        if ($courier->adapter === 'test') {
            $this->translator->seed($connection, [
                'booked' => ShipmentStatus::BOOKED,
                'picked_up' => ShipmentStatus::PICKED_UP,
                'in_transit' => ShipmentStatus::IN_TRANSIT,
                'out_for_delivery' => ShipmentStatus::OUT_FOR_DELIVERY,
                'delivered' => ShipmentStatus::DELIVERED,
                'returned' => ShipmentStatus::RETURNED,
                'cancelled' => ShipmentStatus::CANCELLED,
            ]);
        }

        return response()->json([
            'message' => 'Courier connection created. Please configure API credentials.',
            'data' => [
                'id' => $connection->public_id,
                'integration_id' => $integration->public_id,
                'needs_configuration' => true,
            ],
        ], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $business = $this->tenant->business();

        abort_if($business === null, 409, 'No business context.');

        $connection = CourierConnection::query()
            ->where('business_id', $business->id)
            ->where('public_id', $id)
            ->firstOrFail();

        $validated = $request->validate([
            'label' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', 'string', 'in:active,paused'],
            'cod_fee_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'settlement_days' => ['sometimes', 'integer', 'min:0', 'max:30'],
        ]);

        $connection->update($validated);

        return response()->json([
            'message' => 'Courier connection updated.',
        ]);
    }

    /** Advance sandbox shipments by one canonical courier status. */
    public function simulate(Request $request, string $id): JsonResponse
    {
        $business = $this->tenant->business();

        abort_if($business === null, 409, 'No business context.');

        $connection = CourierConnection::query()
            ->where('business_id', $business->id)
            ->where('public_id', $id)
            ->with('courier')
            ->firstOrFail();

        abort_if($connection->courier?->adapter !== 'test', 422, 'This is not a sandbox courier.');

        $shipmentQuery = Shipment::query()
            ->where('business_id', $business->id)
            ->where('courier_connection_id', $connection->id)
            ->whereNotIn('status', [
                ShipmentStatus::DELIVERED->value,
                ShipmentStatus::RETURNED->value,
                ShipmentStatus::LOST->value,
                ShipmentStatus::CANCELLED->value,
            ]);

        if ($request->filled('shipment_id')) {
            $shipmentQuery->where('public_id', $request->string('shipment_id'));
        }

        $shipments = $shipmentQuery->get();

        if ($shipments->isEmpty()) {
            return response()->json(['message' => 'No active sandbox shipments found.'], 422);
        }

        $next = [
            ShipmentStatus::DRAFT->value => ShipmentStatus::BOOKED->value,
            ShipmentStatus::BOOKED->value => ShipmentStatus::PICKED_UP->value,
            ShipmentStatus::PICKED_UP->value => ShipmentStatus::IN_TRANSIT->value,
            ShipmentStatus::IN_TRANSIT->value => ShipmentStatus::OUT_FOR_DELIVERY->value,
            ShipmentStatus::OUT_FOR_DELIVERY->value => ShipmentStatus::DELIVERED->value,
            ShipmentStatus::ATTEMPTED->value => ShipmentStatus::OUT_FOR_DELIVERY->value,
            ShipmentStatus::RETURNING->value => ShipmentStatus::RETURNED->value,
        ];

        foreach ($shipments as $shipment) {
            $status = $next[$shipment->status] ?? ShipmentStatus::IN_TRANSIT->value;
            $this->tracker->record($shipment, $status, [
                'sandbox' => true,
                'tracking_number' => $shipment->tracking_number,
                'status' => $status,
            ], [
                'source' => 'sandbox',
                'description' => 'Simulated by Test Courier',
            ]);
        }

        return response()->json([
            'message' => sprintf('%d sandbox shipment(s) advanced.', $shipments->count()),
            'status' => $next[$shipments->first()->status] ?? ShipmentStatus::IN_TRANSIT->value,
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $business = $this->tenant->business();

        abort_if($business === null, 409, 'No business context.');

        $connection = CourierConnection::query()
            ->where('business_id', $business->id)
            ->where('public_id', $id)
            ->firstOrFail();

        // Check if there are shipments
        $hasShipments = $connection->shipments()->exists();

        if ($hasShipments) {
            return response()->json([
                'message' => 'Cannot delete courier connection with existing shipments. Set to paused instead.',
            ], 422);
        }

        $connection->delete();

        return response()->json([
            'message' => 'Courier connection deleted.',
        ]);
    }
}
