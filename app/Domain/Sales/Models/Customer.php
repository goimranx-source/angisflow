<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Domain\Shared\Concerns\HasPublicId;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use App\Domain\Shared\ValueObjects\Money;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Somebody the business sells to.
 *
 * Deliberately small. The full customer record — channel identities, merge and
 * dedup, segments, order history, risk scoring — is a much larger job with its
 * own design problems. This is the part invoicing genuinely cannot work
 * without, built properly so that the larger job extends it rather than
 * replacing it.
 */
class Customer extends Model
{
    use BelongsToBusiness, HasPublicId, SoftDeletes;

    protected $fillable = [
        'public_id', 'account_id', 'business_id',
        'name', 'email', 'phone', 'company', 'tax_number',
        'billing_address', 'billing_city', 'billing_postcode', 'billing_country',
        'currency', 'payment_terms_days', 'notes', 'is_active',
        'merged_into_id', 'merged_at', 'order_count', 'lifetime_value_minor',
        'average_order_minor', 'first_order_on', 'last_order_on', 'return_count',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'payment_terms_days' => 'integer',
            'merged_at' => 'datetime',
            'first_order_on' => 'date',
            'last_order_on' => 'date',
            'order_count' => 'integer',
            'lifetime_value_minor' => 'integer',
            'average_order_minor' => 'integer',
            'return_count' => 'integer',
        ];
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function identities(): HasMany
    {
        return $this->hasMany(CustomerIdentity::class);
    }

    public function riskProfile(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(\App\Domain\Risk\Models\CustomerRiskProfile::class);
    }

    public function riskFlags(): HasMany
    {
        return $this->hasMany(\App\Domain\Risk\Models\CustomerRiskFlag::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** The record this one lost a merge to, if it did. */
    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_id');
    }

    public function isMerged(): bool
    {
        return $this->merged_into_id !== null;
    }

    public function lifetimeValue(): Money
    {
        return new Money($this->lifetime_value_minor, $this->currency ?? 'USD');
    }

    public function averageOrder(): Money
    {
        return new Money($this->average_order_minor, $this->currency ?? 'USD');
    }

    /**
     * Days since they last bought anything.
     *
     * Null when they never have. Zero would put a customer who has never
     * ordered at the top of a "most recent" list, which is where the one who
     * ordered this morning belongs.
     */
    public function daysSinceLastOrder(): ?int
    {
        return $this->last_order_on === null ? null : (int) $this->last_order_on->diffInDays(now());
    }

    /**
     * How often they come back, as returns per order.
     *
     * Null below three orders: one return out of one order is 100% and means
     * nothing, and a figure that meaningless will still be sorted on.
     */
    public function returnRate(): ?float
    {
        return $this->order_count < 3 ? null : round($this->return_count / $this->order_count * 100, 1);
    }

    /** Everything that is not the merged-away duplicate of somebody else. */
    public function scopeReal(Builder $q): Builder
    {
        return $q->whereNull('merged_into_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'company' => $this->company,
            'currency' => $this->currency,
            'payment_terms_days' => $this->payment_terms_days,
            'is_active' => $this->is_active,
            'is_merged' => $this->isMerged(),
            'order_count' => $this->order_count,
            'lifetime_value' => $this->lifetimeValue()->jsonSerialize(),
            'average_order' => $this->averageOrder()->jsonSerialize(),
            'first_order_on' => $this->first_order_on?->toDateString(),
            'last_order_on' => $this->last_order_on?->toDateString(),
            'days_since_last_order' => $this->daysSinceLastOrder(),
            'return_rate' => $this->returnRate(),
            'identities' => $this->relationLoaded('identities')
                ? $this->identities->map->toPayload()->all()
                : [],
        ];
    }
}
