<?php

namespace App\Models;

use App\Domain\Catalogue\Models\Product;
use App\Domain\Tenancy\Concerns\BelongsToAccount;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use App\Domain\Shared\ValueObjects\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Parts usage tracking for work orders
 *
 * Records which parts were used on specific work orders,
 * supporting cost tracking and inventory management.
 */
class WorkOrderParts extends Model
{
    use HasFactory, BelongsToAccount, BelongsToBusiness;

    protected $fillable = [
        'account_id',
        'business_id',
        'work_order_id',
        'product_id',
        'used_by',
        'quantity_used',
        'unit_cost_minor',
        'currency',
        'used_at',
        'usage_notes',
        'source_location',
        'part_condition',
    ];

    protected $casts = [
        'quantity_used' => 'decimal:4',
        'used_at' => 'datetime',
    ];

    public const SOURCE_LOCATIONS = [
        'warehouse' => 'Main Warehouse',
        'field_inventory' => 'Field Inventory',
        'emergency_purchase' => 'Emergency Purchase',
    ];

    public const PART_CONDITIONS = [
        'new' => 'New',
        'refurbished' => 'Refurbished',
        'emergency_replacement' => 'Emergency Replacement',
    ];

    // ── Relationships ──────────────────────────────────────────────────────

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function usedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'used_by');
    }

    // ── Accessors ──────────────────────────────────────────────────────────

    /**
     * Get unit cost as Money object
     */
    public function getUnitCost(): Money
    {
        return new Money($this->unit_cost_minor, $this->currency);
    }

    /**
     * Get total cost for this usage
     */
    public function getTotalCost(): Money
    {
        return $this->getUnitCost()->times($this->quantity_used);
    }
}