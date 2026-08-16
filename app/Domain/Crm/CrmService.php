<?php

declare(strict_types=1);

namespace App\Domain\Crm;

use App\Domain\Crm\Models\Deal;
use App\Domain\Crm\Models\DealStageEvent;
use App\Domain\Crm\Models\Lead;
use App\Domain\Crm\Models\PipelineStage;
use App\Domain\Crm\Models\Quote;
use App\Domain\Crm\Models\QuoteLine;
use App\Domain\Sales\Models\Customer;
use App\Domain\Sales\Models\Order;
use App\Domain\Sales\OrderService;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The front of the funnel.
 *
 * ── Conversion links rather than replaces ────────────────────────────────────
 *
 * Converting a lead creates a customer and leaves the lead standing, marked and
 * pointing at what it became. Every marketing question depends on that link:
 * which source produces customers, what a lead from each channel is worth, how
 * many were needed for each one that converted. Delete the lead and all three
 * become unanswerable — and the loss is silent, because the customer looks
 * perfectly fine.
 *
 * ── An accepted quote becomes an order, not an invoice ───────────────────────
 *
 * Because the order is what reserves stock, gets picked and eventually ships,
 * and the invoice is raised from that. A quote that jumped straight to an
 * invoice would bill for goods nobody had allocated.
 */
