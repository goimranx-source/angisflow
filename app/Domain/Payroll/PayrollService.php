<?php

namespace App\Domain\Payroll;

use App\Domain\Ledger\Ledger;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Payroll\CommissionService;
use App\Domain\Shared\ValueObjects\Money;
use App\Models\Employee;
use App\Models\Payslip;
use App\Models\PayrollRun;
use App\Models\StaffAdvance;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Payroll, run as a batch — which is the only way it works at any scale.
 *
 * A thousand employees cannot be paid one form at a time, and no serious system
 * asks anyone to. A run covers a period and a scope, builds a payslip for every
 * employee in it, and produces one document a manager can take to whoever holds
 * the money.
 *
 * The accounting is two entries, and the split matters:
 *
 *   On approval   DR Staff Salaries (6450)   CR Accrued Salaries (2100)
 *                 The wage bill belongs to the month it was earned in, whether
 *                 or not anyone has been paid yet.
 *
 *   On payment    DR Accrued Salaries (2100)  CR Cash / Bank
 *                 The debt clears. The expense does not move — it was never in
 *                 doubt, only unpaid.
 *
 * Posting only when money goes out would drop a month's wages into whichever
 * month the cash happened to leave, which is precisely the distortion accrual
 * accounting exists to prevent. It also hides what a business owes: a month
 * closed with wages unpaid would show a profit it does not have.
 */
class PayrollService
{
    public const SALARY_EXPENSE_CODE = '6450';
    public const ACCRUED_CODE = '2100';
    public const ADVANCE_CODE = '1350';

    /** Where wages and advances are paid from when the caller does not say. */
    public const DEFAULT_MONEY_CODE = '1100';

    public function __construct(
        private readonly Ledger $ledger,
        private readonly CommissionService $commissionService,
    ) {}

    /**
     * Open a run and build a payslip for everyone in scope.
     *
     * @param  array{location_id?:?int, department_id?:?int, notes?:?string}  $scope
     */
    public function createRun(int $businessId, string $periodStart, string $periodEnd, array $scope = []): PayrollRun
    {
        $accountId = DB::table('businesses')->where('id', $businessId)->value('account_id');

        if (! $accountId) {
            throw new InvalidArgumentException("Business {$businessId} not found.");
        }

        return DB::transaction(function () use ($accountId, $businessId, $periodStart, $periodEnd, $scope) {
            $run = PayrollRun::create([
                'account_id' => $accountId,
                'business_id' => $businessId,
                'reference' => $this->nextReference($businessId, $periodStart, $scope['location_id'] ?? null),
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'location_id' => $scope['location_id'] ?? null,
                'department_id' => $scope['department_id'] ?? null,
                'status' => 'draft',
                'notes' => $scope['notes'] ?? null,
            ]);

            $this->rebuild($run);

            return $run->refresh();
        });
    }

    /**
     * Rebuild every payslip in a draft run from what the employees say today.
     *
     * Only a draft. Once approved the payslips are a record of what was paid,
     * and recalculating them would rewrite history to match a later raise.
     */
    public function rebuild(PayrollRun $run): PayrollRun
    {
        if (! $run->isEditable()) {
            throw new InvalidArgumentException('Only a draft run can be rebuilt.');
        }

        return DB::transaction(function () use ($run) {
            $run->payslips()->delete();

            $employees = $this->employeesFor($run);
            $days = $this->workingDays($run);

            foreach ($employees as $employee) {
                $this->buildPayslip($run, $employee, $days);
            }

            return $this->refreshTotals($run);
        });
    }

    /** Who this run covers. */
    public function employeesFor(PayrollRun $run)
    {
        return Employee::where('business_id', $run->business_id)
            ->payable()
            ->when($run->location_id, fn ($q) => $q->where('location_id', $run->location_id))
            ->when($run->department_id, fn ($q) => $q->where('department_id', $run->department_id))
            // Anyone who joined after the period ended, or left before it began,
            // was not employed for any of it.
            ->whereDate('joined_on', '<=', $run->period_end)
            ->where(fn ($q) => $q->whereNull('left_on')->orWhereDate('left_on', '>=', $run->period_start))
            ->with(['position', 'department'])
            ->orderBy('name')
            ->get();
    }

