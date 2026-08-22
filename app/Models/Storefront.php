<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Media\Models\MediaItem;
use App\Domain\Shared\Concerns\HasPublicId;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Tenancy\Concerns\BelongsToAccount;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Storefront Model
 *
 * Represents a public-facing online store for a business. Each business
 * can have multiple storefronts for different brands, regions, or purposes.
 */
class Storefront extends Model
{
    use BelongsToAccount, BelongsToBusiness, HasPublicId, SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'code',
        'logo_media_id',
        'type',
        'status',
        'title',
        'description',
        'settings',
        'custom_domain',
        'ssl_enabled',
        'seo_config',
        'is_active',
        'allow_guest_checkout',
        'require_account',
        'show_inventory_levels',
        'enable_reviews',
        'enable_wishlist',
        'minimum_order_amount_minor',
        'currency',
        'shipping_zones',
        'payment_methods',
        'tax_settings',
        'theme_template',
        'theme_config',
        'header_config',
        'footer_config',
        'enable_caching',
        'featured_products',
        'collections',
        'announcement_bar',
    ];

    protected $casts = [
        'settings' => 'array',
        'seo_config' => 'array',
        'ssl_enabled' => 'boolean',
        'is_active' => 'boolean',
        'allow_guest_checkout' => 'boolean',
        'require_account' => 'boolean',
        'show_inventory_levels' => 'boolean',
        'enable_reviews' => 'boolean',
        'enable_wishlist' => 'boolean',
        'minimum_order_amount_minor' => 'integer',
        'shipping_zones' => 'array',
        'payment_methods' => 'array',
        'tax_settings' => 'array',
        'theme_config' => 'array',
        'header_config' => 'array',
        'footer_config' => 'array',
        'enable_caching' => 'boolean',
        'featured_products' => 'array',
        'collections' => 'array',
    ];

    // ── Relationships ────────────────────────────────────────────────────────

    /**
     * Storefront pages (about, policies, etc.)
     */
    public function pages(): HasMany
    {
        return $this->hasMany(StorefrontPage::class);
    }

    /**
     * Product reviews for this storefront
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(ProductReview::class, 'account_id', 'account_id')
            ->where('business_id', $this->business_id);
    }

    // ── Business Logic ────────────────────────────────────────────────────────

    /**
     * Get the full URL for this storefront
     */
    public function getUrl(): string
    {
        if ($this->custom_domain) {
            $protocol = $this->ssl_enabled ? 'https' : 'http';

            return "{$protocol}://{$this->custom_domain}";
        }

        $baseUrl = rtrim(config('app.url'), '/');

        return "{$baseUrl}/store/{$this->slug}";
    }

    /**
     * Get featured products with full details
     */
    public function getFeaturedProducts()
    {
        if (empty($this->featured_products)) {
            return collect();
        }

        return Product::where('business_id', $this->business_id)
            ->whereIn('public_id', $this->featured_products)
            ->where('status', 'active')
            ->with(['variants', 'media', 'category'])
            ->get();
    }

    /**
     * Get products organized by collections
     */
    public function getProductCollections()
    {
        if (empty($this->collections)) {
            return collect();
        }

        return collect($this->collections)->map(function ($collection) {
            $products = Product::where('business_id', $this->business_id)
                ->whereIn('public_id', $collection['product_ids'] ?? [])
                ->where('status', 'active')
                ->with(['variants', 'media', 'category'])
                ->get();

            return [
                'name' => $collection['name'],
                'description' => $collection['description'] ?? null,
                'products' => $products,
            ];
        });
    }

    /**
     * Check if storefront allows access from given domain/origin
     */
    public function allowsOrigin(?string $origin): bool
    {
        if (! $this->is_active) {
            return false;
        }

        // Always allow access from main domain
        if (! $origin || str_contains($origin, config('app.url'))) {
            return true;
        }

        // Check custom domain
        if ($this->custom_domain && str_contains($origin, $this->custom_domain)) {
            return true;
        }

        return false;
    }

    /**
     * Get theme configuration with defaults
     */
    public function getThemeConfig(): array
    {
        $defaults = [
            'primary_color' => '#3b82f6',
            'secondary_color' => '#64748b',
            'accent_color' => '#f59e0b',
            'font_family' => 'Inter',
            'layout' => 'modern',
            'show_breadcrumbs' => true,
            'show_search' => true,
            'products_per_page' => 12,
        ];

        return array_merge($defaults, $this->theme_config ?? []);
    }

    /**
     * Get SEO configuration with defaults
     */
    public function getSeoConfig(): array
    {
        $defaults = [
            'meta_title' => $this->title,
            'meta_description' => $this->description,
            'meta_keywords' => '',
            'og_title' => $this->title,
            'og_description' => $this->description,
            'og_image' => null,
            'twitter_card' => 'summary',
            'robots' => 'index,follow',
        ];

        return array_merge($defaults, $this->seo_config ?? []);
    }

    /**
     * Get enabled payment methods
     */
    public function getPaymentMethods(): array
    {
        $available = [
            'stripe' => 'Credit/Debit Card',
            'paypal' => 'PayPal',
            'apple_pay' => 'Apple Pay',
            'google_pay' => 'Google Pay',
            'bank_transfer' => 'Bank Transfer',
            'cod' => 'Cash on Delivery',
        ];

        $enabled = $this->payment_methods ?? ['stripe'];

        return array_intersect_key($available, array_flip($enabled));
    }

    /**
     * Check if a minimum order amount is required
     */
    public function hasMinimumOrder(): bool
    {
        return $this->minimum_order_amount_minor && $this->minimum_order_amount_minor > 0;
    }

    /**
     * Validate an order against storefront rules
     *
     * @param  array{total_minor?: int}  $orderData
     */
    public function validateOrder(array $orderData): array
    {
        $errors = [];

        // Check minimum order amount
        if ($this->hasMinimumOrder()) {
            $totalMinor = $orderData['total_minor'] ?? 0;
            if ($totalMinor < $this->minimum_order_amount_minor) {
                $minimum = new Money($this->minimum_order_amount_minor, $this->currency ?? 'USD');
                $errors[] = "Minimum order amount is {$minimum->toDecimalString()}";
            }
        }

        // Check shipping zones if provided
        if ($this->shipping_zones && isset($orderData['shipping_address'])) {
            $allowed = $this->isShippingAllowed($orderData['shipping_address']);
            if (! $allowed) {
                $errors[] = 'Shipping is not available to this location';
            }
        }

        // Check account requirement
        if ($this->require_account && empty($orderData['customer_id'])) {
            $errors[] = 'Account registration is required for purchases';
        }

        return $errors;
    }

    /**
     * Check if shipping is allowed to the given address
     */
    private function isShippingAllowed(array $address): bool
    {
        if (empty($this->shipping_zones)) {
            return true; // No restrictions
        }

        $country = $address['country'] ?? null;
        $state = $address['state'] ?? null;
        $zip = $address['postal_code'] ?? null;

        foreach ($this->shipping_zones as $zone) {
            if ($this->addressMatchesZone($address, $zone)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if address matches shipping zone rules
     */
    private function addressMatchesZone(array $address, array $zone): bool
    {
        // Check country restrictions
        if (isset($zone['countries']) && ! empty($zone['countries'])) {
            if (! in_array($address['country'] ?? null, $zone['countries'])) {
                return false;
            }
        }

        // Check state/province restrictions
        if (isset($zone['states']) && ! empty($zone['states'])) {
            if (! in_array($address['state'] ?? null, $zone['states'])) {
                return false;
            }
        }

        // Check postal code patterns
        if (isset($zone['postal_patterns']) && ! empty($zone['postal_patterns'])) {
            $zip = $address['postal_code'] ?? '';
            $matched = false;

            foreach ($zone['postal_patterns'] as $pattern) {
                if (fnmatch($pattern, $zip)) {
                    $matched = true;
                    break;
                }
            }

            if (! $matched) {
                return false;
            }
        }

        return true;
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    /**
     * Scope to active storefronts only
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope by custom domain
     */
    public function scopeByDomain($query, string $domain)
    {
        return $query->where('custom_domain', $domain);
    }

    /**
     * Scope by slug
     */
    /**
     * The mark that goes at the top of this shop's paperwork.
     *
     * Points at the media library rather than holding a path, so the file keeps
     * its dimensions, its thumbnail and its knowledge of which disk it lives on
     * — none of which a column of text would carry.
     */
    public function logo(): BelongsTo
    {
        return $this->belongsTo(MediaItem::class, 'logo_media_id');
    }

    public function scopeBySlug($query, string $slug)
    {
        return $query->where('slug', $slug);
    }
}