final class CrmService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly OrderService $orders,
    ) {}

    /**
     * Give a business a pipeline to start from.
     *
     * Idempotent, and does nothing at all if they already have stages — a
     * subscriber who has built their own process must never find ours appended
     * to it.
     *
     * @return int how many were created
     */
    public function installPipeline(): int
    {
        if (PipelineStage::query()->exists()) {
            return 0;
        }

        $made = 0;

        foreach (PipelineStage::defaults() as $order => $stage) {
            PipelineStage::create([...$stage, 'sort_order' => ($order + 1) * 10]);
            $made++;
        }

        return $made;
    }

    /**
     * Turn a lead into a customer, and optionally open a deal on them.
     *
     * @throws RuntimeException if it has already been converted
     */
    public function convert(Lead $lead, array $options = []): Customer
    {
        if ($lead->status === Lead::CONVERTED) {
            throw new RuntimeException("{$lead->name} has already been converted.");
        }

        return DB::transaction(function () use ($lead, $options) {
            $customer = Customer::create([
                'name' => $options['name'] ?? $lead->company ?? $lead->name,
                'email' => $lead->email,
                'phone' => $lead->phone,
                'company' => $lead->company,
                'billing_country' => $lead->country,
                'payment_terms_days' => $options['payment_terms_days'] ?? null,
                'notes' => $lead->notes,
            ]);

            $lead->forceFill([
                'status' => Lead::CONVERTED,
                'converted_customer_id' => $customer->id,
                'converted_at' => now(),
            ])->save();

            // Deals already open on the lead now belong to the customer too.
            // Leaving them attached only to the lead means the customer's
            // record shows no history of how they were won.
            Deal::where('lead_id', $lead->id)->update(['customer_id' => $customer->id]);

            return $customer;
        });
    }

    /**
     * Open a deal.
     */
    public function openDeal(string $title, Money $value, array $options = []): Deal
    {
        $stage = $options['stage'] ?? PipelineStage::query()->ordered()->first();

        if ($stage === null) {
            throw new RuntimeException('This business has no pipeline yet. Set the stages up first.');
        }

        return Deal::create([
            'pipeline_stage_id' => $stage->id,
            'lead_id' => ($options['lead'] ?? null)?->id,
            'customer_id' => ($options['customer'] ?? null)?->id
                ?? ($options['lead'] ?? null)?->converted_customer_id,
            'title' => $title,
            'currency' => $value->currency,
            'value_minor' => $value->minor,
            'probability' => $options['probability'] ?? $stage->probability,
            'expected_close_on' => $options['expected_close_on'] ?? null,
            'owner_id' => $options['owner_id'] ?? auth()->id(),
            'stage_changed_at' => now(),
        ]);
    }

    /**
     * Move a deal to another stage.
     *
     * Records the move and how long it sat where it was. Moving into a stage
     * whose outcome is won or lost closes the deal, because a stage called
     * "Won" that leaves the deal open is a pipeline that never empties.
     */
    public function moveTo(Deal $deal, PipelineStage $stage, array $options = []): Deal
    {
        if (! $deal->isOpen()) {
            throw new RuntimeException("{$deal->title} is already closed.");
        }

        if ($deal->pipeline_stage_id === $stage->id) {
            return $deal;
        }

        return DB::transaction(function () use ($deal, $stage, $options) {
            $since = $deal->stage_changed_at ?? $deal->created_at;

            DealStageEvent::create([
                'deal_id' => $deal->id,
                'from_stage_id' => $deal->pipeline_stage_id,
                'to_stage_id' => $stage->id,
                'days_in_previous' => $since === null ? null : (int) $since->diffInDays(now()),
                'moved_by' => auth()->id(),
                'moved_at' => now(),
            ]);

            $deal->forceFill([
                'pipeline_stage_id' => $stage->id,
                // The stage's default follows the deal unless somebody has
                // deliberately set one on this deal.
                'probability' => $options['probability'] ?? $stage->probability,
                'stage_changed_at' => now(),
                'outcome' => $stage->outcome,
                'closed_on' => $stage->isClosing() ? now()->toDateString() : null,
                'lost_reason' => $stage->outcome === PipelineStage::LOST
                    ? ($options['lost_reason'] ?? $deal->lost_reason)
                    : null,
            ])->save();

            return $deal->refresh();
        });
    }

    /**
     * Close a deal without moving it through a stage.
     *
     * A lost reason is required, and that is deliberate. "Why do we lose" is
     * the most valuable report a CRM produces and it is only as good as the
     * least disciplined moment of data entry — so the discipline is enforced
     * where the data is created rather than hoped for.
     */
    public function close(Deal $deal, string $outcome, ?string $reason = null): Deal
    {
        if (! $deal->isOpen()) {
            throw new RuntimeException("{$deal->title} is already closed.");
        }

        if ($outcome === Deal::LOST && ($reason === null || trim($reason) === '')) {
            throw new RuntimeException('A lost deal needs a reason — it is the only way to learn anything from it.');
        }

        $stage = PipelineStage::query()->where('outcome', $outcome)->ordered()->first();

        if ($stage !== null) {
            return $this->moveTo($deal, $stage, ['lost_reason' => $reason]);
        }

        $deal->forceFill([
            'outcome' => $outcome,
            'closed_on' => now()->toDateString(),
            'lost_reason' => $outcome === Deal::LOST ? $reason : null,
            'probability' => $outcome === Deal::WON ? 100 : 0,
        ])->save();

        return $deal->refresh();
    }

    /**
     * Draft a quote.
     *
     * @param  list<array{description: string, quantity: float, unit_price: Money, tax_rate?: float, variant_id?: int}>  $lines
     */
    public function quote(array $lines, array $options = []): Quote
    {
        $business = $this->tenant->business();

        if ($business === null) {
            throw new RuntimeException('No set of books is open.');
        }

        /** @var Deal|null $deal */
        $deal = $options['deal'] ?? null;
        $currency = $options['currency'] ?? $deal?->currency ?? $business->base_currency;

        return DB::transaction(function () use ($lines, $options, $deal, $currency) {
            $quote = Quote::create([
                'deal_id' => $deal?->id,
                'customer_id' => ($options['customer'] ?? null)?->id ?? $deal?->customer_id,
                'lead_id' => ($options['lead'] ?? null)?->id ?? $deal?->lead_id,
                'number' => $options['number'] ?? $this->nextQuoteNumber(),
                'issued_on' => $options['issued_on'] ?? now()->toDateString(),
                'valid_until' => $options['valid_until'] ?? now()->addDays(30)->toDateString(),
                'currency' => $currency,
                'terms' => $options['terms'] ?? null,
                'created_by' => auth()->id(),
            ]);

            $no = 1;
            $subtotal = $discount = $tax = $total = 0;

            foreach ($lines as $row) {
                /** @var Money $price */
                $price = $row['unit_price'];
                $gross = (int) round((float) $row['quantity'] * $price->minor);
                $lineDiscount = (int) ($row['discount_minor'] ?? 0);
                $net = max(0, $gross - $lineDiscount);
                $lineTax = (int) round($net * ((float) ($row['tax_rate'] ?? 0) / 100));

                QuoteLine::create([
                    'quote_id' => $quote->id,
                    'product_variant_id' => $row['variant_id'] ?? null,
                    'line_no' => $no++,
                    'description' => $row['description'],
                    'quantity' => $row['quantity'],
                    'unit_price_minor' => $price->minor,
                    'discount_minor' => $lineDiscount,
                    'tax_rate' => $row['tax_rate'] ?? 0,
                    'tax_minor' => $lineTax,
                    'total_minor' => $net + $lineTax,
                    'currency' => $currency,
                ]);

                $subtotal += $gross;
                $discount += $lineDiscount;
                $tax += $lineTax;
                $total += $net + $lineTax;
            }

            $quote->forceFill([
                'subtotal_minor' => $subtotal,
                'discount_minor' => $discount,
                'tax_minor' => $tax,
                'total_minor' => $total,
            ])->save();

            return $quote->refresh()->load('lines');
        });
    }

    /**
     * Send it. From here the figures are what the customer is looking at.
     */
    public function send(Quote $quote): Quote
    {
        if ($quote->status !== Quote::DRAFT) {
            throw new RuntimeException("{$quote->number} has already been sent.");
        }

        $quote->forceFill(['status' => Quote::SENT, 'sent_at' => now()])->save();

        return $quote->refresh();
    }

    /**
     * Revise a sent quote: a new version, with the old one kept as it was.
     */
    public function revise(Quote $quote, array $lines, array $options = []): Quote
    {
        if (! $quote->isOpen()) {
            throw new RuntimeException("{$quote->number} has been decided, so it cannot be revised.");
        }

        return DB::transaction(function () use ($quote, $lines, $options) {
            $number = $quote->number;
            $version = $quote->version;

            // The old one is renamed *first*. The number is unique per
            // business, so creating the revision while the original still
            // holds it violates the index — and the constraint is right: two
            // live quotes with one number is exactly the confusion it exists
            // to prevent.
            $quote->forceFill([
                'number' => $number.'-v'.$version,
                'status' => Quote::SUPERSEDED,
                'decided_at' => now(),
            ])->save();

            $revision = $this->quote($lines, [
                ...$options,
                'deal' => $quote->deal,
                'customer' => $quote->customer,
                'currency' => $quote->currency,
                // The live version keeps the number people are quoting at each
                // other on the phone; the superseded one takes the suffix.
                'number' => $number,
            ]);

            $revision->forceFill([
                'supersedes_id' => $quote->id,
                'version' => $version + 1,
            ])->save();

            return $revision->refresh()->load('lines');
        });
    }

    /**
     * The customer said yes: turn it into an order.
     */
    public function accept(Quote $quote, array $options = []): Order
    {
        if ($quote->status !== Quote::SENT) {
            throw new RuntimeException("{$quote->number} has not been sent, so there is nothing to accept.");
        }

        if ($quote->hasExpired() && ! ($options['accept_expired'] ?? false)) {
            throw new RuntimeException(
                "{$quote->number} expired on {$quote->valid_until->toDateString()}. Revise it, or accept it deliberately."
            );
        }

        $quote->loadMissing('lines', 'customer', 'deal');

        if ($quote->customer === null) {
            throw new RuntimeException('That quote is not against a customer. Convert the lead first.');
        }

        return DB::transaction(function () use ($quote, $options) {
            $order = $this->orders->open($quote->customer, [
                'ordered_on' => $options['ordered_on'] ?? now()->toDateString(),
                'currency' => $quote->currency,
                'channel' => 'quote',
                'external_ref' => $quote->number,
            ]);

            foreach ($quote->lines as $line) {
                if ($line->product_variant_id === null) {
                    // A quote line for something not in the catalogue — a fee, a
                    // custom job. It still belongs on the order, at the price
                    // that was quoted.
                    (new \App\Domain\Sales\Models\OrderLine([
                        'order_id' => $order->id,
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

                    continue;
                }

                $this->orders->addLine(
                    $order,
                    \App\Domain\Catalogue\Models\ProductVariant::findOrFail($line->product_variant_id),
                    (float) $line->quantity,
                    new Money($line->unit_price_minor, $line->currency),
                    [
                        'description' => $line->description,
                        'discount_minor' => $line->discount_minor,
                        'tax_rate' => $line->tax_rate,
                        'line_no' => $line->line_no,
                    ],
                );
            }

            $quote->forceFill([
                'status' => Quote::ACCEPTED,
                'decided_at' => now(),
                'order_id' => $order->id,
            ])->save();

            // Winning the quote wins the deal. Leaving it open is how a
            // pipeline fills with work that has already been done.
            if ($quote->deal !== null && $quote->deal->isOpen()) {
                $this->close($quote->deal, Deal::WON);
                $quote->deal->forceFill(['order_id' => $order->id])->save();
            }

            return $order->refresh()->load('lines');
        });
    }

    public function decline(Quote $quote, ?string $reason = null): Quote
    {
        if (! $quote->isOpen()) {
            throw new RuntimeException("{$quote->number} has already been decided.");
        }

        $quote->forceFill([
            'status' => Quote::DECLINED,
            'decided_at' => now(),
            'notes' => trim((string) $quote->notes."\nDeclined: ".($reason ?? 'no reason given')),
        ])->save();

        return $quote->refresh();
    }

    private function nextQuoteNumber(?string $date = null): string
    {
        $prefix = 'QUO-'.substr($date ?? now()->toDateString(), 0, 4).'-';

        $last = Quote::query()
            ->where('business_id', $this->tenant->business()->id)
            ->where('number', 'like', $prefix.'%')
            ->orderByDesc('number')
            ->value('number');

        $n = $last === null ? 0 : (int) Str::of($last)->after($prefix)->before('-')->toString();

        return $prefix.str_pad((string) ($n + 1), 4, '0', STR_PAD_LEFT);
    }
}
