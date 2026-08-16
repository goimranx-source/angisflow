<?php

namespace App\Models;

use App\Domain\Tenancy\Concerns\BelongsToAccount;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pivot model for booking-resource assignments
 *
 * Tracks which resources are assigned to which bookings,
 * with role and timing details.
 */
class BookingResourceAssignment extends Model
{
    use HasFactory, BelongsToAccount, BelongsToBusiness;

    protected $table = 'booking_resource_assignments';

    protected $fillable = [
        'account_id',
        'business_id',
        'booking_id',
        'resource_id',
        'role',
        'assigned_start',
        'assigned_end',
        'resource_notes',
    ];

    protected $casts = [
        'assigned_start' => 'datetime',
        'assigned_end' => 'datetime',
    ];

    // ── Relationships ──────────────────────────────────────────────────────

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(BookingResource::class, 'resource_id');
    }

    // ── Accessors ──────────────────────────────────────────────────────────

    /**
     * Get duration in minutes
     */
    public function getDurationMinutes(): int
    {
        return $this->assigned_start->diffInMinutes($this->assigned_end);
    }

    /**
     * Check if assignment overlaps with given time period
     */
    public function overlapsWith(\DateTimeInterface $start, \DateTimeInterface $end): bool
    {
        return $this->assigned_start < $end && $this->assigned_end > $start;
    }
}