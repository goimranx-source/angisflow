<?php

namespace App\Models;

use App\Domain\Tenancy\Concerns\BelongsToAccount;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Vehicle assignment to employees
 *
 * Tracks which employee is assigned to which vehicle and when,
 * supporting temporary and permanent assignments.
 */
class VehicleAssignment extends Model
{
    use HasFactory, BelongsToAccount, BelongsToBusiness;

    protected $fillable = [
        'account_id',
        'business_id',
        'vehicle_id',
        'employee_id',
        'assigned_date',
        'unassigned_date',
        'assignment_type',
        'assignment_notes',
        'is_active',
    ];

    protected $casts = [
        'assigned_date' => 'date',
        'unassigned_date' => 'date',
        'is_active' => 'boolean',
    ];

    public const ASSIGNMENT_TYPES = [
        'primary' => 'Primary Driver',
        'temporary' => 'Temporary Assignment',
        'backup' => 'Backup Driver',
    ];

    // ── Relationships ──────────────────────────────────────────────────────

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(FleetVehicle::class, 'vehicle_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    // ── Query Scopes ───────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopePrimary($query)
    {
        return $query->where('assignment_type', 'primary');
    }
}