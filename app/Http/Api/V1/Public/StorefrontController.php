<?php

declare(strict_types=1);

namespace App\Http\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Domain\Storefront\StorefrontService;
use App\Models\Storefront;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public Storefront Controller
 *
 * Handles public-facing storefront requests including product display,
 * shopping cart operations, and checkout processing.
 */
class StorefrontController extends Controller
{
    public function __construct(
        private StorefrontService $storefrontService
    ) {}

    /**
     * Get storefront configuration and initial data
     */
    public function getStorefront(Request $request, string $identifier): JsonResponse
    {
        $storefront = $this->storefrontService->findStorefront($identifier);
        
        if (!$storefront) {
            return response()->json(['error' => 'Storefront not found'], 404);
        }

        return response()->json([
            'storefront' => [
                'id' => $storefront->public_id,
                'name' => $storefront->name,
                'title' => $storefront->title,
                'description' => $storefront->description,
                'url' => $storefront->getUrl(),
                'theme' => $storefront->getThemeConfig(),
                'seo' => $storefront->getSeoConfig(),
                'settings' => [
                    'allow_guest_checkout' => $storefront->allow_guest_checkout,
                    'require_account' => $storefront->require_account,
                    'show_inventory_levels' => $storefront->show_inventory_levels,
                    'enable_reviews' => $storefront->enable_reviews,
                    'enable_wishlist' => $storefront->enable_wishlist,
                    'minimum_order_amount' => $storefront->minimum_order_amount,
                ],
                'payment_methods' => $storefront->getPaymentMethods(),
                'announcement_bar' => $storefront->announcement_bar,
            ],
            'navigation' => $this->storefrontService->getNavigationPages($storefront),
            'categories' => $this->storefrontService->getCategories($storefront),
        ]);
    }

    /**
     * Get products for storefront
     */
    public function getProducts(Request $request, string $identifier): JsonResponse
    {
        $storefront = $this->storefrontService->findStorefront($identifier);
        
        if (!$storefront) {
            return response()->json(['error' => 'Storefront not found'], 404);
        }

        $this->validate($request, [
            'category' => 'sometimes|integer',
            'search' => 'sometimes|string|max:255',
            'price_min' => 'sometimes|numeric|min:0',
            'price_max' => 'sometimes|numeric|min:0',
            'sort' => 'sometimes|in:name,price_low,price_high,newest,rating',
            'in_stock' => 'sometimes|boolean',
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:50',
        ]);

        $result = $this->storefrontService->getProducts(
            $storefront,
            $request->only(['category', 'search', 'price_min', 'price_max', 'sort', 'in_stock']),
            $request->get('page', 1),
            $request->get('per_page', 12)
        );

        return response()->json($result);
    }

    /**
     * Get product details
     */
    public function getProduct(Request $request, string $identifier, string $productId): JsonResponse
    {
        $storefront = $this->storefrontService->findStorefront($identifier);
        
        if (!$storefront) {
            return response()->json(['error' => 'Storefront not found'], 404);
        }

        $product = $this->storefrontService->getProductDetails($storefront, $productId);
        
        if (!$product) {
            return response()->json(['error' => 'Product not found'], 404);
        }

        return response()->json([
            'product' => [
                'id' => $product->public_id,
                'name' => $product->name,
                'description' => $product->description,
                'sku' => $product->sku,
                'price' => $product->price?->toDecimalString(),
                'compare_price' => $product->compare_price?->toDecimalString(),
                'currency' => $product->currency,
                'images' => $product->getImageUrls(),
                'variants' => $product->variants->map(function ($variant) {
                    return [
                        'id' => $variant->public_id,
                        'name' => $variant->name,
                        'sku' => $variant->sku,
                        'price' => $variant->price?->toDecimalString(),
                        'available' => $variant->available_quantity > 0,
                        'attributes' => $variant->attributes,
                    ];
                }),
                'category' => $product->category ? [
                    'id' => $product->category->public_id,
                    'name' => $product->category->name,
                ] : null,
                'inventory' => $storefront->show_inventory_levels ? [
                    'track_inventory' => $product->track_inventory,
                    'available_quantity' => $product->available_quantity,
                    'low_stock_threshold' => $product->low_stock_threshold,
                ] : null,
                'reviews' => $storefront->enable_reviews ? [
                    'average_rating' => $product->average_rating,
                    'review_count' => $product->review_count,
                    'reviews' => $product->reviews->take(5)->map(function ($review) {
                        return [
                            'id' => $review->public_id,
                            'title' => $review->title,
                            'rating' => $review->rating,
                            'review_text' => $review->review_text,
                            'customer_name' => $review->getDisplayCustomerName(),
                            'verified_purchase' => $review->is_verified_purchase,
                            'created_at' => $review->created_at->format('Y-m-d'),
                        ];
                    }),
                ] : null,
                'specifications' => $product->specifications ?? [],
                'tags' => $product->tags ?? [],
            ],
            'recommendations' => $this->storefrontService->getRecommendedProducts($storefront)->map(function ($rec) {
                return [
                    'id' => $rec->public_id,
                    'name' => $rec->name,
                    'price' => $rec->price?->toDecimalString(),
                    'image_url' => $rec->getImageUrl(),
                ];
            }),
        ]);
    }

