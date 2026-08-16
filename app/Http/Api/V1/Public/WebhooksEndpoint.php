<?php

declare(strict_types=1);

namespace App\Http\Api\V1\Public;

use App\Http\Api\Endpoint;
use App\Http\Api\ApiResponse;
use App\Domain\PublicApi\PublicApiService;
use App\Models\ApiWebhook;
use App\Models\ApiWebhookDelivery;
use App\Jobs\ProcessWebhookDelivery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public API Webhooks Endpoint
 *
 * Provides external access to webhook management operations.
 * Allows third-party systems to create, configure, and monitor webhooks.
 */
class WebhooksEndpoint extends Endpoint
{
    public function __construct(
        private PublicApiService $publicApiService
    ) {}

    /**
     * List webhooks
     */
    public function index(Request $request): JsonResponse
    {
        $this->validate($request, [
            'status' => 'sometimes|in:active,inactive',
            'event' => 'sometimes|string',
            'limit' => 'sometimes|integer|min:1|max:100',
            'page' => 'sometimes|integer|min:1',
        ]);

        $query = ApiWebhook::where('business_id', $this->getCurrentBusiness()->id);

        if ($request->filled('status')) {
            $isActive = $request->status === 'active';
            $query->where('is_active', $isActive);
        }

        if ($request->filled('event')) {
            $query->whereJsonContains('events', $request->event);
        }

        $webhooks = $query->orderBy('created_at', 'desc')
            ->paginate($request->get('limit', 25));

        return ApiResponse::paginated($webhooks, function ($webhook) {
            return $this->transformWebhook($webhook);
        });
    }

    /**
     * Create webhook
     */
    public function store(Request $request): JsonResponse
    {
        $this->validate($request, [
            'name' => 'required|string|max:255',
            'url' => 'required|url|max:500',
            'events' => 'required|array|min:1',
            'events.*' => 'string|in:' . implode(',', $this->getAvailableEvents()),
            'content_type' => 'sometimes|in:application/json,application/x-www-form-urlencoded',
            'custom_headers' => 'sometimes|array',
            'timeout_seconds' => 'sometimes|integer|min:1|max:60',
            'filters' => 'sometimes|array',
            'is_active' => 'sometimes|boolean',
        ]);

        $webhook = $this->publicApiService->createWebhook(
            $request->name,
            $request->url,
            $request->events,
            $this->getCurrentBusiness()->id,
            $this->getCurrentApiKey()?->id,
            $request->only([
                'content_type', 'custom_headers', 'timeout_seconds', 
                'filters', 'is_active'
            ])
        );

        return ApiResponse::item($this->transformWebhook($webhook), 201);
    }

    /**
     * Show webhook
     */
    public function show(string $id): JsonResponse
    {
        $webhook = ApiWebhook::where('business_id', $this->getCurrentBusiness()->id)
            ->where('public_id', $id)
            ->with('recentDeliveries')
            ->firstOrFail();

        return ApiResponse::item($this->transformWebhook($webhook, true));
    }

    /**
     * Update webhook
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $this->validate($request, [
            'name' => 'sometimes|string|max:255',
            'url' => 'sometimes|url|max:500',
            'events' => 'sometimes|array|min:1',
            'events.*' => 'string|in:' . implode(',', $this->getAvailableEvents()),
            'content_type' => 'sometimes|in:application/json,application/x-www-form-urlencoded',
            'custom_headers' => 'sometimes|array',
            'timeout_seconds' => 'sometimes|integer|min:1|max:60',
            'filters' => 'sometimes|array',
            'is_active' => 'sometimes|boolean',
        ]);

        $webhook = ApiWebhook::where('business_id', $this->getCurrentBusiness()->id)
            ->where('public_id', $id)
            ->firstOrFail();

        $updateData = $request->only([
            'name', 'url', 'events', 'content_type', 'custom_headers',
            'timeout_seconds', 'filters', 'is_active'
        ]);

        $webhook->update($updateData);

        return ApiResponse::item($this->transformWebhook($webhook));
    }

    /**
     * Delete webhook
     */
    public function destroy(string $id): JsonResponse
    {
        $webhook = ApiWebhook::where('business_id', $this->getCurrentBusiness()->id)
            ->where('public_id', $id)
            ->firstOrFail();

        $webhook->delete();

        return ApiResponse::item(['message' => 'Webhook deleted successfully']);
    }

    /**
     * Get webhook deliveries
     */
    public function deliveries(Request $request, string $id): JsonResponse
    {
        $this->validate($request, [
            'status' => 'sometimes|in:pending,delivered,failed',
            'event_type' => 'sometimes|string',
            'created_after' => 'sometimes|date',
            'created_before' => 'sometimes|date|after_or_equal:created_after',
            'limit' => 'sometimes|integer|min:1|max:100',
            'page' => 'sometimes|integer|min:1',
        ]);

        $webhook = ApiWebhook::where('business_id', $this->getCurrentBusiness()->id)
            ->where('public_id', $id)
            ->firstOrFail();

        $query = ApiWebhookDelivery::where('api_webhook_id', $webhook->id);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('event_type')) {
            $query->where('event_type', $request->event_type);
        }