    /**
     * One employee's payslip: base pay, then anything owed to or by them.
     *
     * Base pay is pro-rated for a part month. Someone who joined on the 20th is
     * paid for eleven days, and paying them a full month because the system
     * only knows how to multiply by one is a real and expensive mistake.
     */
    private function buildPayslip(PayrollRun $run, Employee $employee, int $days): Payslip
    {
        $worked = $this->daysWorked($run, $employee);
        $rate = (float) $employee->base_salary;

        [$base, $quantity, $unitRate] = match ($employee->pay_type) {
            'daily' => [round($rate * $worked, 2), $worked, $rate],
            'hourly' => [0.0, null, $rate],   // hours are entered, never assumed
            default => $worked >= $days
                ? [$rate, null, null]
                : [round($rate * $worked / max($days, 1), 2), $worked, round($rate / max($days, 1), 4)],
        };

        $payslip = $run->payslips()->create([
            'account_id' => $run->account_id,
            'business_id' => $run->business_id,
            'employee_id' => $employee->id,
            'employee_name' => $employee->name,
            'employee_code' => $employee->code,
            'position_title' => $employee->position?->title,
            'location_name' => null, // Will be filled when location relation exists
            'base_pay' => $base,
            'payment_method' => $employee->payment_method,
        ]);

        if ($base > 0 || $employee->pay_type !== 'hourly') {
            $payslip->lines()->create([
                'kind' => 'earning',
                'code' => 'base',
                'label' => $worked < $days && $employee->pay_type === 'monthly'
                    ? "Basic pay ({$worked} of {$days} days)"
                    : 'Basic pay',
                'quantity' => $quantity,
                'rate' => $unitRate,
                'amount' => $base,
            ]);
        }

        // Anything taken in advance comes back here rather than being chased.
        // Capped at the pay: a deduction larger than the wage would produce a
        // negative payslip, which is not a thing anyone can hand over.
        $advance = $this->advanceBalance($employee->id);

        if ($advance > 0.005) {
            $payslip->lines()->create([
                'kind' => 'deduction',
                'code' => 'advance',
                'label' => 'Advance recovered',
                'amount' => min($advance, $base),
                'settles_ledger_account_code' => self::ADVANCE_CODE,
            ]);
        }

        // Add commission if employee earns it
        if ($employee->commission_type !== 'none' && $employee->linked_user_id) {
            $commissionData = $this->commissionService->calculate(
                $employee,
                $run->period_start->toDateString(),
                $run->period_end->toDateString()
            );

            if ($commissionData['total'] > 0) {
                // Count only orders that actually contributed to commission
                $countableOrders = count(array_filter(
                    $commissionData['order_details'],
                    fn($detail) => $detail['countable']
                ));
                
                $payslip->lines()->create([
                    'kind' => 'earning',
                    'code' => 'commission',
                    'label' => "Commission ({$countableOrders} " . 
                              ($countableOrders === 1 ? 'order' : 'orders') . ")",
                    'quantity' => $countableOrders,
                    'rate' => $countableOrders > 0 
                        ? round($commissionData['total'] / $countableOrders, 4) 
                        : null,
                    'amount' => $commissionData['total'],
                ]);
            }
        }

        return $this->refreshPayslip($payslip);
    }

    /** Add a line by hand — overtime, a bonus, a fine, a commission. */
    public function addLine(Payslip $payslip, string $kind, string $label, float $amount, array $detail = []): Payslip
    {
        if (! $payslip->run->isEditable()) {
            throw new InvalidArgumentException('This run has been approved — its payslips can no longer be changed.');
        }

        $payslip->lines()->create([
            'kind' => $kind === 'deduction' ? 'deduction' : 'earning',
            'code' => $detail['code'] ?? 'manual',
            'label' => $label,
            'quantity' => $detail['quantity'] ?? null,
            'rate' => $detail['rate'] ?? null,
            'amount' => round($amount, 2),
            'settles_ledger_account_code' => $detail['settles_ledger_account_code'] ?? null,
        ]);

        $this->refreshPayslip($payslip);

        return tap($payslip)->setRelation('run', $this->refreshTotals($payslip->run));
    }

    public function removeLine(Payslip $payslip, int $lineId): Payslip
    {
        if (! $payslip->run->isEditable()) {
            throw new InvalidArgumentException('This run has been approved — its payslips can no longer be changed.');
        }

        $payslip->lines()->whereKey($lineId)->delete();

        $this->refreshPayslip($payslip);
        $this->refreshTotals($payslip->run);

        return $payslip->refresh();
    }

