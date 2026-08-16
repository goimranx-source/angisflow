<?php

declare(strict_types=1);

namespace App\Http\Api\V1\Public;

use App\Http\Api\Endpoint;
use App\Http\Api\ApiResponse;
use App\Domain\Sales\OrderService;
use App\Domain\Sales\CustomerDirectory;
use App\Domain\Sales\Models\Order;
use App\Domain\Sales\Models\Customer;
use App\Domain\Catalogue\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public API Orders Endpoint
 *
 * Provides external access to order management operations.
 * Allows third-party systems to create, read, update orders
 * and perform fulfillment operations.
 */
class OrdersEndpoint extends Endpoint
{
    public function __construct(
        private OrderService $orderService,
        private CustomerDirectory $customerDirectory
    ) {}

    /**
     * List orders
     */
    public function index(Request $request): JsonResponse
    {
        $this->validate($request, [
            'customer_id' => 'sometimes|string|exists:customers,public_id',
            'status' => 'sometimes|in:draft,pending,confirmed,processing,shipped,delivered,cancelled,refunded',
            'payment_status' => 'sometimes|in:pending,paid,failed,refunded',
            'created_after' => 'sometimes|date',
            'created_before' => 'sometimes|date|after_or_equal:created_after',
            'limit' => 'sometimes|integer|min:1|max:100',
            'page' => 'sometimes|integer|min:1',
            'include' => 'sometimes|string', // customer,items,payments
        ]);

        $query = Order::where('business_id', $this->getCurrentBusiness()->id);

        if ($request->filled('customer_id')) {
            $customer = Customer::where('public_id', $request->customer_id)->firstOrFail();
            $query->where('customer_id', $customer->id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('payment_status')) {
            $query->where('payment_status', $request->payment_status);
        }

        if ($request->filled('created_after')) {
            $query->where('created_at', '>=', $request->created_after);
        }

        if ($request->filled('created_before')) {
            $query->where('created_at', '<=', $request->created_before);
        }

        // Handle includes
        $includes = $this->parseIncludes($request->get('include', ''));
        if (in_array('customer', $includes)) {
            $query->with('customer');
        }
        if (in_array('items', $includes)) {
            $query->with(['lines.product', 'lines.variant']);
        }
        if (in_array('payments', $includes)) {
            $query->with('payments');
        }

        $orders = $query->orderBy('created_at', 'desc')
            ->paginate($request->get('limit', 25));

        return ApiResponse::paginated($orders, function ($order) use ($includes) {
            return $this->transformOrder($order, $includes);
        });
    }

    /**
     * Create order
     */
    public function store(Request $request): JsonResponse
    {
        $this->validate($request, [
            'customer' => 'required|array',
            'customer.email' => 'required|email',
            'customer.name' => 'sometimes|string|max:255',
            'customer.phone' => 'sometimes|string|max:50',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|string|exists:products,public_id',
            'items.*.variant_id' => 'nullable|string|exists:product_variants,public_id',
            'items.*.quantity' => 'required|numeric|min:0.0001',
            'items.*.unit_price' => 'sometimes|numeric|min:0',
            'shipping_address' => 'sometimes|array',
            'billing_address' => 'sometimes|array',
            'notes' => 'sometimes|string|max:1000',
            'tags' => 'sometimes|array',
            'metadata' => 'sometimes|array',
            'channel' => 'sometimes|string|max:50',
            'external_id' => 'sometimes|string|max:100',
        ]);

        // Find or create customer
        $customer = $this->customerDirectory->findOrCreateByEmail(
            $request->input('customer.email'),
            [
                'name' => $request->input('customer.name'),
                'phone' => $request->input('customer.phone'),
            ]
        );

        // Build order data
        $orderData = [
            'customer_id' => $customer->id,
            'channel' => $request->get('channel', 'api'),
            'notes' => $request->get('notes'),
            'tags' => $request->get('tags', []),
            'shipping_address' => $request->get('shipping_address'),
            'billing_address' => $request->get('billing_address'),
            'metadata' => array_merge(
                $request->get('metadata', []),
                ['external_id' => $request->get('external_id')]
            ),
        ];

        // Transform items
        $items = collect($request->items)->map(function ($item) {
            // sellable() here as well as on the catalogue: hiding a raw
            // material from the listing does not stop anyone posting its id
            // straight to this endpoint, and an order line for a sack of flour
            // would draw down stock that production is counting on.
            $product = Product::where('public_id', $item['product_id'])->sellable()->firstOrFail();

            $variant = null;
            if (!empty($item['variant_id'])) {
                $variant = $product->variants()->where('public_id', $item['variant_id'])->firstOrFail();
            }

            return [
                'product_id' => $product->id,
                'variant_id' => $variant?->id,
                'quantity' => (float) $item['quantity'],
                'unit_price' => isset($item['unit_price']) 
                    ? app(\App\Domain\Shared\ValueObjects\Money::class)->fromDecimal((string) $item['unit_price'], $this->getCurrentBusiness()->base_currency)
                    : null,
            ];
        })->toArray();

        $order = $this->orderService->create($orderData, $items);

        return ApiResponse::item($this->transformOrder($order, ['customer', 'items']), 201);
    }

    /**
     * Show order
     */
    public function show(string $id, Request $request): JsonResponse
    {
        $this->validate($request, [
            'include' => 'sometimes|string',
        ]);

        $order = Order::where('business_id', $this->getCurrentBusiness()->id)
            ->where('public_id', $id)
            ->firstOrFail();

        $includes = $this->parseIncludes($request->get('include', 'customer,items,payments'));

        if (in_array('customer', $includes)) {
            $order->load('customer');
        }
        if (in_array('items', $includes)) {
            $order->load(['lines.product', 'lines.variant']);
        }
        if (in_array('payments', $includes)) {
            $order->load('payments');
        }

        return ApiResponse::item($this->transformOrder($order, $includes));
    }

    /**
     * Update order
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $this->validate($request, [
            'status' => 'sometimes|in:pending,confirmed,processing,cancelled',
            'notes' => 'sometimes|string|max:1000',
            'tags' => 'sometimes|array',
            'shipping_address' => 'sometimes|array',
            'billing_address' => 'sometimes|array',
            'metadata' => 'sometimes|array',
        ]);

        $order = Order::where('business_id', $this->getCurrentBusiness()->id)
            ->where('public_id', $id)
            ->firstOrFail();

        // Only allow updates to orders that aren't shipped/delivered
        if (in_array($order->status, ['shipped', 'delivered'])) {
            return response()->json([
                'error' => 'Cannot update shipped or delivered orders',
                'code' => 'ORDER_IMMUTABLE',
            ], 422);
        }

        $updateData = $request->only(['notes', 'tags', 'shipping_address', 'billing_address', 'metadata']);

        if ($request->filled('status')) {
            $updateData['status'] = $request->status;
        }

        $order = $this->orderService->update($order->id, $updateData);

        return ApiResponse::item($this->transformOrder($order, ['customer', 'items']));
    }

    /**
     * Fulfill order
     */
    public function fulfill(Request $request, string $id): JsonResponse
    {
        $this->validate($request, [
            'items' => 'sometimes|array',
            'items.*.line_id' => 'required_with:items|string',
            'items.*.quantity' => 'required_with:items|numeric|min:0.0001',
            'tracking_number' => 'sometimes|string|max:100',
            'courier' => 'sometimes|string|max:50',
            'notes' => 'sometimes|string|max:500',
            'notify_customer' => 'sometimes|boolean',
        ]);

        $order = Order::where('business_id', $this->getCurrentBusiness()->id)
            ->where('public_id', $id)
            ->with('lines')
            ->firstOrFail();

        if (!in_array($order->status, ['confirmed', 'processing'])) {
            return response()->json([
                'error' => 'Order cannot be fulfilled in current status',
                'code' => 'INVALID_ORDER_STATUS',
                'current_status' => $order->status,
            ], 422);
        }

        // Prepare fulfillment data
        $fulfillmentData = [
            'tracking_number' => $request->get('tracking_number'),
            'courier' => $request->get('courier'),
            'notes' => $request->get('notes'),
            'notify_customer' => $request->get('notify_customer', true),
        ];

        // Handle partial fulfillment
        if ($request->filled('items')) {
            $fulfillmentItems = [];
            foreach ($request->items as $item) {
                $line = $order->lines->where('public_id', $item['line_id'])->first();
                if (!$line) {
                    return response()->json([
                        'error' => "Order line {$item['line_id']} not found",
                        'code' => 'LINE_NOT_FOUND',
                    ], 422);
                }
                
                $fulfillmentItems[] = [
                    'line_id' => $line->id,
                    'quantity' => (float) $item['quantity'],
                ];
            }
            $fulfillmentData['items'] = $fulfillmentItems;
        }

        $order = $this->orderService->fulfill($order->id, $fulfillmentData);

        return ApiResponse::item([
            'order' => $this->transformOrder($order, ['customer', 'items']),
            'message' => 'Order fulfilled successfully',
        ]);
    }

    /**
     * Cancel order
     */
    public function cancel(Request $request, string $id): JsonResponse
    {
        $this->validate($request, [
            'reason' => 'required|string|max:255',
            'refund' => 'sometimes|boolean',
            'notify_customer' => 'sometimes|boolean',
        ]);

        $order = Order::where('business_id', $this->getCurrentBusiness()->id)
            ->where('public_id', $id)
            ->firstOrFail();

        if (!in_array($order->status, ['pending', 'confirmed', 'processing'])) {
            return response()->json([
                'error' => 'Order cannot be cancelled in current status',
                'code' => 'INVALID_ORDER_STATUS',
                'current_status' => $order->status,
            ], 422);
        }

        $cancelData = [
            'reason' => $request->reason,
            'refund' => $request->get('refund', false),
            'notify_customer' => $request->get('notify_customer', true),
        ];

        $order = $this->orderService->cancel($order->id, $cancelData);

        return ApiResponse::item([
            'order' => $this->transformOrder($order, ['customer', 'items']),
            'message' => 'Order cancelled successfully',
        ]);
    }

    /**
     * Transform order for API response
     */
    private function transformOrder(Order $order, array $includes = []): array
    {
        $data = [
            'id' => $order->public_id,
            'number' => $order->order_number,
            'status' => $order->status,
            'payment_status' => $order->payment_status,
            'channel' => $order->channel,
            'subtotal' => $order->subtotal?->toDecimalString(),
            'tax_amount' => $order->tax_amount?->toDecimalString(),
            'shipping_amount' => $order->shipping_amount?->toDecimalString(),
            'discount_amount' => $order->discount_amount?->toDecimalString(),
            'total' => $order->total?->toDecimalString(),
            'currency' => $order->currency,
            'notes' => $order->notes,
            'tags' => $order->tags ?? [],
            'shipping_address' => $order->shipping_address,
            'billing_address' => $order->billing_address,
            'metadata' => $order->metadata ?? [],
            'created_at' => $order->created_at->toIso8601String(),
            'updated_at' => $order->updated_at->toIso8601String(),
        ];

        if (in_array('customer', $includes) && $order->relationLoaded('customer') && $order->customer) {
            $data['customer'] = [
                'id' => $order->customer->public_id,
                'name' => $order->customer->name,
                'email' => $order->customer->email,
                'phone' => $order->customer->phone,
            ];
        }

        if (in_array('items', $includes) && $order->relationLoaded('lines')) {
            $data['items'] = $order->lines->map(function ($line) {
                return [
                    'id' => $line->public_id,
                    'product_id' => $line->product->public_id,
                    'product_name' => $line->product_name,
                    'variant_id' => $line->variant?->public_id,
                    'variant_name' => $line->variant_name,
                    'sku' => $line->sku,
                    'quantity' => (float) $line->quantity,
                    'unit_price' => $line->unit_price?->toDecimalString(),
                    'total_price' => $line->total_price?->toDecimalString(),
                    'fulfillment_status' => $line->fulfillment_status,
                ];
            })->toArray();
        }

        if (in_array('payments', $includes) && $order->relationLoaded('payments')) {
            $data['payments'] = $order->payments->map(function ($payment) {
                return [
                    'id' => $payment->public_id,
                    'amount' => $payment->amount?->toDecimalString(),
                    'method' => $payment->method,
                    'status' => $payment->status,
                    'reference' => $payment->reference,
                    'created_at' => $payment->created_at->toIso8601String(),
                ];
            })->toArray();
        }

        return $data;
    }

    /**
     * Parse include parameter
     */
    private function parseIncludes(string $includes): array
    {
        return array_filter(array_map('trim', explode(',', $includes)));
    }

    /**
     * Get current business from tenant context
     */
    private function getCurrentBusiness()
    {
        return app(\App\Domain\Tenancy\TenantContext::class)->business();
    }
}