        if ($request->filled('created_after')) {
            $query->where('created_at', '>=', $request->created_after);
        }

        if ($request->filled('created_before')) {
            $query->where('created_at', '<=', $request->created_before);
        }

        $deliveries = $query->orderBy('created_at', 'desc')
            ->paginate($request->get('limit', 25));

        return ApiResponse::paginated($deliveries, function ($delivery) {
            return [
                'id' => $delivery->public_id,
                'delivery_id' => $delivery->delivery_id,
                'event_type' => $delivery->event_type,
                'event_id' => $delivery->event_id,
                'status' => $delivery->status,
                'attempts' => $delivery->attempts,
                'response_status' => $delivery->response_status,
                'response_time_ms' => $delivery->response_time_ms,
                'error_message' => $delivery->error_message,
                'created_at' => $delivery->created_at->toIso8601String(),
                'last_attempt_at' => $delivery->last_attempt_at?->toIso8601String(),
                'delivered_at' => $delivery->delivered_at?->toIso8601String(),
            ];
        });
    }

    /**
     * Test webhook
     */
    public function test(Request $request, string $id): JsonResponse
    {
        $this->validate($request, [
            'event_type' => 'sometimes|string|in:' . implode(',', $this->getAvailableEvents()),
        ]);

        $webhook = ApiWebhook::where('business_id', $this->getCurrentBusiness()->id)
            ->where('public_id', $id)
            ->firstOrFail();

        if (!$webhook->is_active) {
            return response()->json([
                'error' => 'Cannot test inactive webhook',
                'code' => 'WEBHOOK_INACTIVE',
            ], 422);
        }

        $eventType = $request->get('event_type', 'webhook.test');
        $eventId = 'test_' . \Illuminate\Support\Str::uuid();
        
        // Create test payload
        $testPayload = [
            'id' => $eventId,
            'type' => $eventType,
            'created' => now()->getTimestamp(),
            'data' => [
                'object' => [
                    'id' => 'test_object_123',
                    'type' => 'test',
                    'message' => 'This is a test webhook delivery',
                    'webhook_id' => $webhook->public_id,
                ]
            ],
            'test' => true,
        ];

        // Create delivery record
        $delivery = ApiWebhookDelivery::createForWebhook(
            $webhook,
            $eventType,
            $eventId,
            $testPayload
        );

        // Queue for processing
        ProcessWebhookDelivery::dispatch($delivery->id);

        return ApiResponse::item([
            'message' => 'Test webhook queued for delivery',
            'delivery' => [
                'id' => $delivery->public_id,
                'delivery_id' => $delivery->delivery_id,
                'event_type' => $eventType,
                'status' => 'pending',
            ],
        ]);
    }

    /**
     * Transform webhook for API response
     */
    private function transformWebhook(ApiWebhook $webhook, bool $includeStats = false): array
    {
        $data = [
            'id' => $webhook->public_id,
            'name' => $webhook->name,
            'url' => $webhook->url,
            'events' => $webhook->events,
            'content_type' => $webhook->content_type,
            'custom_headers' => $webhook->custom_headers ?? [],
            'timeout_seconds' => $webhook->timeout_seconds,
            'filters' => $webhook->filters ?? [],
            'is_active' => $webhook->is_active,
            'is_healthy' => $webhook->is_healthy,
            'created_at' => $webhook->created_at->toIso8601String(),
            'updated_at' => $webhook->updated_at->toIso8601String(),
        ];

        if ($includeStats) {
            $data['statistics'] = [
                'total_deliveries' => $webhook->total_deliveries,
                'successful_deliveries' => $webhook->successful_deliveries,
                'failed_deliveries' => $webhook->failed_deliveries,
                'success_rate' => $webhook->getSuccessRate(),
                'last_delivery_at' => $webhook->last_delivery_at?->toIso8601String(),
                'last_successful_delivery_at' => $webhook->last_successful_delivery_at?->toIso8601String(),
            ];

            if ($webhook->relationLoaded('recentDeliveries')) {
                $data['recent_deliveries'] = $webhook->recentDeliveries->map(function ($delivery) {
                    return [
                        'id' => $delivery->public_id,
                        'event_type' => $delivery->event_type,
                        'status' => $delivery->status,
                        'response_status' => $delivery->response_status,
                        'created_at' => $delivery->created_at->toIso8601String(),
                    ];
                })->toArray();
            }
        }

        return $data;
    }

    /**
     * Get available webhook events
     */
    private function getAvailableEvents(): array
    {
        return [
            'order.created',
            'order.updated', 
            'order.cancelled',
            'order.fulfilled',
            'product.created',
            'product.updated',
            'product.deleted',
            'customer.created',
            'customer.updated',
            'invoice.created',
            'invoice.sent',
            'invoice.paid',
            'payment.succeeded',
            'payment.failed',
            'inventory.updated',
            'webhook.test',
        ];
    }

    /**
     * Get current business from tenant context
     */
    private function getCurrentBusiness()
    {
        return app(\App\Domain\Tenancy\TenantContext::class)->business();
    }

    /**
     * Get current API key from request
     */
    private function getCurrentApiKey()
    {
        return request()->attributes->get('api_key');
    }
}