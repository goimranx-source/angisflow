<?php

namespace App\Models;

use App\Domain\Tenancy\Concerns\BelongsToAccount;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use App\Domain\Shared\Concerns\HasPublicId;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A time entry is an atomic unit of time tracking.
 *
 * Each entry represents work done by one employee on one project for a
 * specific time period. This granular approach enables:
 * - Accurate project costing
 * - Flexible billing (different rates per project/employee)
 * - Mixed billable/non-billable work tracking
 * - Detailed time reporting
 *
 * ## Workflow States
 * - `draft`: Employee working on entry, not yet submitted
 * - `submitted`: Ready for manager review
 * - `approved`: Can be included in client billing
 * - `rejected`: Needs revision (returns to draft)
 * - `invoiced`: Already billed to client, immutable
 *
 * ## Billing calculation
 * When entry moves to approved status, the billable amount is calculated
 * and locked in. This prevents rate changes from affecting already-approved time.
 */
class TimeEntry extends Model
{
    use BelongsToAccount,
        BelongsToBusiness,
        HasPublicId,
        SoftDeletes;

    protected $fillable = [
        'account_id',
        'business_id',
        'project_id',
        'employee_id',
        'entry_date',
        'start_time',
        'end_time',
        'hours',
        'description',
        'is_billable',
        'rate_minor',
        'billable_amount_minor',
        'currency',
        'status',
        'submitted_by_user_id',
        'submitted_at',
        'approved_by_user_id',
        'approved_at',
        'approval_notes',
        'invoice_line_id',
        'invoiced_at',
    ];

    protected $casts = [
        'entry_date' => 'date',
        'start_time' => 'datetime:H:i',
        'end_time' => 'datetime:H:i',
        'hours' => 'decimal:2',
        'is_billable' => 'boolean',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'invoiced_at' => 'datetime',
    ];

    // ── Relationships ───────────────────────────────────────────────────

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function invoiceLine(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Sales\Models\InvoiceLine::class);
    }

    // ── Scopes ──────────────────────────────────────────────────────────

    public function scopeBillable($query)
    {
        return $query->where('is_billable', true);
    }

    public function scopeApproved($query)
    {
        return $query->whereIn('status', ['approved', 'invoiced']);
    }

    public function scopePendingApproval($query)
    {
        return $query->where('status', 'submitted');
    }

    public function scopeForEmployee($query, int $employeeId)
    {
        return $query->where('employee_id', $employeeId);
    }

    public function scopeForProject($query, int $projectId)
    {
        return $query->where('project_id', $projectId);
    }

    public function scopeInPeriod($query, string $from, string $to)
    {
        return $query->whereBetween('entry_date', [$from, $to]);
    }

    // ── Status checks ───────────────────────────────────────────────────

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isSubmitted(): bool
    {
        return $this->status === 'submitted';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    public function isInvoiced(): bool
    {
        return $this->status === 'invoiced';
    }

    public function canEdit(): bool
    {
        return in_array($this->status, ['draft', 'rejected']);
    }

    public function canSubmit(): bool
    {
        return $this->status === 'draft' && $this->hours > 0;
    }

    public function canApprove(): bool
    {
        return $this->status === 'submitted';
    }

    public function canInvoice(): bool
    {
        return $this->status === 'approved' && $this->is_billable;
    }

    // ── Money accessors ─────────────────────────────────────────────────

    public function getRate(): ?Money
    {
        if ($this->rate_minor === null) {
            return null;
        }

        return new Money($this->rate_minor, $this->currency);
    }

    public function setRate(?Money $money): void
    {
        if ($money === null) {
            $this->rate_minor = null;
        } else {
            $this->rate_minor = $money->minor;
            $this->currency = $money->currency;
        }
    }

    public function getBillableAmount(): Money
    {
        return new Money($this->billable_amount_minor ?? 0, $this->currency);
    }

    public function setBillableAmount(Money $money): void
    {
        $this->billable_amount_minor = $money->minor;
        $this->currency = $money->currency;
    }

    // ── Calculation methods ─────────────────────────────────────────────

    /**
     * Calculate billable amount based on hours and rate.
     */
    public function calculateBillableAmount(): Money
    {
        if (!$this->is_billable || !$this->rate_minor) {
            return new Money(0, $this->currency);
        }

        $rate = $this->getRate();
        $amount = (int) round($this->hours * $rate->minor);

        return new Money($amount, $this->currency);
    }

    /**
     * Calculate hours from start/end times if both are set.
     */
    public function calculateHoursFromTimes(): ?float
    {
        if (!$this->start_time || !$this->end_time) {
            return null;
        }

        $start = \Carbon\Carbon::parse($this->start_time);
        $end = \Carbon\Carbon::parse($this->end_time);

        // Handle overnight work (end time next day)
        if ($end->lt($start)) {
            $end->addDay();
        }

        return round($start->diffInMinutes($end) / 60, 2);
    }

    // ── Workflow methods ────────────────────────────────────────────────

    /**
     * Submit entry for approval.
     */
    public function submit(int $userId): bool
    {
        if (!$this->canSubmit()) {
            return false;
        }

        $this->status = 'submitted';
        $this->submitted_by_user_id = $userId;
        $this->submitted_at = now();

        return $this->save();
    }

    /**
     * Approve entry for billing.
     */
    public function approve(int $userId, ?string $notes = null): bool
    {
        if (!$this->canApprove()) {
            return false;
        }

        // Calculate and lock in billable amount
        if ($this->is_billable) {
            $this->setBillableAmount($this->calculateBillableAmount());
        }

        $this->status = 'approved';
        $this->approved_by_user_id = $userId;
        $this->approved_at = now();
        $this->approval_notes = $notes;

        return $this->save();
    }

    /**
     * Reject entry (returns to draft for revision).
     */
    public function reject(int $userId, string $reason): bool
    {
        if (!$this->canApprove()) {
            return false;
        }

        $this->status = 'rejected';
        $this->approved_by_user_id = $userId;
        $this->approved_at = now();
        $this->approval_notes = $reason;

        return $this->save();
    }

    /**
     * Mark entry as invoiced (immutable after this).
     */
    public function markInvoiced(int $invoiceLineId): bool
    {
        if (!$this->canInvoice()) {
            return false;
        }

        $this->status = 'invoiced';
        $this->invoice_line_id = $invoiceLineId;
        $this->invoiced_at = now();

        return $this->save();
    }
}