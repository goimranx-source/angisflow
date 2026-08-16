<?php

namespace App\Models;

use App\Domain\Catalogue\Models\Product;
use App\Domain\Tenancy\Concerns\BelongsToAccount;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Field inventory tracking for technicians
 *
 * Tracks parts and supplies carried by field technicians in vehicles,
 * supporting mobile inventory management and restocking alerts.
 */
class FieldInventory extends Model
{
    use HasFactory, BelongsToAccount, BelongsToBusiness;

    protected $table = 'field_inventory';

    protected $fillable = [
        'account_id',
        'business_id',
        'technician_id',
        'vehicle_id',
        'product_id',
        'quantity_on_hand',
        'reserved_quantity',
        'reorder_level',
        'last_restocked',
        'location_notes',
    ];

    protected $casts = [
        'quantity_on_hand' => 'decimal:4',
        'reserved_quantity' => 'decimal:4',
        'last_restocked' => 'date',
    ];

    // ── Relationships ──────────────────────────────────────────────────────

    public function technician(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'technician_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(FleetVehicle::class, 'vehicle_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    // ── Accessors ──────────────────────────────────────────────────────────

    /**
     * Get available quantity (on hand minus reserved)
     */
    public function getAvailableQuantity(): float
    {
        return $this->quantity_on_hand - $this->reserved_quantity;
    }

    /**
     * Check if stock needs reordering
     */
    public function needsReorder(): bool
    {
        return $this->getAvailableQuantity() <= $this->reorder_level;
    }

    // ── Query Scopes ───────────────────────────────────────────────────────

    public function scopeNeedsReorder($query)
    {
        return $query->whereRaw('(quantity_on_hand - reserved_quantity) <= reorder_level');
    }

    /**
     * Reserve quantity for work order
     */
    public function reserve(float $quantity): bool
    {
        if ($this->getAvailableQuantity() < $quantity) {
            return false;
        }

        return $this->increment('reserved_quantity', $quantity);
    }

    /**
     * Release reserved quantity
     */
    public function release(float $quantity): bool
    {
        $releaseAmount = min($quantity, $this->reserved_quantity);
        return $this->decrement('reserved_quantity', $releaseAmount);
    }

    /**
     * Use quantity (reduce on hand and reserved)
     */
    public function use(float $quantity): bool
    {
        if ($this->quantity_on_hand < $quantity) {
            return false;
        }

        $this->decrement('quantity_on_hand', $quantity);
        
        // Also reduce reserved if applicable
        if ($this->reserved_quantity > 0) {
            $this->decrement('reserved_quantity', min($quantity, $this->reserved_quantity));
        }

        return true;
    }
}