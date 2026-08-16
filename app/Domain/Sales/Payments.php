<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use App\Domain\Ledger\Ledger;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Sales\Models\Customer;
use App\Domain\Sales\Models\Invoice;
use App\Domain\Sales\Models\Payment;
use App\Domain\Sales\Models\PaymentAllocation;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Money arriving, and being matched to what it was for.
 *
 * ── Two separate acts, deliberately ──────────────────────────────────────────
 *
 * Receiving money and deciding which invoice it settles are different events,
 * and conflating them is what makes reconciliation impossible later. A bank
 * transfer lands with a reference nobody can read; it is real money and belongs
 * in the books today, even though which of four invoices it covers will not be
 * known until somebody rings the customer tomorrow.
 *
 * So record() posts the cash, and allocate() applies it. A payment that has
 * been received and not yet applied sits visibly as credit rather than being
 * held out of the ledger until somebody works it out — which is how bank
 * balances stop matching statements.
 *
 * ── What each act does to the ledger ─────────────────────────────────────────
 *
 * record() posts the cash movement and the reduction of the debt:
 *
 *     DR  1000/1100  wherever the money landed
 *     CR  1200       Accounts Receivable
 *
 * allocate() posts nothing. This surprises people, so: the debt was already
 * reduced when the money arrived. Allocation only says *which* invoice it
 * reduced, and that is a fact about the receivable's composition, not about its
 * total. Posting again at allocation would count the same money twice.
 */
