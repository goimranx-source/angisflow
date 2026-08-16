<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use App\Domain\Ledger\Ledger;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Sales\Models\Customer;
use App\Domain\Sales\Models\Order;
use App\Domain\Sales\Models\TillSession;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Stock\Models\StockLocation;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The counter.
 *
 * ── One call, because the customer is standing there ─────────────────────────
 *
 * Everywhere else in this system an order is confirmed, then picked, then
 * shipped, then invoiced, then paid, and the gaps between those are where the
 * work happens. At a till there are no gaps: the goods are handed over and the
 * money is taken in the same second. Making the operator drive five steps for
 * one event is how a queue forms.
 *
 * So sell() does all of it in one transaction — and it is the *same* five
 * steps, running against the same order, stock and ledger code. Nothing about a
 * counter sale is special enough to justify a second implementation, and a
 * second implementation is how "today's takings" and "today's sales" come to
 * differ.
 *
 * ── Why an idempotency key is not optional here ──────────────────────────────
 *
 * A till is the one place in this tool that runs on a flaky connection with a
 * customer waiting. The operator presses the button, the request times out, and
 * they press it again — because from where they are standing nothing happened.
 * Without a key that second press is a second sale: stock leaves twice, the
 * customer is charged twice, and the drawer is over by exactly one basket.
 *
 * With one, the retry finds the first sale and returns it. The till shows a
 * receipt and the operator never knows anything went wrong.
 */
