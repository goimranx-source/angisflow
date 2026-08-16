<?php

namespace App\Models;

use App\Domain\Shared\Concerns\HasPublicId;
use App\Domain\Tenancy\Concerns\BelongsToAccount;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What one person was paid for one period.
 *
 * The employee's name, code, position and location are copied here rather than
 * read through the relation. A payslip states what was true that month; a later
 * raise, transfer or promotion must not rewrite what someone was already paid.
 */
class Payslip extends Model
{
    use BelongsToAccount, BelongsToBusiness, HasPublicId;

    protected $fillable = [
        'account_id', 'business_id', 'payroll_run_id', 'employee_id',
        'employee_name', 'employee_code', 'position_title', 'location_name',
        'base_pay', 'earnings_total', 'deductions_total', 'gross_pay', 'net_pay',
        'payment_method', 'notes',
    ];

    protected $casts = [
        'base_pay' => 'decimal:2',
        'earnings_total' => 'decimal:2',
        'deductions_total' => 'decimal:2',
        'gross_pay' => 'decimal:2',
        'net_pay' => 'decimal:2',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PayslipLine::class)->orderBy('kind')->orderBy('id');
    }

    public function earnings(): HasMany
    {
        return $this->hasMany(PayslipLine::class)->where('kind', 'earning');
    }

    public function deductions(): HasMany
    {
        return $this->hasMany(PayslipLine::class)->where('kind', 'deduction');
    }

    /**
     * Short summary for display.
     */
    public function summary(): string
    {
        return "{$this->employee_name} · " . number_format((float) $this->net_pay, 2);
    }
}
