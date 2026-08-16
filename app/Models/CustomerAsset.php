<?php

namespace App\Models;

use App\Domain\Sales\Models\Customer;
use App\Domain\Tenancy\Concerns\BelongsToAccount;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use App\Domain\Shared\Concerns\HasPublicId;
use App\Domain\Shared\ValueObjects\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Customer equipment/asset that requires field service
 *
 * Represents equipment, systems, or installations at customer locations
 * that require regular maintenance, repair, or inspection services.
 */
class CustomerAsset extends Model
{
    use HasFactory, BelongsToAccount, BelongsToBusiness, HasPublicId, SoftDeletes;

    protected $fillable = [
        'account_id',
        'business_id',
        'public_id',
        'customer_id',
        'asset_number',
        'name',
        'asset_type',
        'make',
        'model',
        'serial_number',
        'description',
        'installation_address',
        'installation_contact',
        'installation_phone',
        'latitude',
        'longitude',
        'installation_date',
        'warranty_start_date',
        'warranty_end_date',
        'service_level',
        'service_interval_days',
        'last_service_date',
        'next_service_due',
        'status',
        'special_instructions',
        'asset_value_minor',
        'currency',
        'criticality',
    ];

    protected $casts = [
        'installation_date' => 'date',
        'warranty_start_date' => 'date',
        'warranty_end_date' => 'date',
        'last_service_date' => 'date',
        'next_service_due' => 'date',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
    ];

    public const STATUSES = [
        'active' => 'Active',
        'inactive' => 'Inactive',
        'decommissioned' => 'Decommissioned',
    ];

    public const SERVICE_LEVELS = [
        'basic' => 'Basic',
        'standard' => 'Standard',
        'premium' => 'Premium',
    ];

    public const CRITICALITY_LEVELS = [
        'low' => 'Low',
        'medium' => 'Medium',
        'high' => 'High',
        'critical' => 'Critical',
    ];

    // ── Relationships ──────────────────────────────────────────────────────

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class, 'customer_asset_id');
    }

    public function serviceContracts(): HasMany
    {
        return $this->hasMany(ServiceContract::class);
    }

    // ── Accessors ──────────────────────────────────────────────────────────

    /**
     * Get asset value as Money object
     */
    public function getAssetValue(): Money
    {
        return new Money($this->asset_value_minor, $this->currency);
    }

    /**
     * Check if asset is under warranty
     */
    public function isUnderWarranty(): bool
    {
        if (!$this->warranty_start_date || !$this->warranty_end_date) {
            return false;
        }

        $now = now()->toDateString();
        return $now >= $this->warranty_start_date && $now <= $this->warranty_end_date;
    }

    /**
     * Check if service is due
     */
    public function isServiceDue(): bool
    {
        return $this->next_service_due && $this->next_service_due <= now()->toDateString();
    }

    /**
     * Get days until next service
     */
    public function getDaysUntilService(): ?int
    {
        if (!$this->next_service_due) {
            return null;
        }

        return now()->diffInDays($this->next_service_due, false);
    }

    /**
     * Generate unique asset number
     */
    public static function generateAssetNumber(): string
    {
        $prefix = 'AST-';
        $lastAsset = static::where('asset_number', 'like', $prefix . '%')
                           ->orderBy('asset_number', 'desc')
                           ->first();

        if (!$lastAsset) {
            return $prefix . '000001';
        }

        $lastNumber = (int) substr($lastAsset->asset_number, strlen($prefix));
        return $prefix . str_pad($lastNumber + 1, 6, '0', STR_PAD_LEFT);
    }

    // ── Query Scopes ───────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeServiceDue($query)
    {
        return $query->where('next_service_due', '<=', now()->toDateString());
    }

    public function scopeCriticality($query, string $level)
    {
        return $query->where('criticality', $level);
    }

    public function scopeUnderWarranty($query)
    {
        return $query->where('warranty_start_date', '<=', now()->toDateString())
                     ->where('warranty_end_date', '>=', now()->toDateString());
    }

    /**
     * Update next service date based on service interval
     */
    public function updateNextServiceDate(?string $lastServiceDate = null): bool
    {
        $baseDate = $lastServiceDate ? $lastServiceDate : ($this->last_service_date ?? $this->installation_date);
        
        if (!$baseDate || !$this->service_interval_days) {
            return false;
        }

        $nextServiceDate = \Carbon\Carbon::parse($baseDate)->addDays($this->service_interval_days);
        
        return $this->update([
            'next_service_due' => $nextServiceDate->toDateString(),
        ]);
    }
}