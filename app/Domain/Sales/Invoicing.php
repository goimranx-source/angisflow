<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use App\Domain\Ledger\Ledger;
use App\Domain\Ledger\Models\JournalEntry;
use App\Domain\Sales\Models\Invoice;
use App\Domain\Sales\Models\InvoiceLine;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Tenancy\Models\Business;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Issuing invoices, and what that does to the books.
 *
 * ── What "issue" means to the ledger ─────────────────────────────────────────
 *
 * A sale on credit is money owed to you, earned by you, and tax you are holding
 * for somebody else. Three facts, three postings:
 *
 *     DR  1200 Accounts Receivable      the customer's debt to you
 *     CR  4100 Sales Income             what you earned
 *     CR  2200 Tax Payable              what you collected on the state's behalf
 *
 * Tax is a liability, not income — it was never yours. Booking it as revenue
 * overstates every sale by the tax rate and understates what you owe by the
 * same amount, and the two errors cancel in the profit figure so nothing looks
 * wrong until a return is filed.
 *
 * Line accounts are used where lines carry them, so goods, delivery and service
 * income land in the accounts they belong to rather than being merged into one.
 *
 * ── Why a draft posts nothing ────────────────────────────────────────────────
 *
 * Because it is not a debt. Nobody has been asked for anything, and a receivable
 * balance that includes half-written invoices is a receivable balance nobody can
 * chase from. Posting happens exactly once, at issue.
 */
final class Invoicing
{
    private const AR = '1200';

    private const TAX = '2200';

    private const DEFAULT_REVENUE = '4100';

    private const DISCOUNT = '4950';