    /**
     * Approve the run and recognise the cost.
     *
     * One entry for the whole run, not one per person. A hundred identical
     * postings say nothing a single total doesn't, and they bury every other
     * transaction that month.
     *
     * The entry is DR 6450 Staff Salaries / CR 2100 Accrued Salaries for the
     * common case. When a payslip carries a deduction that settles a balance
     * elsewhere — an advance recovered against 1350 — that portion is credited
     * straight to that account instead of to Accrued Salaries, because the
     * value did not vanish, it went to clear what the employee already owed.
     * Accrued Salaries always ends up equal to net pay: the cash the business
     * will actually hand over on payday.
     *
     * Recovering the advance itself (decrementing the balance the employee
     * owes) happens here too, once, because a draft run can be rebuilt any
     * number of times before approval and rebuilding must not touch a balance
     * that is meant to move only when pay is truly earned.
     */
    public function approve(PayrollRun $run, ?int $approvedByUserId = null): PayrollRun
    {
        if (! $run->isDraft()) {
            throw new InvalidArgumentException('Only a draft run can be approved.');
        }

        $this->refreshTotals($run);
        $run->refresh();

        if ($run->employee_count === 0) {
            throw new InvalidArgumentException('This run has no employees in it.');
        }

        return DB::transaction(function () use ($run, $approvedByUserId) {
            $currency = $this->businessCurrency($run->business_id);

            $payslips = $run->payslips()->with('lines')->get();

            $grossTotal = Money::fromDecimalString((string) $run->gross_total, $currency);
            $netTotal = Money::fromDecimalString((string) $run->net_total, $currency);

            // Deductions that settle a balance elsewhere (advance recovery,
            // eventually expense claims), summed per account across every
            // payslip in the run — one line per account, not one per person.
            $settledByCode = [];

            foreach ($payslips as $payslip) {
                foreach ($payslip->lines as $line) {
                    if ($line->kind !== 'deduction' || ! $line->settles_ledger_account_code) {
                        continue;
                    }

                    $settledByCode[$line->settles_ledger_account_code] =
                        ($settledByCode[$line->settles_ledger_account_code] ?? 0)
                        + Money::fromDecimalString((string) $line->amount, $currency)->minor;
                }
            }

            $settledTotal = array_sum($settledByCode);

            // Deductions with nowhere named to settle (a fine, a manual
            // adjustment) reduce what the business recognises as cost — they
            // were never going to be paid to anyone, so they should not sit in
            // the expense either.
            $unsettledDeductions = $grossTotal->minor - $netTotal->minor - $settledTotal;

            $expenseAmount = new Money($grossTotal->minor - $unsettledDeductions, $currency);

            $salaryExpense = $this->account($run->business_id, self::SALARY_EXPENSE_CODE);
            $accrued = $this->account($run->business_id, self::ACCRUED_CODE);

            $lines = [
                ['account' => $salaryExpense, 'debit' => $expenseAmount, 'description' => "Wages accrued — {$run->reference}"],
                ['account' => $accrued, 'credit' => $netTotal, 'description' => "Wages accrued — {$run->reference}"],
            ];

            foreach ($settledByCode as $code => $minor) {
                if ($minor <= 0) {
                    continue;
                }

                $lines[] = [
                    'account' => $this->account($run->business_id, $code),
                    'credit' => new Money($minor, $currency),
                    'description' => "Settled against {$run->reference}",
                ];
            }

            $entry = $this->ledger->post(
                $run->period_end->toDateString(),
                "Payroll accrual — {$run->reference}",
                $lines,
                [
                    'source' => 'payroll',
                    'source_ref' => $run->reference,
                    'subject_type' => PayrollRun::class,
                    'subject_id' => $run->id,
                ],
            );

            // Recover advances once, now that the run is a permanent record.
            foreach ($payslips as $payslip) {
                foreach ($payslip->lines as $line) {
                    if ($line->kind === 'deduction' && $line->settles_ledger_account_code === self::ADVANCE_CODE) {
                        $this->recoverAdvances($payslip->employee_id, (float) $line->amount);
                    }
                }
            }

            $run->update([
                'status' => 'approved',
                'accrual_entry_id' => $entry->id,
                'approved_by_user_id' => $approvedByUserId,
                'approved_at' => now(),
            ]);

            return $run->refresh();
        });
    }

