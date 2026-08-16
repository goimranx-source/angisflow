<?php

namespace App\Models;

use App\Domain\Shared\Concerns\HasPublicId;
use App\Domain\Tenancy\Concerns\BelongsToAccount;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Daily clock-in/out tracking.
 *
 * Essential for daily/hourly-rate workers. A monthly salary can be pro-rated
 * from a calendar, but daily or hourly pay requires actual worked hours.
 */
class Attendance extends Model
{
    use BelongsToAccount, BelongsToBusiness, HasPublicId;

    protected $fillable = [
        'account_id', 'business_id', 'employee_id', 'location_id', 'date',
        'clock_in', 'clock_out', 'hours_worked', 'hours_overtime', 'status',
        'leave_type', 'clock_in_lat', 'clock_in_lng', 'clock_out_lat',
        'clock_out_lng', 'notes', 'approved_by_user_id', 'approved_at',
    ];

    protected $casts = [
        'date' => 'date',
        'hours_worked' => 'decimal:2',
        'hours_overtime' => 'decimal:2',
        'approved_at' => 'datetime',
    ];

    public const STATUSES = [
        'present' => 'Present',
        'absent' => 'Absent',
        'on_leave' => 'On Leave',
        'holiday' => 'Holiday',
        'half_day' => 'Half Day',
    ];

    public const LEAVE_TYPES = [
        'sick' => 'Sick Leave',
        'annual' => 'Annual Leave',
        'unpaid' => 'Unpaid Leave',
        'other' => 'Other',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    // Note: see Employee::location() — `location_id` has no matching model
    // yet, so this relation is left commented out rather than pointing at a
    // class that does not exist (which would be a fatal error the moment
    // anything tried to eager-load it).
    // public function location(): BelongsTo
    // {
    //     return $this->belongsTo(Location::class);
    // }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function scopePresent($query)
    {
        return $query->where('status', 'present');
    }

    public function scopeAbsent($query)
    {
        return $query->where('status', 'absent');
    }

    public function scopeOnLeave($query)
    {
        return $query->where('status', 'on_leave');
    }

    public function scopeForPeriod($query, string $from, string $to)
    {
        return $query->whereBetween('date', [$from, $to]);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst($this->status);
    }

    public function leaveTypeLabel(): ?string
    {
        return $this->leave_type ? (self::LEAVE_TYPES[$this->leave_type] ?? ucfirst($this->leave_type)) : null;
    }

    public function isApproved(): bool
    {
        return $this->approved_at !== null;
    }

    /**
     * Calculate hours worked from clock times if not manually set.
     */
    public function calculateHours(): float
    {
        if (! $this->clock_in || ! $this->clock_out) {
            return 0.0;
        }

        $in = \Carbon\Carbon::parse($this->clock_in);
        $out = \Carbon\Carbon::parse($this->clock_out);

        return round($in->diffInMinutes($out) / 60, 2);
    }

    /**
     * Was this person late?
     */
    public function isLate(string $expectedTime = '09:00'): bool
    {
        if (! $this->clock_in) {
            return false;
        }

        $clockIn = \Carbon\Carbon::parse($this->clock_in);
        $expected = \Carbon\Carbon::parse($this->date->format('Y-m-d') . ' ' . $expectedTime);

        return $clockIn->greaterThan($expected);
    }

    /**
     * Left early?
     */
    public function isEarlyLeave(string $expectedTime = '18:00'): bool
    {
        if (! $this->clock_out) {
            return false;
        }

        $clockOut = \Carbon\Carbon::parse($this->clock_out);
        $expected = \Carbon\Carbon::parse($this->date->format('Y-m-d') . ' ' . $expectedTime);

        return $clockOut->lessThan($expected);
    }
}
