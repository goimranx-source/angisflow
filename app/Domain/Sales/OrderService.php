<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use App\Domain\Catalogue\Models\ProductVariant;
use App\Domain\Ledger\Ledger;
use App\Domain\Sales\Models\Customer;
use App\Domain\Sales\Models\Invoice;
use App\Domain\Sales\Models\InvoiceLine;
use App\Domain\Sales\Models\Order;
use App\Domain\Sales\Models\OrderLine;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Stock\Models\StockLocation;
use App\Domain\Stock\Models\StockReservation;
use App\Domain\Stock\StockService;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The life of an order.
 *
 * ── A sale is two postings, and they happen at different moments ─────────────
 *
 * The revenue side — what the customer owes and what we earned — belongs to the
 * invoice, and Invoicing already posts it. The cost side is separate:
 *
 *     DR  5000 Cost of Goods Sold      what the units shipped had cost us
 *     CR  1400 Inventory               they are no longer ours
 *
 * That posting happens at fulfilment, not at confirmation, because until goods
 * leave they are still stock. Posting cost of sales when an order is placed
 * understates inventory and overstates cost in whichever month the order was
 * taken, and the effect is invisible in the profit figure of any month where
 * orders roughly balance despatches — right up to a month where they do not.
 *
 * ── Why confirming reserves rather than issuing ──────────────────────────────
 *
 * A confirmed order is a promise, and a promise has to hold the stock or it is
 * not one. But nothing has physically moved, so a movement would be a lie about
 * where the goods are. That distinction is exactly what StockService's
 * reservations exist for.
 */
final class OrderService
{
    private const COGS = '5000';

