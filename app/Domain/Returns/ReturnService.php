<?php

declare(strict_types=1);

namespace App\Domain\Returns;

use App\Domain\Delivery\Models\Shipment;
use App\Domain\Delivery\ShipmentStatus;
use App\Domain\Ledger\Ledger;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Returns\Models\GoodsReturn;
use App\Domain\Returns\Models\GoodsReturnLine;
use App\Domain\Sales\Models\Order;
use App\Domain\Sales\Models\OrderLine;
use App\Domain\Sales\Payments;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Stock\Models\StockLocation;
use App\Domain\Stock\StockService;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Unwinding a sale.
 *
 * ── Returned stock does not go back on the shelf ─────────────────────────────
 *
 * It goes to a location with is_sellable false — a quarantine bay — and stays
 * there until somebody has looked at it. This is what that flag on stock
 * locations was for.
 *
 * Putting a return straight back into sellable stock means the next customer
 * gets a box somebody has already opened, and the business finds out from a
 * review. It also makes the availability figure a lie for as long as the goods
 * sit in a heap by the door untested. Quarantine costs a location and solves
 * both.
 *
 * ── A refund is a contra to revenue, never an expense ────────────────────────
 *
 *     DR  4900 Sales Returns & Refunds    a deduction from what we sold
 *     CR  1200 Accounts Receivable        the customer is owed
 *
 * Booking it as an expense leaves revenue overstated and costs overstated by
 * the same amount, and the two errors cancel in the profit line — so nothing
 * looks wrong while both the top line and the cost base are wrong. This is the
 * reason ChartOfAccounts has a contra_revenue subtype at all.
 *
 * ── And the cost of sales comes back too ─────────────────────────────────────
 *
 *     DR  1400 Inventory                  the goods are ours again
 *     CR  5000 Cost of Goods Sold         they were never sold after all
 *
 * At what they actually cost when they went out, taken from the order line —
 * not at today's standard cost. A return costed at today's price restates the
 * margin of a sale made three months ago.
 */
final class ReturnService
{
    private const SALES_RETURNS = '4900';

    private const RECEIVABLE = '1200';

    private const INVENTORY = '1400';

    private const COGS = '5000';

