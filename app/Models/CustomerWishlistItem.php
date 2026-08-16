<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Shared\ValueObjects\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Customer Wishlist Item Model
 *
 * Represents items saved by customers for future purchase consideration.
 */
class CustomerWishlistItem extends Model
{
    protected $fillable = [
        'customer_id',
        'product_id',
        'variant_id',
        'product_name',
        'variant_name',
        'price_when_added_minor',
        'currency',
        'list_name',
        'sort_order',
        'notes',
        'notify_price_drop',
        'notify_back_in_stock',
        'target_price_minor',
    ];

    protected $casts = [
        'price_when_added_minor' => 'integer',
        'notify_price_drop' => 'boolean',
        'notify_back_in_stock' => 'boolean',
        'target_price_minor' => 'integer',
    ];

    // ── Relationships ────────────────────────────────────────────────────────

    /**
     * The customer who added this item
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * The product this wishlist item refers to
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * The product variant if applicable
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    // ── Business Logic ────────────────────────────────────────────────────────

    /**
     * Get current product price as Money object
     */
    public function getCurrentPrice(): ?Money
    {
        $product = $this->variant ?? $this->product;
        
        if (!$product || !$product->price) {
            return null;
        }

        return $product->price;
    }

    /**
     * Get price when added as Money object
     */
    public function getPriceWhenAdded(): Money
    {
        return new Money($this->price_when_added_minor, $this->currency);
    }

    /**
     * Get target price as Money object
     */
    public function getTargetPrice(): ?Money
    {
        if (!$this->target_price_minor) {
            return null;
        }

        return new Money($this->target_price_minor, $this->currency);
    }

    /**
     * Check if price has dropped since adding
     */
    public function hasPriceDropped(): bool
    {
        $currentPrice = $this->getCurrentPrice();
        
        if (!$currentPrice) {
            return false;
        }

        $originalPrice = $this->getPriceWhenAdded();
        
        return $currentPrice->isLessThan($originalPrice);
    }

    /**
     * Check if price has reached target
     */
    public function hasReachedTargetPrice(): bool
    {
        $targetPrice = $this->getTargetPrice();
        $currentPrice = $this->getCurrentPrice();
        
        if (!$targetPrice || !$currentPrice) {
            return false;
        }

        return $currentPrice->isLessThanOrEqual($targetPrice);
    }

    /**
     * Check if product is currently in stock
     */
    public function isInStock(): bool
    {
        $product = $this->variant ?? $this->product;
        
        if (!$product) {
            return false;
        }

        if (!$product->track_inventory) {
            return true; // Not tracking inventory, assume in stock
        }

        return $product->available_quantity > 0;
    }

    /**
     * Check if product was out of stock and is now back
     */
    public function isBackInStock(): bool
    {
        // This would require tracking historical stock levels
        // For now, we'll just check if it's currently in stock
        return $this->isInStock();
    }

    /**
     * Get display name for the item
     */
    public function getDisplayName(): string
    {
        if ($this->variant_name) {
            return "{$this->product_name} - {$this->variant_name}";
        }

        return $this->product_name;
    }

    /**
     * Move to different list
     */
    public function moveToList(string $listName): void
    {
        $this->update(['list_name' => $listName]);
    }

    /**
     * Update sort order
     */
    public function updateSortOrder(int $sortOrder): void
    {
        $this->update(['sort_order' => $sortOrder]);
    }

    /**
     * Add customer notes
     */
    public function addNotes(string $notes): void
    {
        $this->update(['notes' => $notes]);
    }

    /**
     * Enable price drop notifications
     */
    public function enablePriceDropNotifications(int $targetPriceMinor = null): void
    {
        $updates = ['notify_price_drop' => true];

        if ($targetPriceMinor !== null) {
            $updates['target_price_minor'] = $targetPriceMinor;
        }

        $this->update($updates);
    }

    /**
     * Enable back in stock notifications
     */
    public function enableStockNotifications(): void
    {
        $this->update(['notify_back_in_stock' => true]);
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    /**
     * Scope by list name
     */
    public function scopeInList($query, string $listName)
    {
        return $query->where('list_name', $listName);
    }

    /**
     * Scope by customer
     */
    public function scopeForCustomer($query, int $customerId)
    {
        return $query->where('customer_id', $customerId);
    }

    /**
     * Scope items with price drop notifications enabled
     */
    public function scopeWithPriceDropNotifications($query)
    {
        return $query->where('notify_price_drop', true);
    }

    /**
     * Scope items with stock notifications enabled
     */
    public function scopeWithStockNotifications($query)
    {
        return $query->where('notify_back_in_stock', true);
    }

    /**
     * Scope by sort order
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('created_at');
    }
}