    private const INVENTORY = '1400';

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly StockService $stock,
        private readonly Invoicing $invoicing,
        private readonly Ledger $ledger,
    ) {}

    /**
     * Start an order. Nothing is promised and nothing moves.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function open(?Customer $customer, array $attributes = []): Order
    {
        $business = $this->tenant->business();

        if ($business === null) {
            throw new RuntimeException('No set of books is open, so there is nowhere to take an order.');
        }

        return Order::create([
            'customer_id' => $customer?->id,
            'number' => $attributes['number'] ?? $this->nextNumber($attributes['ordered_on'] ?? null),
            'ordered_on' => $attributes['ordered_on'] ?? now()->toDateString(),
            'currency' => $attributes['currency'] ?? $customer?->currency ?? $business->base_currency,
            'created_by' => auth()->id(),
            ...$attributes,
        ]);
    }

    /**
     * Put something on the order.
     *
     * The price defaults to the variant's, but is a plain argument — a quoted
     * price, a negotiated one, or a price list are all ordinary, and an order
     * line that can only ever be catalogue price is one nobody can use.
     */
    public function addLine(
        Order $order,
        ProductVariant $variant,
        float $quantity,
        ?Money $unitPrice = null,
        array $options = [],
    ): OrderLine {
        if (! $order->isEditable()) {
            throw new RuntimeException("{$order->number} has been confirmed, so its lines can no longer change.");
        }

        $variant->loadMissing('product');
        $price = $unitPrice ?? $variant->price();

        $line = new OrderLine([
            'order_id' => $order->id,
            'product_variant_id' => $variant->id,
            'line_no' => $options['line_no'] ?? ((int) $order->lines()->max('line_no') + 1),
            'sku' => $variant->sku,
            'description' => $options['description'] ?? $variant->label(),
            'quantity' => $quantity,
            'unit_price_minor' => $price->minor,
            'discount_minor' => $options['discount_minor'] ?? 0,
            'tax_rate' => $options['tax_rate'] ?? $variant->product?->tax_rate ?? 0,
            'currency' => $price->currency,
        ]);

        $this->priceLine($line)->save();
        $this->retotal($order);

        return $line;
    }

    /**
     * Confirm the order and hold the stock for it.
     *
     * @throws RuntimeException if the stock is not there, unless allow_backorder
     */
    public function confirm(Order $order, StockLocation $location, array $options = []): Order
    {
        if ($order->status !== Order::DRAFT) {
            throw new RuntimeException("{$order->number} has already been confirmed.");
        }

        // product, not just variant: isStocked() below is on the product,
        // and lazy loading is disabled — so it has to be asked for by name
        // rather than blowing up partway through reserving.
        $order->loadMissing('lines.variant.product');

        if ($order->lines->isEmpty()) {
            throw new RuntimeException('An order with no lines is not an order.');
        }

        return DB::transaction(function () use ($order, $location, $options) {
            foreach ($order->lines as $line) {
                // A line for something not in the catalogue — a fee, a one-off
                // — has nothing to reserve and is not an error.
                if ($line->variant === null || ! $line->variant->product?->isStocked()) {
                    continue;
                }

                $reservation = $this->stock->reserve(
                    $line->variant,
                    (float) $line->quantity,
                    $location,
                    [
                        'subject_type' => Order::class,
                        'subject_id' => $order->id,
                        'allow_oversell' => $options['allow_backorder'] ?? false,
                    ],
                );

                $line->forceFill(['stock_reservation_id' => $reservation->id])->save();
            }

            $order->forceFill([
                'status' => Order::CONFIRMED,
                'stock_location_id' => $location->id,
                'confirmed_at' => now(),
            ])->save();

            return $order->refresh();
        });
    }

    /**
     * Ship it: stock leaves, cost of sales posts, an invoice is raised.
     *
     * @param  array<int, float>  $quantities  line id => quantity, for a part shipment
     */
    public function fulfil(Order $order, string $on, array $quantities = []): Order
    {
        if (! in_array($order->status, [Order::CONFIRMED, Order::FULFILLED], true)) {
            throw new RuntimeException("{$order->number} has to be confirmed before anything can be shipped.");
        }

        $order->loadMissing('lines.variant.product', 'location', 'customer');

        if ($order->location === null) {
            throw new RuntimeException('That order has no location to ship from.');
        }

        return DB::transaction(function () use ($order, $on, $quantities) {
            $costThisTime = 0;

            foreach ($order->lines as $line) {
                $wanted = $quantities[$line->id] ?? $line->outstandingQuantity();

                if ($wanted <= 0) {
                    continue;
                }

                if ($line->variant === null || ! $line->variant->product?->isStocked()) {
                    // Nothing to move, but it still counts as delivered.
                    $line->forceFill([
                        'quantity_fulfilled' => (float) $line->quantity_fulfilled + $wanted,
                    ])->save();

                    continue;
                }

                // The reservation was this order's claim; redeeming it is what
                // this call is doing, so it is released before the issue rather
                // than blocking it.
                if ($line->stock_reservation_id !== null) {
                    $held = StockReservation::find($line->stock_reservation_id);

                    if ($held !== null && $held->status === StockReservation::HELD) {
                        $this->stock->release($held);
                        $held->forceFill(['status' => StockReservation::CONSUMED])->save();
                    }
                }

                $result = $this->stock->issue(
                    $line->variant,
                    $wanted,
                    $order->location,
                    $on,
                    [
                        'reason' => "Shipped on {$order->number}",
                        'subject_type' => Order::class,
                        'subject_id' => $order->id,
                        'allow_negative' => true,
                    ],
                );

                $line->forceFill([
                    'quantity_fulfilled' => (float) $line->quantity_fulfilled + $wanted,
                    'cost_minor' => $line->cost_minor + $result['cost']->minor,
                ])->save();

                $costThisTime += $result['cost']->minor;
            }

            // The cost side of the sale. Only what went out this time, so a
            // part shipment posts its own share rather than the whole order's.
            if ($costThisTime > 0) {
                $this->ledger->post(
                    $on,
                    "Cost of goods on {$order->number}",
                    [
                        ['account' => self::COGS, 'debit' => new Money($costThisTime, $order->currency)],
                        ['account' => self::INVENTORY, 'credit' => new Money($costThisTime, $order->currency)],
                    ],
                    ['source' => 'system', 'subject_type' => Order::class, 'subject_id' => $order->id],
                );
            }

            $order->forceFill([
                'cost_minor' => $order->cost_minor + $costThisTime,
                'fulfilled_at' => $order->fulfilled_at ?? now(),
            ])->save();

            $this->refreshFulfilment($order->refresh());

            // The revenue side, once. An order shipped in three parts is still
            // one invoice — the customer was promised one bill.
            if ($order->invoice_id === null && $order->fulfilment_status !== Order::UNFULFILLED) {
                $this->raiseInvoice($order->refresh(), $on);
            }

            return $order->refresh();
        });
    }

    /**
     * Raise the invoice for an order, and post the revenue.
     */
    public function raiseInvoice(Order $order, ?string $on = null): Invoice
    {
        if ($order->invoice_id !== null) {
            throw new RuntimeException("{$order->number} has already been invoiced.");
        }

        $order->loadMissing('lines.variant.product', 'customer');

        return DB::transaction(function () use ($order, $on) {
            $invoice = Invoice::create([
                'customer_id' => $order->customer_id,
                'number' => $this->invoicing->nextNumber($on),
                'issue_date' => $on ?? now()->toDateString(),
                'currency' => $order->currency,
                'shipping_minor' => $order->shipping_minor,
                'reference' => $order->number,
            ]);

            foreach ($order->lines as $line) {
                $account = $line->variant?->product?->revenue_account_id;

                (new InvoiceLine([
                    'invoice_id' => $invoice->id,
                    'ledger_account_id' => $account,
                    'line_no' => $line->line_no,
                    'description' => $line->description,
                    'quantity' => $line->quantity,
                    'unit_price_minor' => $line->unit_price_minor,
                    'discount_minor' => $line->discount_minor,
                    'tax_rate' => $line->tax_rate,
                    'tax_minor' => $line->tax_minor,
                    'total_minor' => $line->total_minor,
                    'currency' => $line->currency,
                ]))->save();
            }

            $invoice = $this->invoicing->issue($invoice->refresh(), $on);

            $order->forceFill(['invoice_id' => $invoice->id])->save();

            return $invoice;
        });
    }

    /**
     * Cancel an order and give back what it was holding.
     *
     * Refused once anything has shipped: those goods are gone and the customer
     * has them. That is a return, which is a different act with its own
     * postings — see returns and RTO.
     */
    public function cancel(Order $order, ?string $reason = null): Order
    {
        if (! $order->isOpen()) {
            throw new RuntimeException("{$order->number} is already closed.");
        }

        if ($order->fulfilment_status !== Order::UNFULFILLED) {
            throw new RuntimeException(
                "Part of {$order->number} has already shipped. Take those goods back as a return rather than cancelling."
            );
        }

        return DB::transaction(function () use ($order, $reason) {
            foreach ($order->lines as $line) {
                if ($line->stock_reservation_id === null) {
                    continue;
                }

                $held = StockReservation::find($line->stock_reservation_id);

                if ($held !== null) {
                    $this->stock->release($held);
                }
            }

            if ($order->invoice_id !== null && $order->invoice !== null) {
                $this->invoicing->void($order->invoice, "Order {$order->number} cancelled");
            }

            $order->forceFill([
                'status' => Order::CANCELLED,
                'cancelled_reason' => $reason,
                'cancelled_at' => now(),
            ])->save();

            return $order->refresh();
        });
    }

    /**
     * Bring the order's payment figures in line with its invoice.
     *
     * The invoice is the authority — money is applied to invoices, not to
     * orders — so this copies rather than computes. Two places counting the
     * same money independently is how they come to disagree.
     */
    public function syncPayment(Order $order): Order
    {
        $order->loadMissing('invoice');
        $invoice = $order->invoice;

        if ($invoice === null) {
            return $order;
        }

        $paid = $invoice->paid_minor;

        $order->forceFill([
            'paid_minor' => $paid,
            'payment_status' => match (true) {
                $invoice->status === Invoice::VOID => Order::REFUNDED,
                $paid <= 0 => Order::UNPAID,
                $paid >= $order->total_minor => Order::PAID,
                default => Order::PARTIAL,
            },
            'status' => $paid >= $order->total_minor && $order->fulfilment_status === Order::FULFILLED
                ? Order::COMPLETED
                : $order->status,
        ])->save();

        return $order->refresh();
    }

    public function priceLine(OrderLine $line): OrderLine
    {
        $gross = (int) round((float) $line->quantity * $line->unit_price_minor);
        $net = max(0, $gross - $line->discount_minor);
        $tax = (int) round($net * ((float) $line->tax_rate / 100));

        $line->tax_minor = $tax;
        $line->total_minor = $net + $tax;

        return $line;
    }

    private function retotal(Order $order): void
    {
        $lines = $order->lines()->get();

        $subtotal = $discount = $tax = $total = 0;

        foreach ($lines as $line) {
            $subtotal += (int) round((float) $line->quantity * $line->unit_price_minor);
            $discount += $line->discount_minor;
            $tax += $line->tax_minor;
            $total += $line->total_minor;
        }

        $order->forceFill([
            'subtotal_minor' => $subtotal,
            'discount_minor' => $discount,
            'tax_minor' => $tax,
            'total_minor' => $total + $order->shipping_minor,
        ])->save();
    }

    private function refreshFulfilment(Order $order): void
    {
        $lines = $order->lines()->get();

        $all = $lines->every(fn (OrderLine $line) => $line->isFulfilled());
        $any = $lines->contains(fn (OrderLine $line) => (float) $line->quantity_fulfilled > 0);

        $order->forceFill([
            'fulfilment_status' => match (true) {
                $all => Order::FULFILLED,
                $any => Order::PARTIAL,
                default => Order::UNFULFILLED,
            },
            'status' => $all && $order->status === Order::CONFIRMED ? Order::FULFILLED : $order->status,
        ])->save();
    }

    /** SO-2026-0001, restarting each year. */
    private function nextNumber(?string $date = null): string
    {
        $prefix = 'SO-'.substr($date ?? now()->toDateString(), 0, 4).'-';

        $last = Order::query()
            ->where('business_id', $this->tenant->business()->id)
            ->where('number', 'like', $prefix.'%')
            ->orderByDesc('number')
            ->value('number');

        return $prefix.str_pad((string) ($last === null ? 1 : ((int) substr($last, strlen($prefix))) + 1), 4, '0', STR_PAD_LEFT);
    }
}