final class Payments
{
    private const AR = '1200';

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly Ledger $ledger,
    ) {}

    /**
     * Record money that arrived, and post it.
     *
     * @param  array<string, mixed>  $options
     */
    public function record(
        Money $amount,
        LedgerAccount|string $into,
        string $receivedOn,
        ?Customer $customer = null,
        array $options = [],
    ): Payment {
        if (! $amount->isPositive()) {
            throw new RuntimeException('A payment has to be for something. Use a refund for money going the other way.');
        }

        $deposit = $into instanceof LedgerAccount
            ? $into
            : LedgerAccount::where('code', $into)->firstOr(fn () => throw new RuntimeException("No account with code {$into} in this book."));

        if (! $deposit->is_spendable) {
            // Money arrives into cash or a bank account. Anywhere else is
            // either a mistake or a different kind of transaction entirely.
            throw new RuntimeException(
                "{$deposit->code} {$deposit->name} is not a cash or bank account, so money cannot be received into it."
            );
        }

        $direction = $options['direction'] ?? Payment::IN;

        return DB::transaction(function () use ($amount, $deposit, $receivedOn, $customer, $options, $direction) {
            $payment = Payment::create([
                'customer_id' => $customer?->id,
                'reference' => $options['reference'] ?? $this->nextReference($receivedOn),
                'received_on' => $receivedOn,
                'direction' => $direction,
                'method' => $options['method'] ?? 'cash',
                'external_ref' => $options['external_ref'] ?? null,
                'deposit_account_id' => $deposit->id,
                'currency' => $amount->currency,
                'amount_minor' => $amount->minor,
                'status' => $options['status'] ?? 'cleared',
                'notes' => $options['notes'] ?? null,
                'created_by' => $options['actor_id'] ?? auth()->id(),
            ]);

            // A refund is the same two accounts with the sides swapped: money
            // leaves, and the customer stops owing it.
            $lines = $direction === Payment::OUT
                ? [
                    ['account' => self::AR, 'debit' => $amount, 'description' => 'Refunded on '.$payment->reference],
                    ['account' => $deposit, 'credit' => $amount, 'description' => 'Paid out '.$payment->reference],
                ]
                : [
                    ['account' => $deposit, 'debit' => $amount, 'description' => 'Received '.$payment->reference],
                    ['account' => self::AR, 'credit' => $amount, 'description' => 'Settles debt — '.$payment->reference],
                ];

            $entry = $this->ledger->post(
                $receivedOn,
                ($direction === Payment::OUT ? 'Refund ' : 'Payment ').$payment->reference
                    .($customer ? " — {$customer->name}" : ''),
                $lines,
                [
                    'source' => $options['source'] ?? 'manual',
                    'subject_type' => Payment::class,
                    'subject_id' => $payment->id,
                ],
            );

            $payment->forceFill(['journal_entry_id' => $entry->id])->save();

            return $payment;
        });
    }

    /**
     * Apply some of a payment to an invoice.
     *
     * Posts nothing — see the note at the top of this class.
     *
     * @throws RuntimeException if it would over-apply the payment or overpay the invoice
     */
    public function allocate(Payment $payment, Invoice $invoice, ?Money $amount = null): PaymentAllocation
    {
        if ($invoice->status === Invoice::DRAFT) {
            throw new RuntimeException("{$invoice->number} has not been issued, so there is nothing to pay yet.");
        }

        if ($invoice->status === Invoice::VOID) {
            throw new RuntimeException("{$invoice->number} is void. Apply this to another invoice, or leave it as credit.");
        }

        if ($payment->currency !== $invoice->currency) {
            throw new RuntimeException(
                "That payment is in {$payment->currency} and {$invoice->number} is in {$invoice->currency}. Converting between them is a separate decision with its own rate."
            );
        }

        return DB::transaction(function () use ($payment, $invoice, $amount) {
            // Re-read inside the transaction: two people applying the same
            // payment at once would otherwise each see it as unapplied.
            $payment = Payment::lockForUpdate()->find($payment->id);
            $invoice = Invoice::lockForUpdate()->find($invoice->id);

            $available = $payment->amount_minor - $payment->allocated_minor;
            $owed = max(0, $invoice->total_minor - $invoice->paid_minor);

            $existing = PaymentAllocation::where('payment_id', $payment->id)
                ->where('invoice_id', $invoice->id)
                ->first();

            // The sensible default is "as much as helps": whichever of the two
            // runs out first.
            $wanted = $amount?->minor ?? min($available, $owed);

            if ($wanted <= 0) {
                throw new RuntimeException(
                    $available <= 0
                        ? "{$payment->reference} has already been fully applied."
                        : "{$invoice->number} is already settled."
                );
            }

            if ($wanted > $available) {
                throw new RuntimeException(sprintf(
                    'Only %s of %s is left to apply.',
                    (new Money($available, $payment->currency))->toDecimalString(),
                    $payment->reference,
                ));
            }

            if ($wanted > $owed) {
                throw new RuntimeException(sprintf(
                    '%s only has %s outstanding. Apply the rest elsewhere, or leave it as credit.',
                    $invoice->number,
                    (new Money($owed, $invoice->currency))->toDecimalString(),
                ));
            }

            if ($existing !== null) {
                $existing->forceFill(['amount_minor' => $existing->amount_minor + $wanted])->save();
                $allocation = $existing;
            } else {
                $allocation = PaymentAllocation::create([
                    'payment_id' => $payment->id,
                    'invoice_id' => $invoice->id,
                    'amount_minor' => $wanted,
                    'currency' => $payment->currency,
                    'allocated_on' => now()->toDateString(),
                    'created_by' => auth()->id(),
                ]);
            }

            $payment->forceFill(['allocated_minor' => $payment->allocated_minor + $wanted])->save();

            $invoice->forceFill([
                'paid_minor' => $invoice->paid_minor + $wanted,
                'status' => ($invoice->paid_minor + $wanted) >= $invoice->total_minor
                    ? Invoice::PAID
                    : $invoice->status,
            ])->save();

            return $allocation;
        });
    }

    /**
     * Apply a payment across a customer's outstanding invoices, oldest first.
     *
     * Oldest first is the convention, and it is the right default: it keeps the
     * aging report honest and is what a customer paying a round sum against a
     * statement almost always intends.
     *
     * @return list<PaymentAllocation>
     */
    public function autoAllocate(Payment $payment): array
    {
        if ($payment->customer_id === null) {
            throw new RuntimeException('That payment is not against a customer, so there is nothing to match it to.');
        }

        $invoices = Invoice::query()
            ->where('customer_id', $payment->customer_id)
            ->where('currency', $payment->currency)
            ->outstanding()
            ->orderBy('due_date')
            ->orderBy('issue_date')
            ->get();

        $made = [];

        foreach ($invoices as $invoice) {
            if ($payment->refresh()->isFullyAllocated()) {
                break;
            }

            $made[] = $this->allocate($payment, $invoice);
        }

        return $made;
    }

    /** PAY-2026-0001, restarting each year. */
    private function nextReference(string $date): string
    {
        $business = $this->tenant->business();
        $prefix = 'PAY-'.substr($date, 0, 4).'-';

        $last = Payment::query()
            ->where('business_id', $business->id)
            ->where('reference', 'like', $prefix.'%')
            ->orderByDesc('reference')
            ->value('reference');

        $next = $last === null ? 1 : ((int) substr($last, strlen($prefix))) + 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
