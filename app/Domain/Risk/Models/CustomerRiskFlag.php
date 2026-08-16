<?php

declare(strict_types=1);

namespace App\Domain\Risk\Models;

use App\Domain\Sales\Models\Customer;
use App\Domain\Shared\Concerns\HasPublicId;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A decision somebody made about a customer.
 *
 * Kept apart from the computed score deliberately. A score is an opinion that
 * changes every time it is recalculated; this is a fact about what a person
 * chose, and it has to survive recalculation — including a `trusted` flag that
 * deliberately overrules whatever the machine thinks.
 */
class CustomerRiskFlag extends Model
{
    use BelongsToBusiness, HasPublicId;

    public const BLOCK = 'block';
    public const PREPAY_ONLY = 'prepay_only';
    public const TRUSTED = 'trusted';
    public const WATCH = 'watch';

    protected $fillable = [
        'public_id', 'account_id', 'business_id', 'customer_id', 'kind', 'reason',
        'expires_at', 'lifted_at', 'lifted_reason', 'created_by', 'lifted_by',
    ];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'lifted_at' => 'datetime'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function isActive(): bool
    {
        return $this->lifted_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->whereNull('lifted_at')
            ->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'id' => $this->public_id,
            'kind' => $this->kind,
            'reason' => $this->reason,
            'is_active' => $this->isActive(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'lifted_at' => $this->lifted_at?->toIso8601String(),
        ];
    }
}
