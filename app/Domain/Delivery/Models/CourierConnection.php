<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Models;

use App\Domain\Shared\Concerns\HasPublicId;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One subscriber's arrangement with one courier.
 *
 * The subscriber makes this connection, not us — their contract, their
 * credentials, their webhook. What we provide is somewhere for it to go and a
 * shape it gets translated into.
 */
class CourierConnection extends Model
{
    use BelongsToBusiness, HasPublicId;

    public const ACTIVE = 'active';

    public const PAUSED = 'paused';

    public const BROKEN = 'broken';

    protected $attributes = [
        'status' => self::ACTIVE,
        'cod_fee_percent' => 0,
        'unmapped_count' => 0,
    ];

    protected $fillable = [
        'public_id', 'account_id', 'business_id', 'courier_id', 'integration_id', 'label',
        'credential_ref', 'base_url', 'webhook_path', 'webhook_secret',
        'settings', 'settlement_days', 'cod_fee_percent',
        'status', 'last_seen_at', 'last_error', 'unmapped_count',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'last_seen_at' => 'datetime',
            'settlement_days' => 'integer',
            'unmapped_count' => 'integer',
        ];
    }

    protected $hidden = ['webhook_secret', 'credential_ref'];

    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class);
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Integrations\Models\Integration::class);
    }

    public function mappings(): HasMany
    {
        return $this->hasMany(CourierStatusMapping::class);
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    public function isUsable(): bool
    {
        return $this->status === self::ACTIVE;
    }

    /**
     * Whether anything has arrived recently.
     *
     * A connection that has not been heard from in days is either quiet or
     * broken, and those look identical from here — which is exactly why it is
     * worth surfacing rather than assuming the first.
     */
    public function isSilent(int $days = 3): bool
    {
        return $this->last_seen_at === null || $this->last_seen_at->lt(now()->subDays($days));
    }

    public function scopeUsable(Builder $q): Builder
    {
        return $q->where('status', self::ACTIVE);
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'id' => $this->public_id,
            'label' => $this->label ?? $this->courier?->name,
            'courier' => $this->relationLoaded('courier') ? $this->courier?->toPayload() : null,
            'status' => $this->status,
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'is_silent' => $this->isSilent(),
            'unmapped_count' => $this->unmapped_count,
            'settlement_days' => $this->settlement_days,
            'cod_fee_percent' => (float) $this->cod_fee_percent,
            'last_error' => $this->last_error,
        ];
    }
}
