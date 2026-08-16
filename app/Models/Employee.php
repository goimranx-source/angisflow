<?php

namespace App\Models;

use App\Domain\Shared\Concerns\HasPublicId;
use App\Domain\Tenancy\Concerns\BelongsToAccount;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A person on the payroll.
 *
 * Separate from a login on purpose. A delivery rider may never sign in and
 * still has to be paid every month; requiring an account would mean inventing
 * credentials nobody uses.
 *
 * Owners who work in the business are employees too. There is no separate
 * owner-pay arrangement — a partner drawing a wage is on this payroll at a
 * wage, and that wage is an ordinary cost of trading.
 */
class Employee extends Model
{
    use BelongsToAccount, BelongsToBusiness, HasPublicId, SoftDeletes;

    protected $fillable = [
        'account_id', 'business_id', 'linked_user_id', 'location_id', 'department_id',
        'position_id', 'manager_id', 'code', 'name', 'phone', 'email', 'address',
        'national_id', 'date_of_birth', 'joined_on', 'left_on', 'base_salary', 'pay_type',
        'payment_method', 'bank_account', 'status', 'commission_type', 'commission_rate',
        'notes', 'portal_seen_at',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'joined_on' => 'date',
        'left_on' => 'date',
        'base_salary' => 'decimal:2',
        'commission_rate' => 'decimal:4',
        'portal_seen_at' => 'datetime',
    ];

    public const STATUSES = [
        'active' => 'Active',
        'on_leave' => 'On leave',
        'suspended' => 'Suspended',
        'left' => 'Left',
    ];

    public const PAY_TYPES = [
        'monthly' => 'Monthly salary',
        'daily' => 'Daily rate',
        'hourly' => 'Hourly rate',
    ];

    public const COMMISSION_TYPES = [
        'none' => 'No commission',
        'percent_order' => 'Percentage of what they sell',
        'fixed_order' => 'Fixed amount per order',
    ];

    public function linkedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_user_id');
    }

    // Note: `location_id` is a plain column with no matching model yet — there
    // is no site/branch concept for HR to point at. `App\Domain\Stock\Models\
    // StockLocation` is a warehouse/stock-location concept (batches, shelves,
    // sellability), not a place staff are based, so pointing this relation at
    // it would be wrong rather than merely early. Left commented out, the
    // same way PayrollRun::location() is, until a real HR site/branch model
    // exists.
    // public function location(): BelongsTo
    // {
    //     return $this->belongsTo(Location::class);
    // }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_id');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Employee::class, 'manager_id');
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function leaveBalances(): HasMany
    {
        return $this->hasMany(LeaveBalance::class);
    }

    public function vehicleAssignments(): HasMany
    {
        return $this->hasMany(VehicleAssignment::class, 'employee_id');
    }

    /**
     * Who a payroll run should include.
     *
     * Only active staff. Someone on leave is still paid; someone suspended or
     * gone is not.
     */
    public function scopePayable($query)
    {
        return $query->whereIn('status', ['active', 'on_leave']);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst($this->status);
    }

    public function payTypeLabel(): string
    {
        return self::PAY_TYPES[$this->pay_type] ?? $this->pay_type;
    }

    public function isPayable(): bool
    {
        return in_array($this->status, ['active', 'on_leave'], true);
    }

    /**
     * Still working here.
     *
     * Somebody on leave is still an employee — that is often exactly when they
     * want to check what they are owed.
     */
    public function isActive(): bool
    {
        return in_array($this->status, ['active', 'on_leave'], true);
    }

    public function level(): ?JobLevel
    {
        return $this->position?->jobLevel;
    }

    /**
     * @param  string|null  $currency  ISO code to render a fixed amount in.
     *                                 Left to the caller rather than read off
     *                                 a `business` relation here, so this
     *                                 never risks a lazy load — pass the
     *                                 business's `base_currency` (from
     *                                 TenantContext, or already loaded).
     */
    public function commissionLabel(?string $currency = null): string
    {
        return match ($this->commission_type) {
            'percent_order' => rtrim(rtrim(number_format((float) $this->commission_rate, 2), '0'), '.') . '% of each order',
            'fixed_order' => \App\Domain\Money\Currencies::symbol($currency ?: 'USD') . number_format((float) $this->commission_rate, 2) . ' per order',
            default => 'No commission',
        };
    }

    public function hasLogin(): bool
    {
        return $this->linked_user_id !== null;
    }

    /**
     * Years and months served, for a record card rather than a calculation.
     */
    public function tenure(): string
    {
        if (! $this->joined_on) {
            return '0m';
        }

        $end = $this->left_on ?? now();
        $months = $this->joined_on->diffInMonths($end);

        $years = intdiv((int) $months, 12);
        $rest = (int) $months % 12;

        return match (true) {
            $years > 0 && $rest > 0 => "{$years}y {$rest}m",
            $years > 0 => "{$years}y",
            default => "{$rest}m",
        };
    }

    /**
     * Age in years.
     */
    public function age(): ?int
    {
        return $this->date_of_birth?->age;
    }
}