    /**
     * Search products
     */
    public function searchProducts(Request $request, string $identifier): JsonResponse
    {
        $this->validate($request, [
            'q' => 'required|string|min:2|max:255',
            'limit' => 'sometimes|integer|min:1|max:20',
        ]);

        $storefront = $this->storefrontService->findStorefront($identifier);
        
        if (!$storefront) {
            return response()->json(['error' => 'Storefront not found'], 404);
        }

        $products = $this->storefrontService->searchProducts(
            $storefront,
            $request->q,
            $request->get('limit', 10)
        );

        return response()->json([
            'query' => $request->q,
            'results' => $products->map(function ($product) {
                return [
                    'id' => $product->public_id,
                    'name' => $product->name,
                    'price' => $product->price?->toDecimalString(),
                    'image_url' => $product->getImageUrl(),
                    'category' => $product->category?->name,
                ];
            }),
        ]);
    }

    /**
     * Get storefront page
     */
    public function getPage(Request $request, string $identifier, string $pageSlug): JsonResponse
    {
        $storefront = $this->storefrontService->findStorefront($identifier);
        
        if (!$storefront) {
            return response()->json(['error' => 'Storefront not found'], 404);
        }

        $page = $storefront->pages()
            ->where('slug', $pageSlug)
            ->published()
            ->first();

        if (!$page) {
            return response()->json(['error' => 'Page not found'], 404);
        }

        return response()->json([
            'page' => [
                'id' => $page->public_id,
                'title' => $page->title,
                'slug' => $page->slug,
                'type' => $page->type,
                'content' => $page->content,
                'excerpt' => $page->getExcerpt(),
                'meta_data' => $page->getMetaData(),
                'published_at' => $page->published_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Add item to cart
     */
    public function addToCart(Request $request, string $identifier): JsonResponse
    {
        $this->validate($request, [
            'product_id' => 'required|string',
            'variant_id' => 'nullable|string',
            'quantity' => 'required|integer|min:1',
        ]);

        $storefront = $this->storefrontService->findStorefront($identifier);
        
        if (!$storefront) {
            return response()->json(['error' => 'Storefront not found'], 404);
        }

        try {
            // Get current cart from session
            $cartData = session('cart', []);

            $updatedCart = $this->storefrontService->addToCart($cartData, [
                'product_id' => $request->product_id,
                'variant_id' => $request->variant_id,
                'quantity' => $request->quantity,
            ]);

            // Store updated cart in session
            session(['cart' => $updatedCart]);

            return response()->json([
                'success' => true,
                'cart' => $updatedCart,
                'message' => 'Item added to cart',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'code' => 'CART_ERROR',
            ], 422);
        }
    }

    /**
     * Update cart item
     */
    public function updateCartItem(Request $request, string $identifier): JsonResponse
    {
        $this->validate($request, [
            'item_key' => 'required|string',
            'quantity' => 'required|integer|min:0',
        ]);

        $cartData = session('cart', []);

        $updatedCart = $this->storefrontService->updateCartItem(
            $cartData,
            $request->item_key,
            $request->quantity
        );

        session(['cart' => $updatedCart]);

        return response()->json([
            'success' => true,
            'cart' => $updatedCart,
        ]);
    }

    /**
     * Remove item from cart
     */
    public function removeFromCart(Request $request, string $identifier): JsonResponse
    {
        $this->validate($request, [
            'item_key' => 'required|string',
        ]);

        $cartData = session('cart', []);

        $updatedCart = $this->storefrontService->removeFromCart(
            $cartData,
            $request->item_key
        );

        session(['cart' => $updatedCart]);

        return response()->json([
            'success' => true,
            'cart' => $updatedCart,
        ]);
    }

    /**
     * Get cart contents
     */
    public function getCart(Request $request, string $identifier): JsonResponse
    {
        $cartData = session('cart', []);

        return response()->json([
            'cart' => $cartData,
        ]);
    }

    /**
     * Clear cart
     */
    public function clearCart(Request $request, string $identifier): JsonResponse
    {
        $clearedCart = $this->storefrontService->clearCart();
        session(['cart' => $clearedCart]);

        return response()->json([
            'success' => true,
            'cart' => $clearedCart,
        ]);
    }

    /**
     * Process checkout
     */
    public function checkout(Request $request, string $identifier): JsonResponse
    {
        $this->validate($request, [
            'items' => 'required|array|min:1',
            'customer_email' => 'required|email',
            'customer_name' => 'required|string|max:255',
            'customer_phone' => 'nullable|string|max:50',
            'shipping_address' => 'required|array',
            'billing_address' => 'sometimes|array',
            'payment_method' => 'required|string',
            'notes' => 'sometimes|string|max:1000',
        ]);

        $storefront = $this->storefrontService->findStorefront($identifier);
        
        if (!$storefront) {
            return response()->json(['error' => 'Storefront not found'], 404);
        }

        try {
            $order = $this->storefrontService->processCheckout($storefront, $request->all());

            // Clear cart after successful checkout
            session()->forget('cart');

            return response()->json([
                'success' => true,
                'order' => [
                    'id' => $order->public_id,
                    'order_number' => $order->order_number,
                    'status' => $order->status,
                    'total' => $order->total?->toDecimalString(),
                    'currency' => $order->currency,
                ],
                'message' => 'Order placed successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'code' => 'CHECKOUT_ERROR',
            ], 422);
        }
    }
}