<?php

namespace App\Models;

use App\Domain\Tenancy\Concerns\BelongsToAccount;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Resource availability overrides
 *
 * Defines when resources are available or unavailable,
 * overriding default working hours.
 */
class ResourceAvailability extends Model
{
    use HasFactory, BelongsToAccount, BelongsToBusiness;

    protected $table = 'resource_availability';

    protected $fillable = [
        'resource_id',
        'date',
        'start_time',
        'end_time',
        'availability_type',
        'notes',
        'is_override',
        'override_reason',
    ];

    protected $casts = [
        'date' => 'date',
        'is_override' => 'boolean',
    ];

    public const AVAILABILITY_TYPES = [
        'available' => 'Available',
        'unavailable' => 'Unavailable',
        'break' => 'Break',
    ];

    // ── Relationships ──────────────────────────────────────────────────────

    public function resource(): BelongsTo
    {
        return $this->belongsTo(BookingResource::class, 'resource_id');
    }

    // ── Query Scopes ───────────────────────────────────────────────────────

    public function scopeAvailable($query)
    {
        return $query->where('availability_type', 'available');
    }

    public function scopeUnavailable($query)
    {
        return $query->where('availability_type', 'unavailable');
    }

    public function scopeForDate($query, \DateTimeInterface $date)
    {
        return $query->where('date', $date->format('Y-m-d'));
    }

    public function scopeOverrides($query)
    {
        return $query->where('is_override', true);
    }
}