    private const WRITE_OFF = '5200';

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly StockService $stock,
        private readonly Payments $payments,
        private readonly Ledger $ledger,
    ) {}

    /**
     * Open a return against an order.
     *
     * @param  list<array{order_line: OrderLine, quantity: float, condition?: string, note?: string}>  $items
     */
    public function open(Order $order, array $items, array $options = []): GoodsReturn
    {
        if ($items === []) {
            throw new RuntimeException('A return has to be for something.');
        }

        $order->loadMissing('lines');

        return DB::transaction(function () use ($order, $items, $options) {
            $return = GoodsReturn::create([
                'order_id' => $order->id,
                'shipment_id' => $options['shipment_id'] ?? null,
                'customer_id' => $order->customer_id,
                'number' => $options['number'] ?? $this->nextNumber($options['returned_on'] ?? null),
                'returned_on' => $options['returned_on'] ?? now()->toDateString(),
                'is_rto' => $options['is_rto'] ?? false,
                'reason' => $options['reason'] ?? null,
                'notes' => $options['notes'] ?? null,
                'currency' => $order->currency,
                'restocking_fee_minor' => $options['restocking_fee_minor'] ?? 0,
                'stock_location_id' => $options['location']?->id ?? null,
                'created_by' => auth()->id(),
            ]);

            $goods = $cost = 0;
            $lineNo = 1;

            foreach ($items as $item) {
                /** @var OrderLine $orderLine */
                $orderLine = $item['order_line'];
                $quantity = (float) $item['quantity'];

                if ($quantity <= 0) {
                    continue;
                }

                if ($quantity > (float) $orderLine->quantity_fulfilled + 0.00005) {
                    throw new RuntimeException(sprintf(
                        'Only %s of "%s" was ever shipped, so %s cannot come back.',
                        rtrim(rtrim(number_format((float) $orderLine->quantity_fulfilled, 4, '.', ''), '0'), '.'),
                        $orderLine->description,
                        rtrim(rtrim(number_format($quantity, 4, '.', ''), '0'), '.'),
                    ));
                }

                $condition = $item['condition'] ?? GoodsReturnLine::RESALEABLE;
                $share = (float) $orderLine->quantity > 0 ? $quantity / (float) $orderLine->quantity : 0;

                // Proportional to the line, so a partial return of a discounted
                // line gives back the discounted amount rather than list price.
                $refund = $condition === GoodsReturnLine::MISSING
                    ? 0
                    : (int) round($orderLine->total_minor * $share);

                // What these units actually cost when they left, from the line
                // that recorded it. Today's standard cost would restate an old
                // sale's margin.
                $lineCost = (int) round($orderLine->cost_minor * $share);

                GoodsReturnLine::create([
                    'goods_return_id' => $return->id,
                    'order_line_id' => $orderLine->id,
                    'product_variant_id' => $orderLine->product_variant_id,
                    'line_no' => $lineNo++,
                    'description' => $orderLine->description,
                    'quantity' => $quantity,
                    'condition' => $condition,
                    'unit_price_minor' => $orderLine->unit_price_minor,
                    'refund_minor' => $refund,
                    'cost_minor' => $lineCost,
                    'currency' => $order->currency,
                    'note' => $item['note'] ?? null,
                ]);

                $goods += $refund;
                $cost += $lineCost;
            }

            $return->forceFill([
                'goods_minor' => $goods,
                'cost_minor' => $cost,
                'refund_minor' => max(0, $goods - $return->restocking_fee_minor),
            ])->save();

            return $return->refresh()->load('lines');
        });
    }

    /**
     * Open a return straight from a shipment the courier is sending back.
     *
     * The RTO path: nobody chose to return these goods, the delivery simply
     * failed. Everything shipped comes back, and the reason comes from the
     * parcel rather than from a customer.
     */
    public function fromRto(Shipment $shipment, array $options = []): GoodsReturn
    {
        $shipment->loadMissing('order.lines');
        $order = $shipment->order;

        if ($order === null) {
            throw new RuntimeException('That parcel is not against an order, so there is nothing to unwind.');
        }

        $items = $order->lines
            ->filter(fn (OrderLine $l) => (float) $l->quantity_fulfilled > 0)
            ->map(fn (OrderLine $l) => [
                'order_line' => $l,
                'quantity' => (float) $l->quantity_fulfilled,
                'condition' => GoodsReturnLine::RESALEABLE,
            ])
            ->values()
            ->all();

        return $this->open($order, $items, [
            ...$options,
            'is_rto' => true,
            'shipment_id' => $shipment->id,
            'reason' => $options['reason'] ?? 'undelivered',
        ]);
    }

    /**
     * The goods are in our hands. Put them somewhere and post what it means.
     *
     * @throws RuntimeException if there is nowhere to put them
     */
    public function receive(GoodsReturn $return, StockLocation $into, ?string $on = null): GoodsReturn
    {
        if ($return->status !== GoodsReturn::EXPECTED) {
            throw new RuntimeException("{$return->number} has already been received.");
        }

        if ($into->is_sellable) {
            // Not refused, because a business may genuinely have no quarantine
            // and a small shop checking each item at the counter is a real way
            // to work. But said out loud, because the default should be the
            // careful one and this is the moment to notice.
            $return->forceFill([
                'notes' => trim((string) $return->notes."\nReceived directly into sellable stock at {$into->name}."),
            ])->save();
        }

        $return->loadMissing('lines.variant');
        $date = $on ?? $return->returned_on?->toDateString() ?? now()->toDateString();

        return DB::transaction(function () use ($return, $into, $date) {
            foreach ($return->lines as $line) {
                if (! $line->goesBackToStock() || $line->variant === null) {
                    continue;
                }

                $quantity = (float) $line->quantity;
                $unitCost = $quantity > 0 ? (int) round($line->cost_minor / $quantity) : 0;

                $this->stock->receive(
                    $line->variant,
                    $quantity,
                    new Money($unitCost, $line->currency),
                    $into,
                    $date,
                    [
                        'kind' => 'return_in',
                        'reason' => "Returned on {$return->number}",
                        'subject_type' => GoodsReturn::class,
                        'subject_id' => $return->id,
                    ],
                );
            }

            $return->forceFill([
                'status' => GoodsReturn::RECEIVED,
                'stock_location_id' => $into->id,
                'received_at' => now(),
            ])->save();

            return $return->refresh();
        });
    }

    /**
     * Post the money and close the return.
     *
     * @param  LedgerAccount|string|null  $refundFrom  where a cash refund leaves, if one is due
     */
    public function settle(GoodsReturn $return, LedgerAccount|string|null $refundFrom = null, ?string $on = null): GoodsReturn
    {
        if ($return->status === GoodsReturn::SETTLED) {
            throw new RuntimeException("{$return->number} has already been settled.");
        }

        if ($return->status !== GoodsReturn::RECEIVED) {
            throw new RuntimeException("{$return->number} has not been received yet — the goods are not back.");
        }

        $return->loadMissing('lines', 'order', 'customer');
        $date = $on ?? now()->toDateString();
        $currency = $return->currency;

        return DB::transaction(function () use ($return, $refundFrom, $date, $currency) {
            $lines = [];

            // The revenue side: what we billed comes back off sales. Contra
            // revenue, not an expense.
            if ($return->goods_minor > 0) {
                $lines[] = ['account' => self::SALES_RETURNS, 'debit' => new Money($return->goods_minor, $currency),
                    'description' => 'Returned on '.$return->number];
                $lines[] = ['account' => self::RECEIVABLE, 'credit' => new Money($return->goods_minor, $currency),
                    'description' => 'Owed back to the customer'];
            }

            // A restocking fee is money we keep, so it offsets the credit —
            // the customer is owed less than the goods were worth.
            if ($return->restocking_fee_minor > 0) {
                $lines[] = ['account' => self::RECEIVABLE, 'debit' => new Money($return->restocking_fee_minor, $currency),
                    'description' => 'Restocking fee kept'];
                $lines[] = ['account' => self::SALES_RETURNS, 'credit' => new Money($return->restocking_fee_minor, $currency),
                    'description' => 'Restocking fee on '.$return->number];
            }

            // The cost side, split by what actually came back in usable
            // condition. Damaged goods leave cost of sales and land in the
            // write-off account, where they are visible as a cost of returns
            // rather than hidden inside inventory.
            $resaleable = $return->lines->filter->isResaleable()->sum('cost_minor');
            $damaged = $return->lines
                ->filter(fn (GoodsReturnLine $l) => $l->condition === GoodsReturnLine::DAMAGED)
                ->sum('cost_minor');

            if ($resaleable > 0) {
                $lines[] = ['account' => self::INVENTORY, 'debit' => new Money((int) $resaleable, $currency),
                    'description' => 'Back in stock'];
                $lines[] = ['account' => self::COGS, 'credit' => new Money((int) $resaleable, $currency),
                    'description' => 'Never sold after all'];
            }

            if ($damaged > 0) {
                $lines[] = ['account' => self::WRITE_OFF, 'debit' => new Money((int) $damaged, $currency),
                    'description' => 'Returned damaged'];
                $lines[] = ['account' => self::COGS, 'credit' => new Money((int) $damaged, $currency),
                    'description' => 'Moved out of cost of sales'];
            }

            $entry = $lines === [] ? null : $this->ledger->post(
                $date,
                ($return->is_rto ? 'Undelivered — ' : 'Return ').$return->number,
                $lines,
                ['source' => 'system', 'subject_type' => GoodsReturn::class, 'subject_id' => $return->id],
            );

            // Money actually going back, where the customer had paid. An RTO on
            // a cash-on-delivery parcel needs none — they never paid — which is
            // the practical difference between the two kinds of return.
            $payment = null;
            $owed = $this->refundDue($return);

            if ($owed > 0 && $refundFrom !== null) {
                $payment = $this->payments->record(
                    new Money($owed, $currency),
                    $refundFrom,
                    $date,
                    $return->customer,
                    ['direction' => 'out', 'method' => 'bank', 'source' => 'system',
                        'notes' => "Refund on {$return->number}"],
                );
            }

            $return->forceFill([
                'status' => GoodsReturn::SETTLED,
                'journal_entry_id' => $entry?->id,
                'refund_payment_id' => $payment?->id,
                'settled_at' => now(),
            ])->save();

            return $return->refresh();
        });
    }

    /**
     * What the customer actually gets back.
     *
     * Never more than they paid. A return against an unpaid invoice cancels the
     * debt rather than producing a refund — sending money to somebody who never
     * sent any is the kind of bug that is discovered by the person who noticed
     * and did it twice.
     */
    public function refundDue(GoodsReturn $return): int
    {
        $return->loadMissing('order');
        $paid = $return->order?->paid_minor ?? 0;

        return max(0, min($return->refund_minor, $paid));
    }

    private function nextNumber(?string $date = null): string
    {
        $prefix = 'RET-'.substr($date ?? now()->toDateString(), 0, 4).'-';

        $last = GoodsReturn::query()
            ->where('business_id', $this->tenant->business()->id)
            ->where('number', 'like', $prefix.'%')
            ->orderByDesc('number')
            ->value('number');

        return $prefix.str_pad((string) ($last === null ? 1 : ((int) substr($last, strlen($prefix))) + 1), 4, '0', STR_PAD_LEFT);
    }
}
