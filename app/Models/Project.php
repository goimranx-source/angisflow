<?php

namespace App\Models;

use App\Domain\Tenancy\Concerns\BelongsToAccount;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use App\Domain\Shared\Concerns\HasPublicId;
use App\Domain\Shared\ValueObjects\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A project is a trackable unit of work with budget, timeline, and billing configuration.
 *
 * Projects serve as containers for time entries and can be:
 * - Client work (billable)
 * - Internal initiatives (non-billable)
 * - Overhead activities (admin, sales, etc.)
 *
 * ## Budget tracking
 * Projects track both time budget (hours) and financial budget (money).
 * This enables early warning when approaching limits and profitability analysis.
 *
 * ## Billing configuration
 * - `hourly`: Bill at hourly rates (project default or employee-specific)
 * - `fixed_rate`: Bill fixed amount regardless of time spent
 * - `non_billable`: Internal project, no client billing
 *
 * ## Status workflow
 * planning → active → completed/cancelled
 * on_hold can occur from planning or active
 */
class Project extends Model
{
    use BelongsToAccount,
        BelongsToBusiness,
        HasPublicId,
        SoftDeletes;

    protected $fillable = [
        'account_id',
        'business_id',
        'code',
        'name',
        'description',
        'customer_id',
        'project_manager_id',
        'status',
        'start_date',
        'end_date',
        'budget_hours',
        'budget_amount_minor',
        'currency',
        'billing_type',
        'default_rate_minor',
        'requires_approval',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'budget_hours' => 'decimal:2',
        'requires_approval' => 'boolean',
        'is_active' => 'boolean',
    ];

    // ── Relationships ───────────────────────────────────────────────────

    public function customer(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Sales\Models\Customer::class);
    }

    public function projectManager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'project_manager_id');
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    public function employeeRates(): HasMany
    {
        return $this->hasMany(ProjectEmployeeRate::class);
    }

    public function budgetRevisions(): HasMany
    {
        return $this->hasMany(ProjectBudgetRevision::class);
    }

    // ── Scopes ──────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeBillable($query)
    {
        return $query->whereIn('billing_type', ['hourly', 'fixed_rate']);
    }

    public function scopeForEmployee($query, int $employeeId)
    {
        return $query->whereHas('timeEntries', function ($q) use ($employeeId) {
            $q->where('employee_id', $employeeId);
        });
    }

    // ── Status checks ───────────────────────────────────────────────────

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function canLogTime(): bool
    {
        return in_array($this->status, ['planning', 'active']) && $this->is_active;
    }

    public function isBillable(): bool
    {
        return in_array($this->billing_type, ['hourly', 'fixed_rate']);
    }

    // ── Budget methods ──────────────────────────────────────────────────

    public function getBudgetAmount(): ?Money
    {
        if ($this->budget_amount_minor === null) {
            return null;
        }

        return new Money($this->budget_amount_minor, $this->currency);
    }

    public function setBudgetAmount(?Money $money): void
    {
        if ($money === null) {
            $this->budget_amount_minor = null;
        } else {
            $this->budget_amount_minor = $money->minor;
            $this->currency = $money->currency;
        }
    }

    public function getDefaultRate(): ?Money
    {
        if ($this->default_rate_minor === null) {
            return null;
        }

        return new Money($this->default_rate_minor, $this->currency);
    }

    public function setDefaultRate(?Money $money): void
    {
        if ($money === null) {
            $this->default_rate_minor = null;
        } else {
            $this->default_rate_minor = $money->minor;
            $this->currency = $money->currency;
        }
    }

    // ── Time tracking calculations ──────────────────────────────────────

    /**
     * Get total hours logged across all time entries.
     */
    public function getTotalHours(): float
    {
        return (float) $this->timeEntries()->sum('hours');
    }

    /**
     * Get billable hours (approved time entries only).
     */
    public function getBillableHours(): float
    {
        return (float) $this->timeEntries()
            ->where('is_billable', true)
            ->whereIn('status', ['approved', 'invoiced'])
            ->sum('hours');
    }

    /**
     * Get total billable amount (approved entries only).
     */
    public function getBillableAmount(): Money
    {
        $total = $this->timeEntries()
            ->where('is_billable', true)
            ->whereIn('status', ['approved', 'invoiced'])
            ->sum('billable_amount_minor');

        return new Money($total ?? 0, $this->currency);
    }

    /**
     * Check if project is over budget (hours or amount).
     */
    public function isOverBudget(): array
    {
        $result = ['hours' => false, 'amount' => false];

        if ($this->budget_hours && $this->getTotalHours() > $this->budget_hours) {
            $result['hours'] = true;
        }

        if ($this->budget_amount_minor) {
            $billableAmount = $this->getBillableAmount();
            if ($billableAmount->minor > $this->budget_amount_minor) {
                $result['amount'] = true;
            }
        }

        return $result;
    }

    /**
     * Get budget utilization percentages.
     */
    public function getBudgetUtilization(): array
    {
        $result = [];

        if ($this->budget_hours) {
            $result['hours'] = round(($this->getTotalHours() / $this->budget_hours) * 100, 1);
        }

        if ($this->budget_amount_minor) {
            $billableAmount = $this->getBillableAmount();
            $result['amount'] = round(($billableAmount->minor / $this->budget_amount_minor) * 100, 1);
        }

        return $result;
    }

    // ── Helper methods ──────────────────────────────────────────────────

    /**
     * Get the next available project code.
     */
    public static function nextCode(int $businessId, ?string $prefix = null): string
    {
        $prefix = $prefix ?: 'PRJ';

        $last = self::where('business_id', $businessId)
            ->where('code', 'like', "{$prefix}%")
            ->orderByRaw('CAST(SUBSTR(code, LENGTH(?) + 1) AS INTEGER) DESC', [$prefix])
            ->value('code');

        if (!$last) {
            return "{$prefix}001";
        }

        $number = (int) substr($last, strlen($prefix));
        return $prefix . str_pad((string)($number + 1), 3, '0', STR_PAD_LEFT);
    }

    /**
     * Get effective rate for an employee on this project.
     */
    public function getEmployeeRate(Employee $employee, string $date): ?Money
    {
        // Check for employee-specific rate
        $employeeRate = $this->employeeRates()
            ->where('employee_id', $employee->id)
            ->where('effective_from', '<=', $date)
            ->where(function ($q) use ($date) {
                $q->whereNull('effective_to')
                  ->orWhere('effective_to', '>=', $date);
            })
            ->where('is_active', true)
            ->latest('effective_from')
            ->first();

        if ($employeeRate) {
            return new Money($employeeRate->rate_minor, $this->currency);
        }

        // Fall back to project default rate
        return $this->getDefaultRate();
    }
}