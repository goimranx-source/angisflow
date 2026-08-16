<?php

declare(strict_types=1);

namespace App\Domain\Crm\Models;

use App\Domain\Sales\Models\Customer;
use App\Domain\Shared\Concerns\HasPublicId;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Somebody who might buy.
 *
 * Kept after conversion rather than deleted. The link to what they became is
 * the only record of where a customer came from — and without it, conversion
 * rate has no denominator.
 */
class Lead extends Model
{
    use BelongsToBusiness, HasPublicId, SoftDeletes;

    public const NEW = 'new';
    public const WORKING = 'working';
    public const CONVERTED = 'converted';
    public const DISQUALIFIED = 'disqualified';

    protected $attributes = ['status' => self::NEW];

    protected $fillable = [
        'public_id', 'account_id', 'business_id', 'name', 'company', 'email', 'phone',
        'country', 'source', 'campaign', 'status', 'disqualified_reason',
        'converted_customer_id', 'converted_at', 'owner_id', 'notes', 'last_contacted_at',
    ];

    protected function casts(): array
    {
        return ['converted_at' => 'datetime', 'last_contacted_at' => 'datetime'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'converted_customer_id');
    }

    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::NEW, self::WORKING], true);
    }

    /** Nobody has touched it in a while — the queue that quietly rots. */
    public function isStale(int $days = 14): bool
    {
        if (! $this->isOpen()) {
            return false;
        }

        $last = $this->last_contacted_at ?? $this->created_at;

        return $last !== null && $last->lt(now()->subDays($days));
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereIn('status', [self::NEW, self::WORKING]);
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'company' => $this->company,
            'email' => $this->email,
            'phone' => $this->phone,
            'source' => $this->source,
            'campaign' => $this->campaign,
            'status' => $this->status,
            'is_stale' => $this->isStale(),
            'last_contacted_at' => $this->last_contacted_at?->toIso8601String(),
            'converted_customer' => $this->relationLoaded('customer') ? $this->customer?->toPayload() : null,
        ];
    }
}
