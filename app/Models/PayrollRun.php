<?php

namespace App\Models;

use App\Domain\Shared\Concerns\HasPublicId;
use App\Domain\Tenancy\Concerns\BelongsToAccount;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One month's wage bill, for one scope, as a single document.
 *
 * The status is the whole story:
 *
 *   draft      being built. Payslips can be recalculated freely.
 *   approved   the wage bill is owed. One accrual entry has been posted, and
 *              the payslips are now a record rather than a working figure.
 *   paid       money has gone out and the debt is cleared.
 *   cancelled  abandoned before approval. Nothing was ever posted.
 */
class PayrollRun extends Model
{
    use BelongsToAccount, BelongsToBusiness, HasPublicId;

    protected $fillable = [
        'account_id', 'business_id', 'reference', 'period_start', 'period_end',
        'location_id', 'department_id', 'status', 'gross_total', 'deduction_total',
        'net_total', 'employee_count', 'accrual_entry_id', 'payment_entry_id',
        'approved_by_user_id', 'approved_at', 'paid_on', 'notes',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'approved_at' => 'datetime',
        'paid_on' => 'date',
        'gross_total' => 'decimal:2',
        'deduction_total' => 'decimal:2',
        'net_total' => 'decimal:2',
        'employee_count' => 'integer',
    ];

    public const STATUSES = [
        'draft' => 'Draft',
        'approved' => 'Approved — awaiting payment',
        'paid' => 'Paid',
        'cancelled' => 'Cancelled',
    ];

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class)->orderBy('employee_name');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    // Note: location relation will be added when locations table is built
    // public function location(): BelongsTo
    // {
    //     return $this->belongsTo(Location::class);
    // }

    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopePaid($query)
    {
        return $query->where('status', 'paid');
    }

    public function scopeForPeriod($query, string $from, string $to)
    {
        return $query->where('period_start', '>=', $from)
            ->where('period_end', '<=', $to);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst($this->status);
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    /** Only a draft may be rebuilt; anything posted is a record. */
    public function isEditable(): bool
    {
        return $this->status === 'draft';
    }

    public function periodLabel(): string
    {
        return $this->period_start->format('j M') . ' – ' . $this->period_end->format('j M Y');
    }

    /**
     * What subset this run covers.
     *
     * A run can be for everyone, one location, one department, or both.
     */
    public function scopeLabel(): string
    {
        $parts = [];

        // When locations table exists, use: $this->location?->name
        // For now, just show if there's a location_id filter
        if ($this->location_id) {
            $parts[] = 'Location #' . $this->location_id;
        }

        if ($this->department) {
            $parts[] = $this->department->name;
        }

        return $parts ? implode(' · ', $parts) : 'Everyone';
    }
}
