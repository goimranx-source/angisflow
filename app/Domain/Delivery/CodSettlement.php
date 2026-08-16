<?php

declare(strict_types=1);

namespace App\Domain\Delivery;

use App\Domain\Delivery\Models\CourierConnection;
use App\Domain\Delivery\Models\CourierSettlement;
use App\Domain\Delivery\Models\CourierSettlementLine;
use App\Domain\Delivery\Models\Shipment;
use App\Domain\Ledger\Ledger;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Turning what the couriers are holding into money in the bank.
 *
 * ── Delivery moves a debt; it does not receive cash ──────────────────────────
 *
 * The single most common error in cash-on-delivery accounting is treating a
 * delivered parcel as a cash sale. At that moment no cash of ours exists
 * anywhere — it is in a rider's pocket. What has happened is that the debt
 * changed hands, so that is what gets posted:
 *
 *     DR  1250 Courier Receivable
 *     CR  1200 Accounts Receivable
 *
 * The customer is square, and the courier now owes us. Nothing is in the bank
 * and the books say so.
 *
 * ── Remittance is where cash appears ─────────────────────────────────────────
 *
 *     DR  1100 Bank                 what actually landed
 *     DR  6700 Courier Charge       what they kept
 *     CR  1250 Courier Receivable   the debt is cleared
 *
 * Their fee is an expense, not a discount on the sale. Netting it off revenue
 * would understate turnover and hide what delivery costs — which is exactly the
 * number a business needs when choosing between couriers.
 *
 * ── Why matching is done before posting, not after ───────────────────────────
 *
 * A courier's statement is a claim. Posting it and reconciling later means the
 * books briefly say something nobody has checked, and "later" arrives at the
 * end of the quarter. Here the statement is loaded, matched line by line, and
 * only posted once somebody has seen what does not agree.
 */
final class CodSettlement
{
    private const COURIER_RECEIVABLE = '1250';

    private const ACCOUNTS_RECEIVABLE = '1200';

