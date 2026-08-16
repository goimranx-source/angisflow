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
 * Service contract for recurring maintenance and support
 *
 * Represents maintenance agreements, support contracts, and warranty extensions
 * that define ongoing service commitments to customers.
 */
class ServiceContract extends Model
{
    use HasFactory, BelongsToAccount, BelongsToBusiness, HasPublicId, SoftDeletes;

    protected $fillable = [
        'account_id',
        'business_id',
        'public_id',
        'contract_number',
        'name',
        'description',
        'contract_type',
        'customer_id',
        'covered_assets',
        'start_date',
        'end_date',
        'billing_frequency',
        'contract_value_minor',
        'currency',
        'terms_and_conditions',
        'response_time_hours',
        'resolution_time_hours',
        'service_schedule',
        'included_visits',
        'parts_included',
        'emergency_support',
        'status',
        'next_service_date',
        'visits_used',
        'special_instructions',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'next_service_date' => 'date',
        'covered_assets' => 'array',
        'service_schedule' => 'array',
        'parts_included' => 'boolean',
        'emergency_support' => 'boolean',
    ];

    public const STATUSES = [
        'draft' => 'Draft',
        'active' => 'Active',
        'expired' => 'Expired',
        'cancelled' => 'Cancelled',
    ];

    public const CONTRACT_TYPES = [
        'maintenance' => 'Maintenance Agreement',
        'support' => 'Technical Support',
        'warranty_extension' => 'Warranty Extension',
    ];

    public const BILLING_FREQUENCIES = [
        'monthly' => 'Monthly',
        'quarterly' => 'Quarterly',
        'annually' => 'Annually',
    ];

    // ── Relationships ──────────────────────────────────────────────────────

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }

    // ── Accessors ──────────────────────────────────────────────────────────

    /**
     * Get contract value as Money object
     */
    public function getContractValue(): Money
    {
        return new Money($this->contract_value_minor, $this->currency);
    }

    /**
     * Generate unique contract number
     */
    public static function generateContractNumber(): string
    {
        $prefix = 'SC-' . date('Y') . '-';
        $lastContract = static::where('contract_number', 'like', $prefix . '%')
                              ->orderBy('contract_number', 'desc')
                              ->first();

        if (!$lastContract) {
            return $prefix . '001';
        }

        $lastNumber = (int) substr($lastContract->contract_number, strlen($prefix));
        return $prefix . str_pad($lastNumber + 1, 3, '0', STR_PAD_LEFT);
    }

    // ── Query Scopes ───────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeExpiring($query, int $days = 30)
    {
        return $query->where('status', 'active')
                     ->where('end_date', '<=', now()->addDays($days));
    }

    /**
     * Check if contract is active
     */
    public function isActive(): bool
    {
        return $this->status === 'active' && 
               now()->between($this->start_date, $this->end_date);
    }

    /**
     * Check if contract covers an asset
     */
    public function coversAsset(int $assetId): bool
    {
        return in_array($assetId, $this->covered_assets ?? []);
    }
}