<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Models;

use App\Domain\Delivery\ShipmentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One of a courier's words, and what it means here.
 *
 * is_guess is the important column. A mapping somebody confirmed and one we
 * proposed behave identically at runtime and must not look identical on screen
 * — the second is a question, and presenting it as an answer is how a parcel
 * ends up settled because a guess said "delivered".
 */
class CourierStatusMapping extends Model
{
    protected $attributes = ['is_guess' => false, 'seen_count' => 0];

    protected $fillable = [
        'courier_connection_id', 'raw_status', 'canonical',
        'is_guess', 'confirmed_at', 'seen_count',
    ];

    protected function casts(): array
    {
        return ['is_guess' => 'boolean', 'confirmed_at' => 'datetime', 'seen_count' => 'integer'];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(CourierConnection::class, 'courier_connection_id');
    }

    public function status(): ShipmentStatus
    {
        return ShipmentStatus::tryFrom($this->canonical) ?? ShipmentStatus::UNKNOWN;
    }

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'raw_status' => $this->raw_status,
            'canonical' => $this->canonical,
            'label' => $this->status()->label(),
            'is_guess' => $this->is_guess,
            'is_confirmed' => $this->isConfirmed(),
            'seen_count' => $this->seen_count,
        ];
    }
}
