<?php

declare(strict_types=1);

namespace App\Domain\Storefront;

use App\Domain\Catalogue\Models\Product;
use App\Domain\Catalogue\Models\ProductCategory;
use App\Domain\Catalogue\Models\ProductVariant;
use App\Domain\Sales\CustomerDirectory;
use App\Domain\Sales\Models\Customer;
use App\Domain\Sales\Models\Order;
use App\Domain\Sales\OrderService;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Tenancy\TenantContext;
use App\Models\Storefront;
use App\Models\StorefrontPage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Storefront Service
 *
 * Manages public-facing storefronts including product display,
 * cart management, and order processing for external customers.
 */
class StorefrontService
{
    public function __construct(
        private TenantContext $tenantContext,
        private OrderService $orderService,
        private CustomerDirectory $customerDirectory
    ) {}

    // ── Storefront Management ────────────────────────────────────────────────

    /**
     * Create a new storefront
     */
    public function createStorefront(array $data): Storefront
    {
        return DB::transaction(function () use ($data) {
            // account_id / business_id are stamped by Storefront's
            // BelongsToAccount / BelongsToBusiness traits from TenantContext;
            // neither is in $fillable, so passing them here would throw under
            // Model::preventSilentlyDiscardingAttributes().
            $storefront = Storefront::create([
                'name' => $data['name'],
                'slug' => $data['slug'] ?? Str::slug($data['name']),

                // What kind of shop and what state it is in. Defaulted rather
                // than required, so every existing caller keeps working.
                'type' => $data['type'] ?? 'main',
                'status' => $data['status'] ?? 'active',
                'title' => $data['title'] ?? $data['name'],
                'description' => $data['description'] ?? null,
                'settings' => $data['settings'] ?? [],
                'custom_domain' => $data['custom_domain'] ?? null,
                'ssl_enabled' => $data['ssl_enabled'] ?? true,
                'seo_config' => $data['seo_config'] ?? [],
                'is_active' => $data['is_active'] ?? true,
                'allow_guest_checkout' => $data['allow_guest_checkout'] ?? true,
                'require_account' => $data['require_account'] ?? false,
                'show_inventory_levels' => $data['show_inventory_levels'] ?? false,
                'enable_reviews' => $data['enable_reviews'] ?? true,
                'enable_wishlist' => $data['enable_wishlist'] ?? true,
                'minimum_order_amount_minor' => $data['minimum_order_amount_minor'] ?? null,
                'currency' => $data['currency'] ?? $this->tenantContext->business()->base_currency,
                'shipping_zones' => $data['shipping_zones'] ?? null,
                'payment_methods' => $data['payment_methods'] ?? ['stripe'],
                'tax_settings' => $data['tax_settings'] ?? null,
                'theme_template' => $data['theme_template'] ?? 'default',
                'theme_config' => $data['theme_config'] ?? null,
                'header_config' => $data['header_config'] ?? null,
                'footer_config' => $data['footer_config'] ?? null,
                'enable_caching' => $data['enable_caching'] ?? true,
                'featured_products' => $data['featured_products'] ?? null,
                'collections' => $data['collections'] ?? null,
                'announcement_bar' => $data['announcement_bar'] ?? null,
            ]);

            // Create default pages
            $this->createDefaultPages($storefront);

            return $storefront;
        });
    }

    /**
     * Update storefront configuration
     */
    public function updateStorefront(int $storefrontId, array $data): Storefront
    {
        $storefront = Storefront::findOrFail($storefrontId);
        $storefront->update($data);

        return $storefront;
    }

    /**
     * Find storefront by domain or slug
     */
    public function findStorefront(string $identifier): ?Storefront
    {
        // Try custom domain first
        $storefront = Storefront::active()
            ->where('custom_domain', $identifier)
            ->first();

        if (! $storefront) {
            // Try slug
            $storefront = Storefront::active()
                ->where('slug', $identifier)
                ->first();
        }

        return $storefront;
    }

