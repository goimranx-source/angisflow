<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Reconcilers;

use App\Domain\Catalogue\Models\Product;
use App\Domain\Catalogue\Models\ProductVariant;
use App\Domain\Integrations\Models\Integration;
use App\Domain\Integrations\Support\LineItems;
use App\Domain\Integrations\Support\MappedRecord;
use App\Domain\Sales\Models\Order;
use App\Domain\Sales\Models\OrderLine;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * A shop's order, becoming ours.
 *
 * ── What this deliberately does not do ───────────────────────────────────────
 *
 * It does not post to the ledger and it does not move stock.
 *
 * That restraint is the most important decision in this file. An order arriving
 * from a shop is a record of something that already happened somewhere else,
 * and the shop has usually already decremented its own stock and taken the
 * money. Confirming it here as though it were a new sale would decrement stock a
 * second time and post revenue that the books may already carry from the
 * payment side — and both are corrections somebody has to unpick by hand, in a
 * ledger, months later.
 *
 * So imported orders land as records. Whether they then post is a decision the
 * business makes explicitly, through the connection's own settings, once
 * somebody has decided which system is the authority on stock. `post_to_ledger`
 * is off until they do.
 *
 * ── The shop's total wins ────────────────────────────────────────────────────
 *
 * Line totals are recomputed here from quantity and price, and they will
 * sometimes disagree with the figure the shop charged — different tax rounding,
 * a discount rule this application has never seen, shipping folded in
 * differently. The shop took the money, so the shop's total is the truth, and
 * it is written over the computed one rather than reconciled to it.
 */