    /**
     * Pay an approved run.
     *
     * Clears the accrual rather than posting a second expense. The cost was
     * recognised on approval; paying it is a movement of money, and recording
     * it as an expense again would double the month's wage bill.
     */
    public function pay(PayrollRun $run, string $paidOn, string $moneyAccountCode = self::DEFAULT_MONEY_CODE): PayrollRun
    {
        if (! $run->isApproved()) {
            throw new InvalidArgumentException('Only an approved run can be paid.');
        }

        return DB::transaction(function () use ($run, $paidOn, $moneyAccountCode) {
            $currency = $this->businessCurrency($run->business_id);
            $netTotal = Money::fromDecimalString((string) $run->net_total, $currency);

            $accrued = $this->account($run->business_id, self::ACCRUED_CODE);
            $moneyAccount = $this->account($run->business_id, $moneyAccountCode);

            $description = "Wages paid — {$run->reference}";

            $entry = $this->ledger->post($paidOn, $description, [
                ['account' => $accrued, 'debit' => $netTotal, 'description' => $description],
                ['account' => $moneyAccount, 'credit' => $netTotal, 'description' => $description],
            ], [
                'source' => 'payroll',
                'source_ref' => $run->reference,
                'subject_type' => PayrollRun::class,
                'subject_id' => $run->id,
            ]);

            $run->update([
                'status' => 'paid',
                'payment_entry_id' => $entry->id,
                'paid_on' => $paidOn,
            ]);

            return $run->refresh();
        });
    }

    /**
     * Give an advance to an employee against future pay.
     *
     * An asset, not an expense — the business is owed it back, and it comes
     * back automatically on their next payslip.
     */
    public function giveAdvance(
        int $employeeId,
        float $amount,
        string $date,
        ?string $reason = null,
        ?int $approvedByUserId = null,
        string $moneyAccountCode = self::DEFAULT_MONEY_CODE,
    ): StaffAdvance {
        $employee = Employee::findOrFail($employeeId);

        if ($amount <= 0) {
            throw new InvalidArgumentException('Advance amount must be greater than zero.');
        }

        return DB::transaction(function () use ($employee, $amount, $date, $reason, $approvedByUserId, $moneyAccountCode) {
            $currency = $this->businessCurrency($employee->business_id);
            $money = Money::fromDecimalString(sprintf('%.2f', $amount), $currency);

            $advanceAccount = $this->account($employee->business_id, self::ADVANCE_CODE);
            $moneyAccount = $this->account($employee->business_id, $moneyAccountCode);

            $reference = $this->nextAdvanceReference($employee);
            // The employee code is in the description because the advance
            // account is shared by everyone — without it there is no way to
            // say whose balance moved when reading the ledger.
            $description = "Advance to {$employee->name} ({$employee->code}) — {$reference}";

            $entry = $this->ledger->post($date, $description, [
                ['account' => $advanceAccount, 'debit' => $money, 'description' => $description],
                ['account' => $moneyAccount, 'credit' => $money, 'description' => $description],
            ], [
                'source' => 'payroll',
                'source_ref' => $reference,
                'subject_type' => Employee::class,
                'subject_id' => $employee->id,
            ]);

            return StaffAdvance::create([
                'account_id' => $employee->account_id,
                'business_id' => $employee->business_id,
                'employee_id' => $employee->id,
                'reference' => $reference,
                'advance_date' => $date,
                'amount' => $amount,
                'currency' => $currency,
                'reason' => $reason,
                'advance_entry_id' => $entry->id,
                'balance' => $amount,
                'status' => 'active',
                'approved_by_user_id' => $approvedByUserId,
                'approved_at' => $approvedByUserId ? now() : null,
            ]);
        });
    }

    /**
     * Recover an advance recovery deduction against the employee's oldest
     * outstanding advances first, so a partial recovery always clears the
     * longest-standing debt before touching a newer one.
     */
    private function recoverAdvances(int $employeeId, float $amount): void
    {
        $remaining = round($amount, 2);

        if ($remaining <= 0) {
            return;
        }

        $advances = StaffAdvance::where('employee_id', $employeeId)
            ->active()
            ->orderBy('advance_date')
            ->orderBy('id')
            ->get();

        foreach ($advances as $advance) {
            if ($remaining <= 0.005) {
                break;
            }

            $take = min($remaining, (float) $advance->balance);

            if ($take <= 0) {
                continue;
            }

            $advance->recordRecovery($take);
            $remaining = round($remaining - $take, 2);
        }
    }