    // ── Product Catalog for Storefront ───────────────────────────────────────

    /**
     * Get products for storefront display
     */
    public function getProducts(
        Storefront $storefront,
        array $filters = [],
        int $page = 1,
        int $perPage = 12
    ): array {
        // sellable(), not just active: raw materials are active products that
        // are bought and counted but never sold. Without this the flour a
        // bakery stocks appears on its shop front next to the bread.
        $query = Product::where('business_id', $storefront->business_id)
            ->where('is_active', true)
            ->sellable()
            ->with(['variants', 'media', 'category']);

        // Apply filters
        if (! empty($filters['category'])) {
            $query->where('category_id', $filters['category']);
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Price lives on the variant (ProductVariant.price_minor), never on the
        // product itself — see Product::priceRange(). A product qualifies for a
        // price filter if any of its active variants falls in range, the same
        // "from / to" idea the product page uses to show a single price.
        if (! empty($filters['price_min'])) {
            $priceMinMinor = (int) round($filters['price_min'] * 100);
            $query->whereHas('variants', function ($q) use ($priceMinMinor) {
                $q->where('is_active', true)->where('price_minor', '>=', $priceMinMinor);
            });
        }

        if (! empty($filters['price_max'])) {
            $priceMaxMinor = (int) round($filters['price_max'] * 100);
            $query->whereHas('variants', function ($q) use ($priceMaxMinor) {
                $q->where('is_active', true)->where('price_minor', '<=', $priceMaxMinor);
            });
        }

        if (! empty($filters['in_stock']) && $storefront->show_inventory_levels) {
            $query->where('available_quantity', '>', 0);
        }

        // Apply sorting. Price sorts use the cheapest (or dearest) active
        // variant's price via a correlated subquery — a product has no price
        // column to order by directly.
        $sort = $filters['sort'] ?? 'name';
        switch ($sort) {
            case 'price_low':
                $query->orderBy(
                    ProductVariant::selectRaw('MIN(price_minor)')
                        ->whereColumn('product_id', 'products.id')
                        ->where('is_active', true),
                    'asc'
                );
                break;
            case 'price_high':
                $query->orderBy(
                    ProductVariant::selectRaw('MAX(price_minor)')
                        ->whereColumn('product_id', 'products.id')
                        ->where('is_active', true),
                    'desc'
                );
                break;
            case 'newest':
                $query->orderBy('created_at', 'desc');
                break;
            case 'rating':
                $query->orderByDesc('average_rating');
                break;
            default:
                $query->orderBy('name');
        }

        $products = $query->paginate($perPage, ['*'], 'page', $page);

        return [
            'products' => $products->items(),
            'pagination' => [
                'current_page' => $products->currentPage(),
                'total_pages' => $products->lastPage(),
                'per_page' => $products->perPage(),
                'total_items' => $products->total(),
                'has_more' => $products->hasMorePages(),
            ],
        ];
    }

    /**
     * Get product categories for storefront
     */
    public function getCategories(Storefront $storefront): Collection
    {
        return ProductCategory::where('business_id', $storefront->business_id)
            ->whereHas('products', function ($query) {
                $query->where('status', 'active');
            })
            ->withCount(['products' => function ($query) {
                $query->where('status', 'active');
            }])
            ->orderBy('name')
            ->get();
    }

    /**
     * Get product details for storefront display
     */
    public function getProductDetails(Storefront $storefront, string $productId): ?Product
    {
        $product = Product::where('business_id', $storefront->business_id)
            ->where('public_id', $productId)
            ->where('status', 'active')
            ->with([
                'variants',
                'media',
                'category',
                'reviews' => function ($query) {
                    $query->approved()->with('customer');
                },
            ])
            ->first();

        if (! $product) {
            return null;
        }

        // Load related products
        $product->loadMissing([
            'relatedProducts' => function ($query) {
                $query->where('status', 'active')->limit(4);
            },
        ]);

        return $product;
    }

    // ── Shopping Cart Management ──────────────────────────────────────────────

    /**
     * Add item to cart session
     */
    public function addToCart(array $cartData, array $item): array
    {
        $productId = $item['product_id'];
        $variantId = $item['variant_id'] ?? null;
        $quantity = (int) $item['quantity'];

        // Validate product exists and is available
        $product = Product::where('public_id', $productId)
            ->where('is_active', true)
            ->with('variants')
            ->first();

        if (! $product) {
            throw new \InvalidArgumentException('Product not found or not available');
        }

        // Price and SKU live on the variant, never on the product — see
        // Product::priceRange(). Fall back to the product's default variant
        // when none was requested explicitly.
        $variant = $variantId
            ? $product->variants->firstWhere('public_id', $variantId)
            : $product->defaultVariant();

        if (! $variant) {
            throw new \InvalidArgumentException('This product has nothing to sell yet — no variant is set up for it.');
        }

        // Stock availability is not wired into the storefront cart yet — that
        // belongs to App\Domain\Stock\StockService, which the cart does not
        // currently consult. Left as a known gap rather than checking a
        // column (`available_quantity`) that does not exist on the model.

        $price = $variant->price(); // Money, minor units + currency

        // Initialize cart if empty
        if (empty($cartData)) {
            $cartData = [
                'items' => [],
                'totals' => [
                    'subtotal_minor' => 0,
                    'tax_minor' => 0,
                    'shipping_minor' => 0,
                    'total_minor' => 0,
                ],
                'currency' => $price->currency,
            ];
        }

        // Create cart item key
        $itemKey = $productId.($variantId ? ":{$variantId}" : '');

        // Check if item already exists in cart
        $existingIndex = null;
        foreach ($cartData['items'] as $index => $cartItem) {
            if ($cartItem['key'] === $itemKey) {
                $existingIndex = $index;
                break;
            }
        }

        if ($existingIndex !== null) {
            // Update existing item — quantity grows, line total is
            // recomputed from the (possibly since-changed) unit price rather
            // than scaled, so a price change is picked up immediately.
            $newQuantity = $cartData['items'][$existingIndex]['quantity'] + $quantity;
            $cartData['items'][$existingIndex]['quantity'] = $newQuantity;
            $cartData['items'][$existingIndex]['line_total_minor'] = $price->times($newQuantity)->minor;
        } else {
            // Add new item
            $cartData['items'][] = [
                'key' => $itemKey,
                'product_id' => $productId,
                'variant_id' => $variantId,
                'product_name' => $product->name,
                'variant_name' => $variant->name,
                'sku' => $variant->sku,
                'quantity' => $quantity,
                'currency' => $price->currency,
                'unit_price_minor' => $price->minor,
                'line_total_minor' => $price->times($quantity)->minor,
                // Media lookup is not wired into the cart yet — see
                // Product::media() / ProductMedia for the relation this
                // would need to resolve through.
                'image_url' => null,
            ];
        }

        // Recalculate totals
        return $this->recalculateCartTotals($cartData);
    }

    /**
     * Update cart item quantity
     */
    public function updateCartItem(array $cartData, string $itemKey, int $quantity): array
    {
        foreach ($cartData['items'] as $index => $item) {
            if ($item['key'] === $itemKey) {
                if ($quantity <= 0) {
                    // Remove item
                    unset($cartData['items'][$index]);
                    $cartData['items'] = array_values($cartData['items']);
                } else {
                    // Update quantity — line total recomputed as an integer
                    // minor-unit multiplication via Money, never as float
                    // arithmetic on a formatted decimal string.
                    $unitPrice = new Money($item['unit_price_minor'], $item['currency']);
                    $cartData['items'][$index]['quantity'] = $quantity;
                    $cartData['items'][$index]['line_total_minor'] = $unitPrice->times($quantity)->minor;
                }
                break;
            }
        }

        return $this->recalculateCartTotals($cartData);
    }

    /**
     * Remove item from cart
     */
    public function removeFromCart(array $cartData, string $itemKey): array
    {
        foreach ($cartData['items'] as $index => $item) {
            if ($item['key'] === $itemKey) {
                unset($cartData['items'][$index]);
                $cartData['items'] = array_values($cartData['items']);
                break;
            }
        }

        return $this->recalculateCartTotals($cartData);
    }

    /**
     * Clear entire cart
     */
    public function clearCart(): array
    {
        return [
            'items' => [],
            'totals' => [
                'subtotal_minor' => 0,
                'tax_minor' => 0,
                'shipping_minor' => 0,
                'total_minor' => 0,
            ],
        ];
    }

    /**
     * Recalculate cart totals.
     *
     * Sums line totals as Money — integer minor-unit addition — rather than
     * floatval()/number_format() on formatted strings, which is where the
     * previous version lost or gained fractions of a cent across repeated
     * add/update calls (see OrderService/PointOfSale for the same pattern
     * applied to a real order).
     */
    private function recalculateCartTotals(array $cartData): array
    {
        $currency = $cartData['currency'] ?? 'USD';
        $subtotal = Money::zero($currency);

        foreach ($cartData['items'] as $item) {
            $subtotal = $subtotal->plus(new Money($item['line_total_minor'], $item['currency'] ?? $currency));
        }

        // TODO: Calculate tax and shipping based on storefront settings
        $tax = Money::zero($currency);
        $shipping = Money::zero($currency);
        $total = $subtotal->plus($tax)->plus($shipping);

        $cartData['totals'] = [
            'subtotal' => $subtotal->jsonSerialize(),
            'tax' => $tax->jsonSerialize(),
            'shipping' => $shipping->jsonSerialize(),
            'total' => $total->jsonSerialize(),
        ];

        return $cartData;
    }

    // ── Checkout and Order Processing ─────────────────────────────────────────

    /**
     * Process storefront checkout
     */
    public function processCheckout(Storefront $storefront, array $checkoutData): Order
    {
        return DB::transaction(function () use ($storefront, $checkoutData) {
            // Validate storefront rules
            $validationErrors = $storefront->validateOrder($checkoutData);
            if (! empty($validationErrors)) {
                throw new \InvalidArgumentException(implode(', ', $validationErrors));
            }

            // Find or create customer
            $customer = null;
            if (! empty($checkoutData['customer_id'])) {
                $customer = Customer::findOrFail($checkoutData['customer_id']);
            } elseif (! $storefront->allow_guest_checkout) {
                throw new \InvalidArgumentException('Account required for checkout');
            } else {
                // Create guest customer if email provided
                if (! empty($checkoutData['customer_email'])) {
                    $customer = $this->customerDirectory->findOrCreateByEmail(
                        $checkoutData['customer_email'],
                        [
                            'name' => $checkoutData['customer_name'] ?? 'Guest Customer',
                            'phone' => $checkoutData['customer_phone'] ?? null,
                        ]
                    );
                }
            }

            // Prepare order data
            $orderData = [
                'customer_id' => $customer?->id,
                'channel' => 'storefront',
                'source_reference' => $storefront->public_id,
                'notes' => $checkoutData['notes'] ?? null,
                'shipping_address' => $checkoutData['shipping_address'] ?? null,
                'billing_address' => $checkoutData['billing_address'] ?? $checkoutData['shipping_address'] ?? null,
                'metadata' => [
                    'storefront_id' => $storefront->public_id,
                    'storefront_name' => $storefront->name,
                ],
            ];

            // Transform cart items to order items
            $items = [];
            foreach ($checkoutData['items'] as $item) {
                $product = Product::where('business_id', $storefront->business_id)
                    ->where('public_id', $item['product_id'])
                    ->firstOrFail();

                $variant = null;
                if (! empty($item['variant_id'])) {
                    $variant = $product->variants()
                        ->where('public_id', $item['variant_id'])
                        ->firstOrFail();
                }

                $items[] = [
                    'product_id' => $product->id,
                    'variant_id' => $variant?->id,
                    'quantity' => (float) $item['quantity'],
                    'unit_price' => $variant?->price ?? $product->price,
                ];
            }

            // Create the order
            $order = $this->orderService->create($orderData, $items);

            return $order;
        });
    }

    // ── Page Management ───────────────────────────────────────────────────────

    /**
     * Create default pages for a storefront
     */
    private function createDefaultPages(Storefront $storefront): void
    {
        $defaultPages = [
            [
                'title' => 'About Us',
                'slug' => 'about-us',
                'type' => 'page',
                'content' => '<p>Tell customers about your business story and values.</p>',
                'show_in_navigation' => true,
                'sort_order' => 1,
            ],
            [
                'title' => 'Shipping Policy',
                'slug' => 'shipping-policy',
                'type' => 'policy',
                'content' => '<p>Describe your shipping methods, costs, and timeframes.</p>',
                'show_in_navigation' => true,
                'sort_order' => 2,
            ],
            [
                'title' => 'Return Policy',
                'slug' => 'return-policy',
                'type' => 'policy',
                'content' => '<p>Explain your return and refund policies.</p>',
                'show_in_navigation' => true,
                'sort_order' => 3,
            ],
            [
                'title' => 'Privacy Policy',
                'slug' => 'privacy-policy',
                'type' => 'policy',
                'content' => '<p>Detail how you collect, use, and protect customer data.</p>',
                'show_in_navigation' => false,
                'sort_order' => 4,
            ],
            [
                'title' => 'Terms of Service',
                'slug' => 'terms-of-service',
                'type' => 'policy',
                'content' => '<p>Outline the terms and conditions for using your store.</p>',
                'show_in_navigation' => false,
                'sort_order' => 5,
            ],
        ];

        foreach ($defaultPages as $pageData) {
            $storefront->pages()->create($pageData);
        }
    }

    /**
     * Create or update storefront page
     */
    public function savePage(Storefront $storefront, array $pageData): StorefrontPage
    {
        if (! empty($pageData['id'])) {
            $page = $storefront->pages()->where('public_id', $pageData['id'])->firstOrFail();
            $page->update($pageData);
        } else {
            $page = $storefront->pages()->create($pageData);
        }

        return $page;
    }

    /**
     * Get storefront navigation pages
     */
    public function getNavigationPages(Storefront $storefront): Collection
    {
        return $storefront->pages()
            ->published()
            ->inNavigation()
            ->get();
    }

    // ── Search and Recommendations ────────────────────────────────────────────

    /**
     * Search products in storefront
     */
    public function searchProducts(Storefront $storefront, string $query, int $limit = 10): Collection
    {
        return Product::where('business_id', $storefront->business_id)
            ->where('status', 'active')
            ->sellable()
            ->where(function ($q) use ($query) {
                $q->where('name', 'like', "%{$query}%")
                    ->orWhere('description', 'like', "%{$query}%")
                    ->orWhere('sku', 'like', "%{$query}%");
            })
            ->with(['media', 'category'])
            ->limit($limit)
            ->get();
    }

    /**
     * Get recommended products for a customer
     */
    public function getRecommendedProducts(Storefront $storefront, ?Customer $customer = null, int $limit = 4): Collection
    {
        // Simple recommendation based on popular products
        // TODO: Implement more sophisticated recommendations based on customer behavior

        return Product::where('business_id', $storefront->business_id)
            ->where('status', 'active')
            ->sellable()
            ->whereNotNull('average_rating')
            ->orderByDesc('average_rating')
            ->orderByDesc('review_count')
            ->with(['media', 'category'])
            ->limit($limit)
            ->get();
    }
}