    private const SHIPPING = '4150';

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly Ledger $ledger,
    ) {}

    /**
     * Freeze the totals, post to the ledger, and make it a document.
     *
     * @throws RuntimeException if it has no lines, is already issued, or does not add up
     */
    public function issue(Invoice $invoice, ?string $date = null): Invoice
    {
        if ($invoice->status !== Invoice::DRAFT) {
            throw new RuntimeException("{$invoice->number} has already been issued.");
        }

        // The line accounts decide where revenue lands, and the customer's name
        // goes in the entry description — both are read below, and this project
        // has lazy loading disabled, so they are asked for by name here rather
        // than blowing up halfway through building the posting.
        $invoice->loadMissing('lines.ledgerAccount', 'customer');

        if ($invoice->lines->isEmpty()) {
            throw new RuntimeException('An invoice with no lines is not asking for anything.');
        }

        $totals = $this->totals($invoice);

        if ($totals['total']->isZero()) {
            throw new RuntimeException('This invoice comes to nothing. Check the amounts before issuing it.');
        }

        return DB::transaction(function () use ($invoice, $totals, $date) {
            $issueDate = $date ?? $invoice->issue_date?->toDateString() ?? now()->toDateString();

            $entry = $this->ledger->post(
                $issueDate,
                "Invoice {$invoice->number}".($invoice->customer ? " — {$invoice->customer->name}" : ''),
                $this->postingLines($invoice, $totals),
                [
                    'source' => 'system',
                    'subject_type' => Invoice::class,
                    'subject_id' => $invoice->id,
                ],
            );

            $invoice->forceFill([
                'status' => Invoice::ISSUED,
                'issue_date' => $issueDate,
                'due_date' => $invoice->due_date ?? $this->dueDateFor($invoice, $issueDate),
                'subtotal_minor' => $totals['subtotal']->minor,
                'discount_minor' => $totals['discount']->minor,
                'tax_minor' => $totals['tax']->minor,
                'total_minor' => $totals['total']->minor,
                'journal_entry_id' => $entry->id,
                'issued_at' => now(),
            ])->save();

            return $invoice->refresh();
        });
    }

    /**
     * Cancel an issued invoice.
     *
     * The posting is reversed, not removed — see JournalEntry. An invoice that
     * has been partly paid is refused: the money is real and has to be dealt
     * with (refunded, or moved to another invoice) before the debt it settled
     * can be said never to have existed.
     */
    public function void(Invoice $invoice, ?string $reason = null): Invoice
    {
        if ($invoice->status === Invoice::VOID) {
            throw new RuntimeException("{$invoice->number} is already void.");
        }

        if ($invoice->paid_minor > 0) {
            throw new RuntimeException(
                "{$invoice->number} has payments against it. Unallocate or refund them first — the money is real even if the invoice was a mistake."
            );
        }

        return DB::transaction(function () use ($invoice, $reason) {
            if ($invoice->journalEntry !== null && $invoice->journalEntry->status !== JournalEntry::REVERSED) {
                $this->ledger->reverse(
                    $invoice->journalEntry,
                    null,
                    $reason ?? "Invoice {$invoice->number} voided",
                );
            }

            $invoice->forceFill(['status' => Invoice::VOID, 'voided_at' => now()])->save();

            return $invoice->refresh();
        });
    }

    /**
     * Work out a line's tax and total from its own figures.
     *
     * Tax is computed on the discounted amount, which is what tax is actually
     * charged on — computing it on the list price and then discounting collects
     * tax on money nobody paid.
     */
    public function priceLine(InvoiceLine $line): InvoiceLine
    {
        $gross = (int) round((float) $line->quantity * $line->unit_price_minor);
        $net = max(0, $gross - $line->discount_minor);
        $tax = (int) round($net * ((float) $line->tax_rate / 100));

        $line->tax_minor = $tax;
        $line->total_minor = $net + $tax;

        return $line;
    }

    /**
     * @return array{subtotal: Money, discount: Money, tax: Money, total: Money}
     */
    public function totals(Invoice $invoice): array
    {
        $currency = $invoice->currency;
        $subtotal = $discount = $tax = 0;

        foreach ($invoice->lines as $line) {
            $subtotal += (int) round((float) $line->quantity * $line->unit_price_minor);
            $discount += $line->discount_minor;
            $tax += $line->tax_minor;
        }

        $total = $subtotal - $discount + $tax + $invoice->shipping_minor;

        return [
            'subtotal' => new Money($subtotal, $currency),
            'discount' => new Money($discount, $currency),
            'tax' => new Money($tax, $currency),
            'total' => new Money($total, $currency),
        ];
    }

    /**
     * The postings, grouped so each revenue account appears once.
     *
     * @param  array{subtotal: Money, discount: Money, tax: Money, total: Money}  $totals
     * @return list<array<string, mixed>>
     */
    private function postingLines(Invoice $invoice, array $totals): array
    {
        $currency = $invoice->currency;

        // What the customer owes: the whole thing, tax included.
        $lines = [[
            'account' => self::AR,
            'debit' => $totals['total'],
            'description' => 'Owed on '.$invoice->number,
        ]];

        // Revenue, per account, net of tax. Grouped rather than one posting per
        // invoice line: a fifty-line invoice should not put fifty identical
        // credits into the sales account for somebody to scroll past.
        $revenue = [];

        foreach ($invoice->lines as $line) {
            $code = $line->ledgerAccount?->code ?? self::DEFAULT_REVENUE;
            $gross = (int) round((float) $line->quantity * $line->unit_price_minor);
            $revenue[$code] = ($revenue[$code] ?? 0) + $gross;
        }

        foreach ($revenue as $code => $minor) {
            $lines[] = [
                'account' => (string) $code,
                'credit' => new Money($minor, $currency),
                'description' => 'Earned on '.$invoice->number,
            ];
        }

        // A discount is a deduction from sales, so it is a debit to a contra
        // revenue account — not an expense, which would leave revenue
        // overstated and costs overstated by the same amount.
        if ($totals['discount']->isPositive()) {
            $lines[] = [
                'account' => self::DISCOUNT,
                'debit' => $totals['discount'],
                'description' => 'Discount on '.$invoice->number,
            ];
        }

        if ($invoice->shipping_minor > 0) {
            $lines[] = [
                'account' => self::SHIPPING,
                'credit' => new Money($invoice->shipping_minor, $currency),
                'description' => 'Delivery on '.$invoice->number,
            ];
        }

        // Held on the state's behalf. Never income.
        if ($totals['tax']->isPositive()) {
            $lines[] = [
                'account' => self::TAX,
                'credit' => $totals['tax'],
                'description' => 'Tax on '.$invoice->number,
            ];
        }

        return $lines;
    }

    private function dueDateFor(Invoice $invoice, string $issueDate): string
    {
        $days = $invoice->customer?->payment_terms_days ?? 30;

        return date('Y-m-d', strtotime($issueDate." +{$days} days"));
    }

    /** INV-2026-0001, restarting each year. */
    public function nextNumber(?string $date = null): string
    {
        $business = $this->tenant->business();

        if (! $business instanceof Business) {
            throw new RuntimeException('No set of books is open.');
        }

        $prefix = 'INV-'.substr($date ?? now()->toDateString(), 0, 4).'-';

        $last = Invoice::query()
            ->where('business_id', $business->id)
            ->where('number', 'like', $prefix.'%')
            ->orderByDesc('number')
            ->value('number');

        $next = $last === null ? 1 : ((int) substr($last, strlen($prefix))) + 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
