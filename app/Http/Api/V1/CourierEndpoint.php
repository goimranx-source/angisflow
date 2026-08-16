<?php

declare(strict_types=1);

namespace App\Http\Api\V1;

use App\Domain\Delivery\CourierDashboard;
use App\Domain\Delivery\Models\CourierConnection;
use App\Domain\Delivery\ShipmentStatus;
use App\Domain\Delivery\StatusTranslator;
use App\Http\Api\Endpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The unified courier dashboard.
 *
 * One set of endpoints for every courier a subscriber has connected. Nothing
 * here takes a courier name or branches on one — see CourierDashboard for why
 * that is the point rather than an accident.
 */
class CourierEndpoint extends Endpoint
{
    public function __construct(
        private readonly CourierDashboard $dashboard,
        private readonly StatusTranslator $translator,
    ) {}

    public function overview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        return response()->json([
            'data' => $this->dashboard->overview($validated['from'] ?? null, $validated['to'] ?? null),
        ]);
    }

    public function shipments(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', 'max:24'],
            'connection_id' => ['nullable', 'string', 'size:26'],
            'cod_only' => ['nullable', 'boolean'],
            'unsettled' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:80'],
        ]);

        $page = $this->dashboard->shipments($validated);

        return response()->json([
            'data' => collect($page->items())->map->toPayload()->all(),
            'meta' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    /** The vocabulary a subscriber maps their courier's words onto. */
    public function statuses(): JsonResponse
    {
        return response()->json([
            'data' => array_map(fn (ShipmentStatus $s) => [
                'value' => $s->value,
                'label' => $s->label(),
                'is_final' => $s->isFinal(),
                'is_in_flight' => $s->isInFlight(),
            ], array_filter(
                ShipmentStatus::cases(),
                // Not offered as a choice: unknown is what happens when nobody
                // has chosen, and mapping something to it deliberately would be
                // a way of hiding a parcel from every rule in the system.
                fn (ShipmentStatus $s) => $s !== ShipmentStatus::UNKNOWN,
            )),
        ]);
    }

    /** Everything on one connection still waiting for somebody to decide. */
    public function pendingMappings(string $id): JsonResponse
    {
        $connection = CourierConnection::query()->wherePublicId($id)->firstOrFail();

        return response()->json(['data' => $this->translator->pending($connection)]);
    }

    public function mapStatus(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'raw_status' => ['required', 'string', 'max:120'],
            'canonical' => ['required', 'string', 'max:24'],
        ]);

        $canonical = ShipmentStatus::tryFrom($validated['canonical']);

        if ($canonical === null || $canonical === ShipmentStatus::UNKNOWN) {
            return response()->json([
                'message' => 'That is not a status a courier update can be mapped to.',
                'errors' => ['canonical' => ['Choose one of the listed statuses.']],
            ], 422);
        }

        $connection = CourierConnection::query()->wherePublicId($id)->firstOrFail();
        $mapping = $this->translator->confirm($connection, $validated['raw_status'], $canonical);

        return response()->json([
            'message' => "\"{$mapping->raw_status}\" now means {$canonical->label()}.",
            'data' => $mapping->toPayload(),
        ]);
    }
}
