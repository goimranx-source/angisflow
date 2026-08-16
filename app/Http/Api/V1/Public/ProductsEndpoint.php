<?php

declare(strict_types=1);

namespace App\Http\Api\V1\Public;

use App\Http\Api\Endpoint;
use App\Http\Api\ApiResponse;
use App\Domain\Catalogue\ProductCatalogue;
use App\Domain\Catalogue\Models\Product;
use App\Domain\Catalogue\Models\ProductVariant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public API Products Endpoint
 *
 * Provides external access to product catalog operations.
 * Allows third-party systems to manage products and variants.
 */
class ProductsEndpoint extends Endpoint
{
    public function __construct(
        private ProductCatalogue $productCatalogue
    ) {}

    /**
     * List products
     */
    public function index(Request $request): JsonResponse
    {
        $this->validate($request, [
            'category_id' => 'sometimes|string|exists:product_categories,public_id',
            'status' => 'sometimes|in:active,inactive,draft',
            'type' => 'sometimes|in:simple,variant',
            'sku' => 'sometimes|string',
            'search' => 'sometimes|string|max:255',
            'limit' => 'sometimes|integer|min:1|max:100',
            'page' => 'sometimes|integer|min:1',
            'include' => 'sometimes|string', // variants,category,stock
        ]);

        // This is the public catalogue — raw materials are stocked products a
        // buyer must never see, so the kind filter belongs here rather than in
        // each caller.
        $query = Product::where('business_id', $this->getCurrentBusiness()->id)->sellable();

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->filled('sku')) {
            $query->where('sku', 'like', '%' . $request->sku . '%');
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%")
                  ->orWhere('sku', 'like', "%{$search}%");
            });
        }

        // Handle includes
        $includes = $this->parseIncludes($request->get('include', ''));
        if (in_array('variants', $includes)) {
            $query->with('variants');
        }
        if (in_array('category', $includes)) {
            $query->with('category');
        }
        if (in_array('stock', $includes)) {
            $query->with('stockBatches');
        }

        $products = $query->orderBy('name')
            ->paginate($request->get('limit', 25));

        return ApiResponse::paginated($products, function ($product) use ($includes) {
            return $this->transformProduct($product, $includes);
        });
    }

    /**
     * Create product
     */
    public function store(Request $request): JsonResponse
    {
        $this->validate($request, [
            'name' => 'required|string|max:255',
            'description' => 'sometimes|string',
            'sku' => 'sometimes|string|max:100|unique:products,sku,NULL,id,business_id,' . $this->getCurrentBusiness()->id,
            'type' => 'sometimes|in:simple,variant',
            'category_id' => 'sometimes|string|exists:product_categories,public_id',
            'price' => 'sometimes|numeric|min:0',
            'cost_price' => 'sometimes|numeric|min:0',
            'weight' => 'sometimes|numeric|min:0',
            'dimensions' => 'sometimes|array',
            'track_inventory' => 'sometimes|boolean',
            'status' => 'sometimes|in:active,inactive,draft',
            'tags' => 'sometimes|array',
            'metadata' => 'sometimes|array',
            'variants' => 'sometimes|array',
            'variants.*.name' => 'required_with:variants|string|max:255',
            'variants.*.sku' => 'sometimes|string|max:100',
            'variants.*.price' => 'sometimes|numeric|min:0',
            'variants.*.cost_price' => 'sometimes|numeric|min:0',
            'variants.*.attributes' => 'sometimes|array',
        ]);

        $productData = [
            'name' => $request->name,
            'description' => $request->get('description'),
            'sku' => $request->get('sku'),
            'type' => $request->get('type', 'simple'),
            'status' => $request->get('status', 'active'),
            'tags' => $request->get('tags', []),
            'metadata' => $request->get('metadata', []),
            'track_inventory' => $request->get('track_inventory', true),
        ];

        // Handle pricing
        if ($request->filled('price')) {
            $productData['price'] = app(\App\Domain\Shared\ValueObjects\Money::class)
                ->fromDecimal((string) $request->price, $this->getCurrentBusiness()->base_currency);
        }

        if ($request->filled('cost_price')) {
            $productData['cost_price'] = app(\App\Domain\Shared\ValueObjects\Money::class)
                ->fromDecimal((string) $request->cost_price, $this->getCurrentBusiness()->base_currency);
        }

        // Handle dimensions and weight
        if ($request->filled('weight')) {
            $productData['weight'] = (float) $request->weight;
        }

        if ($request->filled('dimensions')) {
            $productData['dimensions'] = $request->dimensions;
        }

        $variants = $request->get('variants', []);
        $product = $this->productCatalogue->create($productData, $variants);

        return ApiResponse::item($this->transformProduct($product, ['variants']), 201);
    }

    /**
     * Show product
     */
    public function show(string $id, Request $request): JsonResponse
    {
        $this->validate($request, [
            'include' => 'sometimes|string',
        ]);

        $product = Product::where('business_id', $this->getCurrentBusiness()->id)
            ->where('public_id', $id)
            ->firstOrFail();

        $includes = $this->parseIncludes($request->get('include', 'variants,stock'));

        if (in_array('variants', $includes)) {
            $product->load('variants');
        }
        if (in_array('category', $includes)) {
            $product->load('category');
        }
        if (in_array('stock', $includes)) {
            $product->load('stockBatches');
        }

        return ApiResponse::item($this->transformProduct($product, $includes));
    }

    /**
     * Update product
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $this->validate($request, [
            'name' => 'sometimes|string|max:255',
            'description' => 'sometimes|string',
            'price' => 'sometimes|numeric|min:0',
            'cost_price' => 'sometimes|numeric|min:0',
            'status' => 'sometimes|in:active,inactive,draft',
            'tags' => 'sometimes|array',
            'metadata' => 'sometimes|array',
            'track_inventory' => 'sometimes|boolean',
        ]);

        $product = Product::where('business_id', $this->getCurrentBusiness()->id)
            ->where('public_id', $id)
            ->firstOrFail();

        $updateData = $request->only(['name', 'description', 'status', 'tags', 'metadata', 'track_inventory']);

        // Handle pricing updates
        if ($request->filled('price')) {
            $updateData['price'] = app(\App\Domain\Shared\ValueObjects\Money::class)
                ->fromDecimal((string) $request->price, $this->getCurrentBusiness()->base_currency);
        }

        if ($request->filled('cost_price')) {
            $updateData['cost_price'] = app(\App\Domain\Shared\ValueObjects\Money::class)
                ->fromDecimal((string) $request->cost_price, $this->getCurrentBusiness()->base_currency);
        }

        $product = $this->productCatalogue->update($product->id, $updateData);

        return ApiResponse::item($this->transformProduct($product, ['variants']));
    }

    /**
     * Delete product
     */
    public function destroy(string $id): JsonResponse
    {
        $product = Product::where('business_id', $this->getCurrentBusiness()->id)
            ->where('public_id', $id)
            ->firstOrFail();

        $this->productCatalogue->archive($product->id);

        return ApiResponse::item(['message' => 'Product archived successfully']);
    }

    /**
     * Get product variants
     */
    public function variants(string $id): JsonResponse
    {
        $product = Product::where('business_id', $this->getCurrentBusiness()->id)
            ->where('public_id', $id)
            ->with('variants')
            ->firstOrFail();

        if ($product->type !== 'variant') {
            return response()->json([
                'error' => 'Product does not have variants',
                'code' => 'NO_VARIANTS',
            ], 422);
        }

        $variants = $product->variants->map(function ($variant) {
            return [
                'id' => $variant->public_id,
                'name' => $variant->name,
                'sku' => $variant->sku,
                'price' => $variant->price?->toDecimalString(),
                'cost_price' => $variant->cost_price?->toDecimalString(),
                'status' => $variant->status,
                'attributes' => $variant->attributes ?? [],
                'inventory' => $variant->track_inventory ? [
                    'available' => $variant->available_quantity,
                    'committed' => $variant->committed_quantity,
                    'on_hand' => $variant->on_hand_quantity,
                ] : null,
            ];
        });

        return ApiResponse::collection($variants);
    }

    /**
     * Get product stock levels
     */
    public function stock(string $id): JsonResponse
    {
        $product = Product::where('business_id', $this->getCurrentBusiness()->id)
            ->where('public_id', $id)
            ->with(['stockBatches' => function ($query) {
                $query->where('available_quantity', '>', 0);
            }])
            ->firstOrFail();

        if (!$product->track_inventory) {
            return response()->json([
                'error' => 'Product inventory is not tracked',
                'code' => 'INVENTORY_NOT_TRACKED',
            ], 422);
        }

        $stockData = [
            'product_id' => $product->public_id,
            'total_available' => $product->available_quantity,
            'total_committed' => $product->committed_quantity,
            'total_on_hand' => $product->on_hand_quantity,
            'batches' => $product->stockBatches->map(function ($batch) {
                return [
                    'id' => $batch->public_id,
                    'batch_number' => $batch->batch_number,
                    'available_quantity' => (float) $batch->available_quantity,
                    'cost_per_unit' => $batch->cost_per_unit?->toDecimalString(),
                    'expiry_date' => $batch->expiry_date?->format('Y-m-d'),
                    'location' => $batch->location?->name,
                ];
            }),
        ];

        return ApiResponse::item($stockData);
    }

    /**
     * Transform product for API response
     */
    private function transformProduct(Product $product, array $includes = []): array
    {
        $data = [
            'id' => $product->public_id,
            'name' => $product->name,
            'description' => $product->description,
            'sku' => $product->sku,
            'type' => $product->type,
            'status' => $product->status,
            'price' => $product->price?->toDecimalString(),
            'cost_price' => $product->cost_price?->toDecimalString(),
            'currency' => $product->currency,
            'weight' => $product->weight,
            'dimensions' => $product->dimensions,
            'track_inventory' => $product->track_inventory,
            'tags' => $product->tags ?? [],
            'metadata' => $product->metadata ?? [],
            'created_at' => $product->created_at->toIso8601String(),
            'updated_at' => $product->updated_at->toIso8601String(),
        ];

        if (in_array('variants', $includes) && $product->relationLoaded('variants')) {
            $data['variants'] = $product->variants->map(function ($variant) {
                return [
                    'id' => $variant->public_id,
                    'name' => $variant->name,
                    'sku' => $variant->sku,
                    'price' => $variant->price?->toDecimalString(),
                    'cost_price' => $variant->cost_price?->toDecimalString(),
                    'status' => $variant->status,
                    'attributes' => $variant->attributes ?? [],
                ];
            })->toArray();
        }

        if (in_array('category', $includes) && $product->relationLoaded('category') && $product->category) {
            $data['category'] = [
                'id' => $product->category->public_id,
                'name' => $product->category->name,
                'slug' => $product->category->slug,
            ];
        }

        if (in_array('stock', $includes) && $product->track_inventory) {
            $data['inventory'] = [
                'available' => $product->available_quantity,
                'committed' => $product->committed_quantity,
                'on_hand' => $product->on_hand_quantity,
            ];
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