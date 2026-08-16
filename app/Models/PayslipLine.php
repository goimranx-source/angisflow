<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a payslip — a component of pay, not a total.
 *
 * Kept as lines rather than a single net figure because "why is this less than
 * last month" has to have an answer on the paper. A deduction nobody can
 * explain is how payroll disputes start.
 */
class PayslipLine extends Model
{
    protected $fillable = [
        'payslip_id', 'kind', 'code', 'label', 'quantity', 'rate', 'amount',
        'settles_ledger_account_code',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'rate' => 'decimal:4',
        'amount' => 'decimal:2',
    ];

    public function payslip(): BelongsTo
    {
        return $this->belongsTo(Payslip::class);
    }

    public function isEarning(): bool
    {
        return $this->kind === 'earning';
    }

    public function isDeduction(): bool
    {
        return $this->kind === 'deduction';
    }

    /** A line that clears a balance elsewhere, such as a staff advance. */
    public function settlesBalance(): bool
    {
        return $this->settles_ledger_account_code !== null;
    }

    /** "5 × $250" where a rate was used, otherwise nothing. */
    public function workingLabel(): ?string
    {
        if ($this->quantity === null || $this->rate === null) {
            return null;
        }

        return rtrim(rtrim(number_format((float) $this->quantity, 3), '0'), '.')
            . ' × $' . number_format((float) $this->rate, 2);
    }
}
