<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Models;

use App\Domain\Shared\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One inbound request, as it arrived.
 *
 * Deliberately not tenant-scoped: a request that matched no connection has no
 * tenant, and those are the ones most worth keeping.
 */
class WebhookDelivery extends Model
{
    use HasPublicId;

    public const RECEIVED = 'received';
    public const VERIFIED = 'verified';
    public const REJECTED = 'rejected';
    public const PARSED = 'parsed';
    public const FAILED = 'failed';
    public const IGNORED = 'ignored';

    protected $attributes = ['status' => self::RECEIVED, 'event_count' => 0, 'applied_count' => 0];

    protected $fillable = [
        'public_id', 'account_id', 'business_id', 'courier_connection_id',
        'endpoint', 'source_ip', 'headers', 'body', 'status', 'failure_reason',
        'event_count', 'applied_count', 'external_delivery_id',
        'received_at', 'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'headers' => 'array',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
            'event_count' => 'integer',
            'applied_count' => 'integer',
        ];
    }

    protected $hidden = ['headers'];

    public function courierConnection(): BelongsTo
    {
        return $this->belongsTo(CourierConnection::class, 'courier_connection_id');
    }

    /** The body as data, or null if it was never valid JSON. */
    public function decoded(): ?array
    {
        $decoded = json_decode((string) $this->body, true);

        return is_array($decoded) ? $decoded : null;
    }

    /** Anything that arrived and produced nothing — the queue worth looking at. */
    public function scopeUnresolved(Builder $q): Builder
    {
        return $q->whereIn('status', [self::FAILED, self::REJECTED])
            ->orWhere(fn ($w) => $w->where('status', self::PARSED)->where('applied_count', 0));
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'id' => $this->public_id,
            'endpoint' => $this->endpoint,
            'status' => $this->status,
            'failure_reason' => $this->failure_reason,
            'event_count' => $this->event_count,
            'applied_count' => $this->applied_count,
            'received_at' => $this->received_at?->toIso8601String(),
            'source_ip' => $this->source_ip,
        ];
    }
}
