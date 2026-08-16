<?php

namespace App\Models;

use App\Domain\Tenancy\Concerns\BelongsToAccount;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Technician check-in/out records for work orders
 *
 * Tracks when technicians arrive, depart, or take breaks during field service,
 * supporting GPS tracking and photo documentation.
 */
class TechnicianCheckIn extends Model
{
    use HasFactory, BelongsToAccount, BelongsToBusiness;

    protected $table = 'technician_checkins';

    protected $fillable = [
        'account_id',
        'business_id',
        'work_order_id',
        'technician_id',
        'checkin_type',
        'checkin_time',
        'latitude',
        'longitude',
        'notes',
        'photos',
        'odometer_reading',
        'status_update',
    ];

    protected $casts = [
        'checkin_time' => 'datetime',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'photos' => 'array',
    ];

    public const CHECKIN_TYPES = [
        'arrival' => 'Arrival',
        'departure' => 'Departure',
        'break_start' => 'Break Start',
        'break_end' => 'Break End',
    ];

    // ── Relationships ──────────────────────────────────────────────────────

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'technician_id');
    }

    // ── Query Scopes ───────────────────────────────────────────────────────

    public function scopeType($query, string $type)
    {
        return $query->where('checkin_type', $type);
    }

    public function scopeToday($query)
    {
        return $query->whereDate('checkin_time', now()->toDateString());
    }
}