    private function account(int $businessId, string $code): LedgerAccount
    {
        return LedgerAccount::where('business_id', $businessId)
            ->where('code', $code)
            ->firstOrFail();
    }

    private function businessCurrency(int $businessId): string
    {
        $currency = DB::table('businesses')->where('id', $businessId)->value('base_currency');

        if (! $currency) {
            throw new RuntimeException("Business {$businessId} has no base currency set.");
        }

        return strtoupper($currency);
    }

    /**
     * Cancel a draft run.
     */
    public function cancel(PayrollRun $run): PayrollRun
    {
        if (! $run->isDraft()) {
            throw new InvalidArgumentException('Only a draft run can be cancelled.');
        }

        $run->update(['status' => 'cancelled']);

        return $run;
    }

    private function refreshPayslip(Payslip $payslip): Payslip
    {
        $lines = $payslip->lines()->get();

        $earnings = round($lines->where('kind', 'earning')->sum('amount'), 2);
        $deductions = round($lines->where('kind', 'deduction')->sum('amount'), 2);

        $payslip->update([
            'earnings_total' => $earnings,
            'deductions_total' => $deductions,
            'gross_pay' => $earnings,
            'net_pay' => round($earnings - $deductions, 2),
        ]);

        return $payslip->refresh();
    }

    private function refreshTotals(PayrollRun $run): PayrollRun
    {
        $payslips = $run->payslips()->get();

        $run->update([
            'gross_total' => round($payslips->sum('gross_pay'), 2),
            'deduction_total' => round($payslips->sum('deductions_total'), 2),
            'net_total' => round($payslips->sum('net_pay'), 2),
            'employee_count' => $payslips->count(),
        ]);

        return $run->refresh();
    }

    /** Calendar days in the period — the denominator a part month is scaled by. */
    private function workingDays(PayrollRun $run): int
    {
        return (int) $run->period_start->diffInDays($run->period_end) + 1;
    }

    /** Days of the period this employee was actually employed for. */
    private function daysWorked(PayrollRun $run, Employee $employee): int
    {
        $start = $employee->joined_on && $employee->joined_on->greaterThan($run->period_start)
            ? $employee->joined_on
            : $run->period_start;

        $end = $employee->left_on && $employee->left_on->lessThan($run->period_end)
            ? $employee->left_on
            : $run->period_end;

        return max((int) $start->diffInDays($end) + 1, 0);
    }

    /** PAY-202607, or PAY-202607-001 when multiple runs exist. */
    public function nextReference(int $businessId, string $periodStart, ?int $locationId = null): string
    {
        $stem = 'PAY-' . Carbon::parse($periodStart)->format('Ym');

        // When locations exist, append location code
        // if ($locationId && $code = Location::find($locationId)?->code) {
        //     $stem .= '-' . $code;
        // }

        $taken = PayrollRun::where('business_id', $businessId)
            ->where('reference', 'like', $stem . '%')
            ->pluck('reference');

        if (! $taken->contains($stem)) {
            return $stem;
        }

        $suffix = 2;
        while ($taken->contains($stem . '-' . str_pad((string) $suffix, 3, '0', STR_PAD_LEFT))) {
            $suffix++;
        }

        return $stem . '-' . str_pad((string) $suffix, 3, '0', STR_PAD_LEFT);
    }

    /** ADV-EMP001-001, ADV-EMP001-002, etc. */
    private function nextAdvanceReference(Employee $employee): string
    {
        $stem = 'ADV-' . ($employee->code ?: 'EMP' . $employee->id);

        $taken = StaffAdvance::where('employee_id', $employee->id)
            ->where('reference', 'like', $stem . '%')
            ->pluck('reference');

        $suffix = 1;
        while ($taken->contains($stem . '-' . str_pad((string) $suffix, 3, '0', STR_PAD_LEFT))) {
            $suffix++;
        }

        return $stem . '-' . str_pad((string) $suffix, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Total outstanding advances for an employee.
     */
    private function advanceBalance(int $employeeId): float
    {
        return round(
            (float) StaffAdvance::where('employee_id', $employeeId)
                ->active()
                ->sum('balance'),
            2
        );
    }
}
