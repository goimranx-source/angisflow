<?php

namespace App\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Shared\Concerns\HasPublicId;
use App\Domain\Tenancy\Concerns\BelongsToAccount;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money given to an employee against future pay.
 *
 * An asset, not an expense — the business is owed it back, and it comes back
 * automatically on their next payslip. The employee code goes in every advance
 * transaction description because the advance account is shared: without it
 * there would be no way to say whose balance is whose when reading the ledger.
 */
class StaffAdvance extends Model
{
    use BelongsToAccount, BelongsToBusiness, HasPublicId;

    protected $fillable = [
        'account_id', 'business_id', 'employee_id', 'reference', 'advance_date',
        'amount', 'currency', 'reason', 'advance_entry_id', 'recovered_amount',
        'balance', 'status', 'approved_by_user_id', 'approved_at',
    ];

    protected $casts = [
        'advance_date' => 'date',
        'amount' => 'decimal:2',
        'recovered_amount' => 'decimal:2',
        'balance' => 'decimal:2',
        'approved_at' => 'datetime',
    ];

    public const STATUSES = [
        'active' => 'Active',
        'recovered' => 'Fully recovered',
        'written_off' => 'Written off',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeForEmployee($query, int $employeeId)
    {
        return $query->where('employee_id', $employeeId);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isRecovered(): bool
    {
        return $this->status === 'recovered';
    }

    public function isWrittenOff(): bool
    {
        return $this->status === 'written_off';
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst($this->status);
    }

    /**
     * How much is still owed.
     */
    public function remainingBalance(): float
    {
        return round((float) $this->balance, 2);
    }

    /**
     * Mark as fully recovered.
     */
    public function markRecovered(): void
    {
        $this->update([
            'balance' => 0,
            'recovered_amount' => $this->amount,
            'status' => 'recovered',
        ]);
    }

    /**
     * Record a recovery (partial or full).
     */
    public function recordRecovery(float $recoveredAmount): void
    {
        $newRecovered = round((float) $this->recovered_amount + $recoveredAmount, 2);
        $newBalance = round((float) $this->amount - $newRecovered, 2);

        $this->update([
            'recovered_amount' => $newRecovered,
            'balance' => max($newBalance, 0),
            'status' => $newBalance <= 0.01 ? 'recovered' : 'active',
        ]);
    }
}