class OrderReconciler
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $statusColumns  from the status map
     */
    public function apply(
        Integration $integration,
        MappedRecord $record,
        array $payload,
        ?int $localId,
        ?int $customerId = null,
        array $statusColumns = [],
    ): ?Order {
        $attributes = [...$record->attributes, ...$statusColumns];
        $currency = (string) ($attributes['currency'] ?? $integration->business?->base_currency ?? 'USD');

        $order = $localId === null ? null : Order::query()->find($localId);

        if ($order !== null) {
            return $this->update($order, $attributes, $integration, $payload, $currency);
        }

        return $this->create($integration, $attributes, $payload, $currency, $customerId);
    }

    /**
     * An order we already hold.
     *
     * Lines are reconciled rather than left alone — see syncLines, which tells
     * a payload that carries no items apart from one that carries an empty
     * list, so a partial webhook still cannot empty an order.
     *
     * @param  array<string, mixed>  $attributes
     */
    /**
     * @param  array<string, mixed>  $payload
     */
    private function update(Order $order, array $attributes, Integration $integration, array $payload = [], string $currency = 'USD'): Order
    {
        // Never re-dated. A shop that re-sends an order with today's timestamp
        // would otherwise move a March sale into August, and every report built
        // on order dates would quietly shift with it.
        unset($attributes['ordered_on'], $attributes['number']);

        /*
         * An adopted order is claimed for the shop it came from.
         *
         * Orders that arrived before this connection existed — imported by an
         * earlier tool, or delivered by webhook while the connection had no
         * storefront yet — sit in the books attributed to nothing. Recognising
         * one and then leaving it unattributed would mean every per-shop
         * figure kept undercounting it, and the next sync would have to
         * recognise it all over again.
         *
         * Only ever filled in, never moved: an order already belonging to a
         * storefront keeps it. Reassigning one would take a sale out of one
         * shop's books and put it in another's.
         */
        if ($order->storefront_id === null && $integration->storefront_id !== null) {
            $order->storefront_id = $integration->storefront_id;
        }

        // Archive state follows the shop's authoritative lifecycle status.
        // Without this, an order completed here keeps its old archived_at after
        // a full sync reports it as processing, leaving an active order stranded
        // in the archive tab.
        if (array_key_exists('status', $attributes)) {
            $order->archived_at = in_array($attributes['status'], [Order::COMPLETED, Order::CANCELLED], true)
                ? ($order->archived_at ?? now())
                : null;
        }

        $order->fill($attributes)->save();

        $this->syncLines($integration, $order, $payload, $currency);

        return $order;
    }

    /**
     * Bring the order's items into line with what the shop now holds.
     *
     * ── Why this replaced "leave the lines alone" ────────────────────────────
     *
     * Lines used to be written once, on import, and never touched again. The
     * reasoning was sound as far as it went: a webhook often carries a partial
     * record, and rebuilding an order's items from one would empty it.
     *
     * But never updating them is not safe either, and it fails quietly. An item
     * added to an order in the shop never arrived here, so the order showed one
     * line under a total that covered two — a summary that visibly does not add
     * up, and a picking list that is wrong.
     *
     * It was worse than a display problem. The push sends the items we hold and
     * asks the shop to remove anything else, so the line we never imported
     * would have been deleted from the customer's real order the next time
     * anything about it changed here.
     *
     * ── How the original worry is answered ───────────────────────────────────
     *
     * By telling "the shop sent no items" apart from "the shop sent an empty
     * list". A payload with no line container at all is a partial record and is
     * left strictly alone. Only a payload that actually carries the items is
     * treated as authoritative about them.
     *
     * Lines of ours that the shop has never heard of — added here and not yet
     * pushed — are kept regardless. They are not missing from the shop's list
     * because they were removed; they are missing because they have not been
     * sent yet, and deleting them would undo somebody's work.
     *
     * @param  array<string, mixed>  $payload
     */
    private function syncLines(Integration $integration, Order $order, array $payload, string $currency): void
    {
        if ($payload === [] || ! LineItems::present($integration, $payload)) {
            return;
        }

        $incoming = LineItems::from($integration, $payload, $currency);
        $existing = $order->lines()->get();
        $seen = [];
        $number = 1;

        foreach ($incoming as $line) {
            $unit = $line['unit_price_minor']
                ?? ($line['total_minor'] === null ? 0 : (int) round($line['total_minor'] / max($line['quantity'], 0.001)));
            $total = $line['total_minor'] ?? (int) round($unit * $line['quantity']);

            $match = $this->matchLine($existing, $line);

            if ($match !== null) {
                $seen[] = $match->id;

                $match->forceFill([
                    'external_id' => $line['external_id'] ?? $match->external_id,
                    'line_no' => $number++,
                    'description' => $line['name'],
                    'quantity' => $line['quantity'],
                    'unit_price_minor' => $unit,
                    'total_minor' => $total,
                ])->save();

                continue;
            }

            $variant = $this->resolveVariant($integration, $line, $currency);

            $created = OrderLine::query()->create([
                'order_id' => $order->id,
                'product_variant_id' => $variant?->id,
                'line_no' => $number++,
                'external_id' => $line['external_id'] ?? null,
                'sku' => $line['sku'] !== '' ? $line['sku'] : $variant?->sku,
                'description' => $line['name'],
                'quantity' => $line['quantity'],
                'unit_price_minor' => $unit,
                'discount_minor' => 0,
                'tax_rate' => 0,
                'currency' => $currency,
                'tax_minor' => 0,
                'total_minor' => $total,
            ]);

            $seen[] = $created->id;
        }

        // Gone from the shop's list, and known to the shop — so genuinely
        // removed there rather than simply not sent yet.
        foreach ($existing as $line) {
            if (! in_array($line->id, $seen, true) && $line->external_id !== null) {
                $line->delete();
            }
        }
    }

    /**
     * Which of our lines this incoming one is.
     *
     * By the shop's own id where we have it, since that is exact. Falling back
     * to SKU covers the first sync after ids began being kept, and the orders
     * imported before that.
     *
     * @param  Collection<int, OrderLine>  $existing
     * @param  array{external_id: string|null, sku: string, name: string, quantity: float, unit_price_minor: int|null, total_minor: int|null}  $line
     */
    private function matchLine($existing, array $line): ?OrderLine
    {
        $externalId = $line['external_id'] ?? null;

        if ($externalId !== null) {
            $byId = $existing->firstWhere('external_id', $externalId);

            if ($byId !== null) {
                return $byId;
            }
        }

        $sku = mb_strtolower(trim((string) $line['sku']));

        if ($sku === '') {
            return null;
        }

        return $existing->first(fn (OrderLine $row): bool => $row->external_id === null
            && mb_strtolower(trim((string) $row->sku)) === $sku);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $payload
     */
    private function create(
        Integration $integration,
        array $attributes,
        array $payload,
        string $currency,
        ?int $customerId,
    ): Order {
        $status = $attributes['status'] ?? 'confirmed';

        $order = Order::query()->create([
            'account_id' => $integration->account_id,
            'business_id' => $integration->business_id,
            'customer_id' => $customerId,
            'storefront_id' => $integration->storefront_id,
            // Where it came from, so a report can tell shop orders from till
            // ones without inspecting the integration table.
            'channel' => 'online',
            'currency' => $currency,
            'number' => $attributes['number'] ?? $this->fallbackNumber($integration),
            'ordered_on' => $attributes['ordered_on'] ?? now()->toDateString(),
            'status' => $status,
            'archived_at' => in_array($status, [Order::COMPLETED, Order::CANCELLED], true) ? now() : null,
            'payment_status' => $attributes['payment_status'] ?? 'unpaid',
            'fulfilment_status' => $attributes['fulfilment_status'] ?? 'unfulfilled',
            ...$attributes,
        ]);

        $this->addLines($integration, $order, $payload, $currency);
        $this->applyShopTotals($order, $attributes);

        return $order;
    }

    /**
     * Put the purchased items on the order.
     *
     * @param  array<string, mixed>  $payload
     */
    private function addLines(Integration $integration, Order $order, array $payload, string $currency): void
    {
        $lines = LineItems::from($integration, $payload, $currency);
        $number = 1;

        foreach ($lines as $line) {
            $variant = $this->resolveVariant($integration, $line, $currency);

            $unit = $line['unit_price_minor']
                ?? ($line['total_minor'] === null ? 0 : (int) round($line['total_minor'] / max($line['quantity'], 0.001)));

            $total = $line['total_minor'] ?? (int) round($unit * $line['quantity']);

            OrderLine::query()->create([
                'order_id' => $order->id,
                'product_variant_id' => $variant?->id,
                'line_no' => $number++,
                // The shop's own id for this row, so a later push edits it
                // in place rather than adding a second one beside it.
                'external_id' => $line['external_id'] ?? null,
                'sku' => $line['sku'] !== '' ? $line['sku'] : $variant?->sku,
                'description' => $line['name'],
                'quantity' => $line['quantity'],
                'unit_price_minor' => $unit,
                'discount_minor' => 0,
                'tax_rate' => 0,
                'currency' => $currency,
                'tax_minor' => 0,
                'total_minor' => $total,
            ]);
        }
    }

    /**
     * The variant a line refers to, creating a placeholder if need be.
     *
     * ── Why an unknown SKU still becomes something ───────────────────────────
     *
     * An order line pointing at nothing is an order that cannot be picked,
     * costed or returned. A business that connects its shop before syncing its
     * catalogue — which is most of them, in whichever order the screen offers —
     * would otherwise import a book of orders full of blanks.
     *
     * So an unrecognised SKU creates a minimal product, named from the line
     * itself, which a later product sync will match on that same SKU and fill
     * in properly.
     *
     * @param  array{sku: string, name: string, quantity: float, unit_price_minor: int|null, total_minor: int|null}  $line
     */
    private function resolveVariant(Integration $integration, array $line, string $currency): ?ProductVariant
    {
        if ($line['sku'] !== '') {
            $variant = ProductVariant::query()
                ->where('business_id', $integration->business_id)
                ->whereRaw('LOWER(sku) = ?', [mb_strtolower($line['sku'])])
                ->first();

            if ($variant !== null) {
                return $variant;
            }
        }

        $product = Product::query()->create([
            'account_id' => $integration->account_id,
            'business_id' => $integration->business_id,
            'name' => $line['name'],
            'slug' => Str::slug($line['name']).'-'.Str::lower(Str::random(5)),
            'kind' => 'good',
            'is_stocked' => true,
            'is_active' => true,
        ]);

        return ProductVariant::query()->create([
            'account_id' => $integration->account_id,
            'business_id' => $integration->business_id,
            'product_id' => $product->id,
            'sku' => $line['sku'] !== '' ? $line['sku'] : 'IMP-'.strtoupper(Str::random(6)),
            'name' => $line['name'],
            'price_minor' => $line['unit_price_minor'] ?? 0,
            'currency' => $currency,
            'is_default' => true,
            'is_active' => true,
            'position' => 0,
        ]);
    }

    /**
     * Write the shop's own figures over anything computed from the lines.
     *
     * Only the ones it actually sent — a shop that reports a total but no tax
     * breakdown should not have its tax silently zeroed.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function applyShopTotals(Order $order, array $attributes): void
    {
        $totals = array_intersect_key($attributes, array_flip([
            'subtotal_minor', 'discount_minor', 'shipping_minor',
            'tax_minor', 'total_minor', 'paid_minor',
        ]));

        if ($totals === []) {
            return;
        }

        /*
         * Subtotal is derived when the shop did not send one, because a total
         * with no subtotal leaves every order detail screen showing a blank
         * line above a filled-in one.
         */
        if (! isset($totals['subtotal_minor']) && isset($totals['total_minor'])) {
            $totals['subtotal_minor'] = max(0, (int) $totals['total_minor']
                - (int) ($totals['tax_minor'] ?? 0)
                - (int) ($totals['shipping_minor'] ?? 0)
                + (int) ($totals['discount_minor'] ?? 0));
        }

        $order->forceFill($totals)->save();
    }

    /**
     * A number for a shop that sends none.
     *
     * Prefixed so it is obvious on screen that this came from a connection and
     * was not typed by anyone here.
     */
    private function fallbackNumber(Integration $integration): string
    {
        return 'IMP-'.$integration->id.'-'.strtoupper(Str::random(6));
    }
}