final class PointOfSale
{
    private const CASH_OVER_SHORT = '6500';

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly OrderService $orders,
        private readonly Payments $payments,
        private readonly Ledger $ledger,
    ) {}

    /**
     * Open the drawer for a shift.
     *
     * @throws RuntimeException if this till is already open
     */
    public function openSession(
        StockLocation $location,
        Money $float,
        string $tillCode = 'T1',
        array $options = [],
    ): TillSession {
        $existing = TillSession::query()
            ->open()
            ->where('stock_location_id', $location->id)
            ->where('till_code', $tillCode)
            ->first();

        if ($existing !== null) {
            throw new RuntimeException(
                "Till {$tillCode} at {$location->name} is already open as {$existing->number}. Close it before opening another."
            );
        }

        return TillSession::create([
            'stock_location_id' => $location->id,
            'till_code' => $tillCode,
            'number' => $options['number'] ?? $this->nextSessionNumber(),
            'opened_by' => $options['actor_id'] ?? auth()->id(),
            'opened_at' => now(),
            'currency' => $float->currency,
            'opening_float_minor' => $float->minor,
        ]);
    }

    /**
     * Ring up a sale: goods out, money in, all of it, at once.
     *
     * $items is a list of ['variant' => ProductVariant, 'quantity' => float,
     * 'price' => ?Money], and $tenders a list of ['amount' => Money,
     * 'method' => 'cash'|'card'|…, 'account' => code].
     *
     * @param  list<array<string, mixed>>  $items
     * @param  list<array<string, mixed>>  $tenders
     */
    public function sell(
        TillSession $session,
        array $items,
        array $tenders,
        array $options = [],
    ): Order {
        if (! $session->isOpen()) {
            throw new RuntimeException("{$session->number} is closed, so nothing more can be sold through it.");
        }

        if ($items === []) {
            throw new RuntimeException('An empty basket is not a sale.');
        }

        $key = $options['idempotency_key'] ?? null;

        // Checked before the transaction and again inside it: this is the
        // retry-after-timeout path, and the first press may still be in flight.
        if ($key !== null) {
            $already = Order::where('idempotency_key', $key)->first();

            if ($already !== null) {
                return $already->load('lines', 'invoice');
            }
        }

        $session->loadMissing('location');

        return DB::transaction(function () use ($session, $items, $tenders, $options, $key) {
            if ($key !== null && Order::lockForUpdate()->where('idempotency_key', $key)->exists()) {
                return Order::where('idempotency_key', $key)->first()->load('lines', 'invoice');
            }

            /** @var Customer|null $customer */
            $customer = $options['customer'] ?? null;
            $on = $options['on'] ?? now()->toDateString();

            $order = $this->orders->open($customer, [
                'ordered_on' => $on,
                'channel' => 'pos',
                'currency' => $session->currency,
                'idempotency_key' => $key,
                'till_session_id' => $session->id,
                'shipping_minor' => $options['shipping_minor'] ?? 0,
            ]);

            foreach ($items as $item) {
                $this->orders->addLine(
                    $order,
                    $item['variant'],
                    (float) $item['quantity'],
                    $item['price'] ?? null,
                    $item['options'] ?? [],
                );
            }

            $order = $order->refresh();

            // Same path as any other order: reserve, then ship. The reservation
            // exists for a moment only, which is a little wasteful and much
            // better than a second code path that could get stock wrong
            // differently.
            $this->orders->confirm($order, $session->location, ['allow_backorder' => $options['allow_backorder'] ?? false]);
            $order = $this->orders->fulfil($order->refresh(), $on);

            $this->takeTenders($session, $order->refresh(), $tenders, $on, $customer);

            return $this->orders->syncPayment($order->refresh())->load('lines', 'invoice');
        });
    }

    /**
     * Count the drawer and close the shift.
     *
     * The variance posts. A till that is three pounds short every Friday is
     * telling somebody something, and it can only do that if the number lands
     * in the books rather than on a scrap of paper.
     */
    public function closeSession(TillSession $session, Money $counted, ?string $notes = null): TillSession
    {
        if (! $session->isOpen()) {
            throw new RuntimeException("{$session->number} is already closed.");
        }

        return DB::transaction(function () use ($session, $counted, $notes) {
            $expected = $session->expectedCash();
            $variance = $counted->minor - $expected->minor;

            $entry = null;

            if ($variance !== 0) {
                $amount = new Money(abs($variance), $session->currency);

                // Over means there is more cash than the sales explain, so cash
                // goes up and the gain offsets it. Short is the mirror. Both
                // land in the same account so "how much did the tills lose this
                // year" is one figure.
                $entry = $this->ledger->post(
                    now()->toDateString(),
                    "Till {$session->number} ".($variance > 0 ? 'over' : 'short'),
                    $variance > 0
                        ? [
                            ['account' => '1000', 'debit' => $amount, 'description' => 'Cash over at close'],
                            ['account' => self::CASH_OVER_SHORT, 'credit' => $amount, 'description' => 'Till over'],
                        ]
                        : [
                            ['account' => self::CASH_OVER_SHORT, 'debit' => $amount, 'description' => 'Till short'],
                            ['account' => '1000', 'credit' => $amount, 'description' => 'Cash short at close'],
                        ],
                    ['source' => 'system', 'subject_type' => TillSession::class, 'subject_id' => $session->id],
                );
            }

            $session->forceFill([
                'status' => TillSession::CLOSED,
                'closed_at' => now(),
                'closed_by' => auth()->id(),
                'counted_minor' => $counted->minor,
                'variance_minor' => $variance,
                'notes' => $notes,
                'journal_entry_id' => $entry?->id,
            ])->save();

            return $session->refresh();
        });
    }

    /**
     * Move cash out of the drawer to the safe, or put more in.
     *
     * Recorded rather than done quietly, because unrecorded cash movement is
     * indistinguishable from a shortfall at close — and treating one as the
     * other is how an honest cashier gets accused.
     */
    public function moveCash(TillSession $session, Money $amount, string $direction, ?string $reason = null): TillSession
    {
        if (! $session->isOpen()) {
            throw new RuntimeException("{$session->number} is closed.");
        }

        if (! $amount->isPositive()) {
            throw new RuntimeException('A cash movement has to be for something.');
        }

        $field = $direction === 'out' ? 'cash_out_minor' : 'cash_in_minor';

        $session->forceFill([
            $field => $session->{$field} + $amount->minor,
            'notes' => trim((string) $session->notes."\n".ucfirst($direction).' '.$amount->toDecimalString().': '.($reason ?? '')),
        ])->save();

        return $session->refresh();
    }

    /**
     * @param  list<array<string, mixed>>  $tenders
     */
    private function takeTenders(
        TillSession $session,
        Order $order,
        array $tenders,
        string $on,
        ?Customer $customer,
    ): void {
        $invoice = $order->invoice;

        if ($invoice === null) {
            throw new RuntimeException('That sale produced no invoice, so there is nothing to take money against.');
        }

        $taken = 0;

        foreach ($tenders as $tender) {
            /** @var Money $amount */
            $amount = $tender['amount'];
            $method = $tender['method'] ?? 'cash';

            // Cash goes to the drawer account; everything else to wherever that
            // tender settles. A card payment sitting in "Cash" is what makes a
            // drawer look thousands over.
            $code = $tender['account'] ?? ($method === 'cash' ? '1000' : '1100');
            $account = LedgerAccount::where('code', $code)->firstOr(
                fn () => throw new RuntimeException("No account with code {$code} in this book.")
            );

            $payment = $this->payments->record($amount, $account, $on, $customer, [
                'method' => $method,
                'source' => 'pos',
                'external_ref' => $tender['reference'] ?? null,
            ]);

            // Change given is not a tender — the basket is settled to its total
            // and the excess stays as the customer's cash, not ours.
            $applicable = new Money(
                min($amount->minor, max(0, $invoice->total_minor - $invoice->refresh()->paid_minor)),
                $amount->currency,
            );

            if ($applicable->isPositive()) {
                $this->payments->allocate($payment, $invoice->refresh(), $applicable);
            }

            $taken += $amount->minor;

            $session->forceFill(
                $method === 'cash'
                    ? ['cash_sales_minor' => $session->cash_sales_minor + $amount->minor]
                    : ['other_tenders_minor' => $session->other_tenders_minor + $amount->minor],
            )->save();
        }

        if ($taken < $order->total_minor) {
            throw new RuntimeException(sprintf(
                'The tenders come to %s but the basket is %s. A counter sale has to be settled in full.',
                (new Money($taken, $order->currency))->toDecimalString(),
                $order->total()->toDecimalString(),
            ));
        }
    }

    /** TILL-2026-0001, restarting each year. */
    private function nextSessionNumber(?string $date = null): string
    {
        $prefix = 'TILL-'.substr($date ?? now()->toDateString(), 0, 4).'-';

        $last = TillSession::query()
            ->where('business_id', $this->tenant->business()->id)
            ->where('number', 'like', $prefix.'%')
            ->orderByDesc('number')
            ->value('number');

        return $prefix.str_pad((string) ($last === null ? 1 : ((int) substr($last, strlen($prefix))) + 1), 4, '0', STR_PAD_LEFT);
    }
}
