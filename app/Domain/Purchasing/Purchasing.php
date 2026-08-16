<?php

declare(strict_types=1);

namespace App\Domain\Purchasing;

use App\Domain\Ledger\Ledger;
use App\Domain\Ledger\Models\JournalEntry;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Purchasing\Models\Bill;
use App\Domain\Purchasing\Models\BillLine;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Approving bills and paying them.
 *
 * ── What approval does to the books ──────────────────────────────────────────
 *
 *     DR  6xxx  the expense, net of tax
 *     DR  2200  Tax Payable — reclaimable tax, reducing what you owe the state
 *     CR  2000  Accounts Payable — what you now owe the supplier
 *
 * The tax line is the part people get wrong. Tax you were charged on a purchase
 * is not a cost: in most regimes it is set against the tax you collected on
 * sales, so it belongs against the same liability rather than in the expense.
 * Booking it as an expense overstates costs by the tax rate and overstates what
 * you owe the state by the same amount — a pair of errors that hide each other
 * until a return is filed.
 *
 * Businesses that cannot reclaim it pass tax_rate zero and put the gross in the
 * expense line, which is the correct treatment for them and needs no special
 * case here.
 */
final class Purchasing
{
    private const AP = '2000';

    private const TAX = '2200';

    private const DEFAULT_EXPENSE = '6500';

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly Ledger $ledger,
    ) {}

    /**
     * Accept a bill and post what it costs.
     */
    public function approve(Bill $bill, ?string $date = null): Bill
    {
        if ($bill->status !== Bill::DRAFT) {
            throw new RuntimeException("{$bill->number} has already been approved.");
        }

        $bill->loadMissing('lines.ledgerAccount', 'supplier');

        if ($bill->lines->isEmpty()) {
            throw new RuntimeException('A bill with no lines charges nothing.');
        }

        $subtotal = 0;
        $tax = 0;

        foreach ($bill->lines as $line) {
            $subtotal += $line->total_minor - $line->tax_minor;
            $tax += $line->tax_minor;
        }

        $total = $subtotal + $tax;

        if ($total <= 0) {
            throw new RuntimeException('This bill comes to nothing. Check the amounts before approving it.');
        }

        return DB::transaction(function () use ($bill, $subtotal, $tax, $total, $date) {
            $billDate = $date ?? $bill->bill_date?->toDateString() ?? now()->toDateString();
            $currency = $bill->currency;

            // Grouped per expense account, so a bill with twenty lines against
            // three accounts posts three debits rather than twenty.
            $byAccount = [];

            foreach ($bill->lines as $line) {
                $code = $line->ledgerAccount?->code
                    ?? $bill->supplier?->defaultExpenseAccount?->code
                    ?? self::DEFAULT_EXPENSE;
                $byAccount[$code] = ($byAccount[$code] ?? 0) + ($line->total_minor - $line->tax_minor);
            }

            $lines = [];

            foreach ($byAccount as $code => $minor) {
                $lines[] = [
                    'account' => (string) $code,
                    'debit' => new Money($minor, $currency),
                    'description' => 'Cost on '.$bill->number,
                ];
            }

            if ($tax > 0) {
                $lines[] = [
                    'account' => self::TAX,
                    'debit' => new Money($tax, $currency),
                    'description' => 'Reclaimable tax on '.$bill->number,
                ];
            }

            $lines[] = [
                'account' => self::AP,
                'credit' => new Money($total, $currency),
                'description' => 'Owed on '.$bill->number,
            ];

            $entry = $this->ledger->post(
                $billDate,
                "Bill {$bill->number}".($bill->supplier ? " — {$bill->supplier->name}" : ''),
                $lines,
                ['source' => 'system', 'subject_type' => Bill::class, 'subject_id' => $bill->id],
            );

            $bill->forceFill([
                'status' => Bill::APPROVED,
                'subtotal_minor' => $subtotal,
                'tax_minor' => $tax,
                'total_minor' => $total,
                'due_date' => $bill->due_date ?? $this->dueDateFor($bill, $billDate),
                'journal_entry_id' => $entry->id,
                'approved_at' => now(),
                'approved_by' => auth()->id(),
            ])->save();

            return $bill->refresh();
        });
    }

    /**
     * Pay a bill, in full or in part.
     *
     *     DR  2000  Accounts Payable   the debt goes down
     *     CR  1000/1100                the money leaves
     */
    public function pay(Bill $bill, Money $amount, LedgerAccount|string $from, string $paidOn): Bill
    {
        if ($bill->status !== Bill::APPROVED) {
            throw new RuntimeException("{$bill->number} has not been approved, so there is nothing to pay yet.");
        }

        if (! $amount->isPositive()) {
            throw new RuntimeException('A payment has to be for something.');
        }

        if ($amount->currency !== $bill->currency) {
            throw new RuntimeException(
                "That payment is in {$amount->currency} and {$bill->number} is in {$bill->currency}. Converting between them is a separate decision with its own rate."
            );
        }

        $source = $from instanceof LedgerAccount
            ? $from
            : LedgerAccount::where('code', $from)->firstOr(fn () => throw new RuntimeException("No account with code {$from} in this book."));

        if (! $source->is_spendable) {
            throw new RuntimeException(
                "{$source->code} {$source->name} is not a cash or bank account, so money cannot be paid out of it."
            );
        }

        return DB::transaction(function () use ($bill, $amount, $source, $paidOn) {
            $bill = Bill::lockForUpdate()->find($bill->id);
            $owed = $bill->total_minor - $bill->paid_minor;

            if ($amount->minor > $owed) {
                throw new RuntimeException(sprintf(
                    '%s only has %s outstanding.',
                    $bill->number,
                    (new Money($owed, $bill->currency))->toDecimalString(),
                ));
            }

            $this->ledger->post(
                $paidOn,
                "Paid {$bill->number}".($bill->supplier ? " — {$bill->supplier->name}" : ''),
                [
                    ['account' => self::AP, 'debit' => $amount, 'description' => 'Settles '.$bill->number],
                    ['account' => $source, 'credit' => $amount, 'description' => 'Paid out for '.$bill->number],
                ],
                ['source' => 'system', 'subject_type' => Bill::class, 'subject_id' => $bill->id],
            );

            $paid = $bill->paid_minor + $amount->minor;

            $bill->forceFill([
                'paid_minor' => $paid,
                'status' => $paid >= $bill->total_minor ? Bill::PAID : $bill->status,
            ])->save();

            return $bill->refresh();
        });
    }

    public function void(Bill $bill, ?string $reason = null): Bill
    {
        if ($bill->paid_minor > 0) {
            throw new RuntimeException(
                "{$bill->number} has been paid against. Reverse the payment first — the money left the account even if the bill was wrong."
            );
        }

        return DB::transaction(function () use ($bill, $reason) {
            if ($bill->journalEntry !== null && $bill->journalEntry->status !== JournalEntry::REVERSED) {
                $this->ledger->reverse($bill->journalEntry, null, $reason ?? "Bill {$bill->number} voided");
            }

            $bill->forceFill(['status' => Bill::VOID, 'voided_at' => now()])->save();

            return $bill->refresh();
        });
    }

    public function priceLine(BillLine $line): BillLine
    {
        $net = (int) round((float) $line->quantity * $line->unit_price_minor);
        $tax = (int) round($net * ((float) $line->tax_rate / 100));

        $line->tax_minor = $tax;
        $line->total_minor = $net + $tax;

        return $line;
    }

    /** BILL-2026-0001, restarting each year. */
    public function nextNumber(?string $date = null): string
    {
        $prefix = 'BILL-'.substr($date ?? now()->toDateString(), 0, 4).'-';

        $last = Bill::query()
            ->where('business_id', $this->tenant->business()->id)
            ->where('number', 'like', $prefix.'%')
            ->orderByDesc('number')
            ->value('number');

        return $prefix.str_pad((string) ($last === null ? 1 : ((int) substr($last, strlen($prefix))) + 1), 4, '0', STR_PAD_LEFT);
    }

    private function dueDateFor(Bill $bill, string $billDate): string
    {
        $days = $bill->supplier?->payment_terms_days ?? 30;

        return date('Y-m-d', strtotime($billDate." +{$days} days"));
    }
}