    private const COURIER_CHARGE = '6700';

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly Ledger $ledger,
    ) {}

    /**
     * Post the transfer of debt when a cash-on-delivery parcel is delivered.
     *
     * Called once per parcel, by whatever notices the delivery. Idempotent by
     * subject, so a courier sending "delivered" three times cannot post three
     * transfers.
     */
    public function recordDelivery(Shipment $shipment, ?string $on = null): bool
    {
        if (! $shipment->is_cod || $shipment->cod_amount_minor <= 0) {
            return false;
        }

        if ($shipment->status() !== ShipmentStatus::DELIVERED) {
            return false;
        }

        $already = \App\Domain\Ledger\Models\JournalEntry::query()
            ->where('subject_type', Shipment::class)
            ->where('subject_id', $shipment->id)
            ->where('description', 'like', 'Collected on delivery%')
            ->exists();

        if ($already) {
            return false;
        }

        $amount = $shipment->codAmount();

        $this->ledger->post(
            $on ?? $shipment->delivered_at?->toDateString() ?? now()->toDateString(),
            "Collected on delivery — {$shipment->number}",
            [
                ['account' => self::COURIER_RECEIVABLE, 'debit' => $amount, 'description' => 'Held by the courier'],
                ['account' => self::ACCOUNTS_RECEIVABLE, 'credit' => $amount, 'description' => 'Customer has paid'],
            ],
            ['source' => 'system', 'subject_type' => Shipment::class, 'subject_id' => $shipment->id],
        );

        return true;
    }

    /**
     * Load a courier's statement and match it against what we believe.
     *
     * @param  list<array{tracking_number: string, collected: int, fee?: int}>  $rows
     * @param  array<string, mixed>  $declared  their own totals
     */
    public function import(
        CourierConnection $connection,
        string $settledOn,
        array $rows,
        array $declared = [],
        array $options = [],
    ): CourierSettlement {
        $currency = $this->currency();

        return DB::transaction(function () use ($connection, $settledOn, $rows, $declared, $options, $currency) {
            $settlement = CourierSettlement::create([
                'courier_connection_id' => $connection->id,
                'number' => $options['number'] ?? $this->nextNumber($settledOn),
                'external_ref' => $options['external_ref'] ?? null,
                'settled_on' => $settledOn,
                'currency' => $currency,
                'declared_gross_minor' => $declared['gross'] ?? 0,
                'declared_fee_minor' => $declared['fee'] ?? 0,
                'declared_net_minor' => $declared['net'] ?? (($declared['gross'] ?? 0) - ($declared['fee'] ?? 0)),
                'created_by' => auth()->id(),
            ]);

            $gross = $fee = $net = 0;

            foreach ($rows as $row) {
                $line = $this->matchRow($settlement, $connection, $row);

                $gross += $line->collected_minor;
                $fee += $line->fee_minor;
                $net += $line->net_minor;
            }

            // Declared where they gave us totals, otherwise ours — a statement
            // with no stated total is common, and inventing a variance against
            // a number they never claimed would be noise.
            $declaredNet = $settlement->declared_net_minor > 0 ? $settlement->declared_net_minor : $net;

            $settlement->forceFill([
                'matched_gross_minor' => $gross,
                'matched_fee_minor' => $fee,
                'matched_net_minor' => $net,
                'declared_net_minor' => $declaredNet,
                'variance_minor' => $declaredNet - $net,
            ])->save();

            return $settlement->refresh()->load('lines');
        });
    }

    /**
     * Agree the statement and post it.
     *
     * @throws RuntimeException if it does not add up and nobody has said to proceed
     */
    public function confirm(
        CourierSettlement $settlement,
        LedgerAccount|string $into,
        array $options = [],
    ): CourierSettlement {
        if ($settlement->isPosted()) {
            throw new RuntimeException("{$settlement->number} has already been confirmed.");
        }

        $settlement->loadMissing('lines.shipment', 'courierConnection');

        if (! $settlement->agrees() && ! ($options['accept_variance'] ?? false)) {
            $reasons = [];

            if ($settlement->variance_minor !== 0) {
                $reasons[] = sprintf(
                    'the total is out by %s (%s)',
                    $settlement->variance()->toDecimalString(),
                    $settlement->variance_minor > 0 ? 'they paid more than the lines explain' : 'they paid less',
                );
            }

            $problems = $settlement->matchSummary();
            unset($problems['matched']);

            foreach ($problems as $kind => $count) {
                $reasons[] = "{$count} line(s) marked {$kind}";
            }

            throw new RuntimeException(sprintf(
                '%s does not agree with our records: %s. Resolve it, or confirm deliberately and the difference will be written off to courier charges.',
                $settlement->number,
                implode('; ', $reasons),
            ));
        }

        $deposit = $into instanceof LedgerAccount
            ? $into
            : LedgerAccount::where('code', $into)->firstOr(
                fn () => throw new RuntimeException("No account with code {$into} in this book.")
            );

        if (! $deposit->is_spendable) {
            throw new RuntimeException(
                "{$deposit->code} {$deposit->name} is not a cash or bank account, so a remittance cannot land there."
            );
        }

        return DB::transaction(function () use ($settlement, $deposit, $options) {
            $currency = $settlement->currency;

            // Only lines that matched a parcel of ours clear a receivable. A
            // line for a parcel we have never heard of brought in cash we
            // cannot attribute, and pretending it cleared something would leave
            // the receivable permanently wrong.
            $clearing = $settlement->lines
                ->filter(fn (CourierSettlementLine $l) => $l->shipment_id !== null)
                ->sum('collected_minor');

            $unattributed = $settlement->matched_gross_minor - $clearing;
            $fee = $settlement->matched_fee_minor;
            $cash = $settlement->declared_net_minor;

            $lines = [
                ['account' => $deposit, 'debit' => new Money($cash, $currency), 'description' => 'Remitted by courier'],
            ];

            if ($fee > 0) {
                $lines[] = ['account' => self::COURIER_CHARGE, 'debit' => new Money($fee, $currency),
                    'description' => 'Courier fee on '.$settlement->number];
            }

            if ($clearing > 0) {
                $lines[] = ['account' => self::COURIER_RECEIVABLE, 'credit' => new Money($clearing, $currency),
                    'description' => 'Cleared by '.$settlement->number];
            }

            // Cash for parcels we cannot identify, and any difference between
            // what they paid and what the lines explain. Both land in the
            // courier charge account so they are visible as a cost of using
            // this courier rather than disappearing into a suspense nobody
            // reads.
            $residual = $cash + $fee - $clearing;

            if ($residual !== 0) {
                $lines[] = $residual > 0
                    ? ['account' => self::COURIER_RECEIVABLE, 'credit' => new Money($residual, $currency),
                        'description' => 'Unattributed remittance']
                    : ['account' => self::COURIER_RECEIVABLE, 'debit' => new Money(-$residual, $currency),
                        'description' => 'Shortfall on '.$settlement->number];
            }

            $entry = $this->ledger->post(
                $settlement->settled_on->toDateString(),
                "Courier remittance {$settlement->number}",
                $lines,
                ['source' => 'system', 'subject_type' => CourierSettlement::class, 'subject_id' => $settlement->id],
            );

            // Each parcel records what came back for it, so codOutstanding()
            // stops counting them and the dashboard's money figure falls.
            foreach ($settlement->lines as $line) {
                if ($line->shipment === null) {
                    continue;
                }

                $line->shipment->forceFill([
                    'cod_settled_minor' => $line->shipment->cod_settled_minor + $line->collected_minor,
                    'delivery_fee_minor' => $line->shipment->delivery_fee_minor + $line->fee_minor,
                ])->save();
            }

            $settlement->forceFill([
                'status' => CourierSettlement::CONFIRMED,
                'deposit_account_id' => $deposit->id,
                'journal_entry_id' => $entry->id,
                'confirmed_at' => now(),
                'confirmed_by' => auth()->id(),
            ])->save();

            return $settlement->refresh();
        });
    }

    /**
     * Delivered, cash on delivery, and on no statement anybody has sent.
     *
     * The question a courier's portal cannot answer, because it only knows what
     * it has paid — not what it has not.
     *
     * @return list<array<string, mixed>>
     */
    public function unremitted(?CourierConnection $connection = null, int $olderThanDays = 0): array
    {
        return Shipment::query()
            ->where('business_id', $this->businessId())
            ->awaitingSettlement()
            ->when($connection !== null, fn ($q) => $q->where('courier_connection_id', $connection->id))
            ->when($olderThanDays > 0, fn ($q) => $q->where('delivered_at', '<', now()->subDays($olderThanDays)))
            ->with('courierConnection.courier')
            ->orderBy('delivered_at')
            ->get()
            ->map(fn (Shipment $s) => [
                ...$s->toPayload(),
                'days_since_delivery' => $s->delivered_at === null ? null : (int) $s->delivered_at->diffInDays(now()),
            ])
            ->all();
    }

    /**
     * @param  array{tracking_number: string, collected: int, fee?: int}  $row
     */
    private function matchRow(
        CourierSettlement $settlement,
        CourierConnection $connection,
        array $row,
    ): CourierSettlementLine {
        $tracking = trim((string) ($row['tracking_number'] ?? ''));
        $collected = (int) ($row['collected'] ?? 0);

        // Their stated fee where they give one, otherwise the rate agreed on
        // the connection. Computed rather than assumed zero — a courier that
        // reports only net is common, and treating their cut as nothing would
        // make delivery look free.
        $fee = array_key_exists('fee', $row)
            ? (int) $row['fee']
            : (int) round($collected * ((float) $connection->cod_fee_percent / 100));

        $shipment = $tracking === '' ? null : Shipment::query()
            ->where('business_id', $settlement->business_id)
            ->where(fn ($q) => $q->where('tracking_number', $tracking)->orWhere('number', $tracking))
            ->first();

        [$status, $note] = $this->classify($shipment, $collected);

        return CourierSettlementLine::create([
            'courier_settlement_id' => $settlement->id,
            'shipment_id' => $shipment?->id,
            'tracking_number' => $tracking === '' ? null : $tracking,
            'collected_minor' => $collected,
            'fee_minor' => $fee,
            'net_minor' => $collected - $fee,
            'match_status' => $status,
            'note' => $note,
        ]);
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    private function classify(?Shipment $shipment, int $collected): array
    {
        if ($shipment === null) {
            return [CourierSettlementLine::UNKNOWN, 'No parcel of ours has that tracking number'];
        }

        if ($shipment->cod_settled_minor >= $shipment->cod_amount_minor && $shipment->cod_amount_minor > 0) {
            return [CourierSettlementLine::DUPLICATE, 'Already settled on an earlier statement'];
        }

        $expected = $shipment->cod_amount_minor - $shipment->cod_settled_minor;

        if ($collected < $expected) {
            return [CourierSettlementLine::SHORT, sprintf(
                'Expected %s, they paid %s',
                (new Money($expected, $shipment->currency))->toDecimalString(),
                (new Money($collected, $shipment->currency))->toDecimalString(),
            )];
        }

        if ($collected > $expected) {
            return [CourierSettlementLine::OVER, sprintf(
                'Expected %s, they paid %s',
                (new Money($expected, $shipment->currency))->toDecimalString(),
                (new Money($collected, $shipment->currency))->toDecimalString(),
            )];
        }

        return [CourierSettlementLine::MATCHED, null];
    }

    private function nextNumber(string $date): string
    {
        $prefix = 'REM-'.substr($date, 0, 4).'-';

        $last = CourierSettlement::query()
            ->where('business_id', $this->businessId())
            ->where('number', 'like', $prefix.'%')
            ->orderByDesc('number')
            ->value('number');

        return $prefix.str_pad((string) ($last === null ? 1 : ((int) substr($last, strlen($prefix))) + 1), 4, '0', STR_PAD_LEFT);
    }

    private function businessId(): int
    {
        $business = $this->tenant->business();

        if ($business === null) {
            throw new RuntimeException('No set of books is open.');
        }

        return $business->id;
    }

    private function currency(): string
    {
        return strtoupper($this->tenant->business()?->base_currency ?? 'USD');
    }
}
