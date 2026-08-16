<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Models;

use App\Domain\Delivery\ShipmentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something a courier told us.
 *
 * Kept whether or not it changed anything. An event ignored as stale is still
 * evidence that they sent it, which is the answer to half of all integration
 * arguments.
 */
class ShipmentEvent extends Model
{
    protected $attributes = ['source' => 'webhook', 'applied' => true];

    protected $fillable = [
        'shipment_id', 'business_id', 'status', 'raw_status', 'description',
        'location', 'occurred_at', 'received_at', 'source', 'payload',
        'applied', 'ignored_reason',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'received_at' => 'datetime',
            'payload' => 'array',
            'applied' => 'boolean',
        ];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function status(): ShipmentStatus
    {
        return ShipmentStatus::tryFrom($this->status) ?? ShipmentStatus::UNKNOWN;
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'status' => $this->status,
            'label' => $this->status()->label(),
            'raw_status' => $this->raw_status,
            'description' => $this->description,
            'location' => $this->location,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'source' => $this->source,
            'applied' => $this->applied,
            'ignored_reason' => $this->ignored_reason,
        ];
    }
}
