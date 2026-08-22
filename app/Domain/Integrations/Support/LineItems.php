<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Support;

use App\Domain\Integrations\Models\Integration;
use App\Domain\Money\Currencies;

/**
 * The things somebody actually bought, whatever their shop calls them.
 *
 * ── Why lines are not a field mapping ────────────────────────────────────────
 *
 * Every other mapped field is one value at one path. Lines are a repeating
 * structure, and each one needs four things read out of it in step — a SKU, a
 * description, a quantity and a price. Expressed as field maps that would be
 * four rules per line index against a list whose length changes per order,
 * which the mapping model has no way to say.
 *
 * So the shape of a line is knowledge about the platform, kept here beside the
 * other platform knowledge, and read out into one normalised form the order
 * reconciler can work with.
 *
 * ── Why the money is read here ───────────────────────────────────────────────
 *
 * Because the platforms disagree about what "price" means on a line. Woo's
 * `price` is per unit but its `total` is the whole line net of discount;
 * Shopify's `price` is per unit and its discounts live in a separate structure.
 * Reading unit price and line total separately, and letting the reconciler
 * prefer whichever the platform is authoritative about, is the only way the
 * arithmetic ends up matching the total the customer was charged.
 */
final class LineItems
{
    /**
     * Read the lines out of one order payload.
     *
     * @param  array<string, mixed>  $payload
     * @return list<array{external_id: string|null, sku: string, name: string, quantity: float, unit_price_minor: int|null, total_minor: int|null}>
     */
    public static function from(Integration $integration, array $payload, string $currency): array
    {
        $provider = strtolower((string) $integration->provider);

        [$path, $keys] = match ($provider) {
            'woocommerce' => ['line_items', ['id' => 'id', 'sku' => 'sku', 'name' => 'name', 'qty' => 'quantity', 'unit' => 'price', 'total' => 'total']],
            'shopify' => ['line_items', ['id' => 'id', 'sku' => 'sku', 'name' => 'title', 'qty' => 'quantity', 'unit' => 'price', 'total' => null]],
            'webflow' => ['purchasedItems', ['id' => null, 'sku' => 'variantSKU', 'name' => 'productName', 'qty' => 'count', 'unit' => 'variantPrice.value', 'total' => 'rowTotal.value']],
            default => [
                // A bespoke site is asked where its lines are and what it calls
                // the four fields, because nothing here could know.
                trim((string) $integration->config('lines_path', 'line_items')),
                [
                    'id' => (string) $integration->config('line_id_field', 'id'),
                    'sku' => (string) $integration->config('line_sku_field', 'sku'),
                    'name' => (string) $integration->config('line_name_field', 'name'),
                    'qty' => (string) $integration->config('line_quantity_field', 'quantity'),
                    'unit' => (string) $integration->config('line_price_field', 'price'),
                    'total' => (string) $integration->config('line_total_field', 'total'),
                ],
            ],
        };

        if ($path === '') {
            return [];
        }

        $rows = FieldPath::resolve($payload, $path, []);

        if (! is_array($rows)) {
            return [];
        }

        $scale = 10 ** Currencies::scale($currency);
        $lines = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $quantity = (float) Transform::apply(FieldPath::resolve($row, (string) $keys['qty']), 'decimal');

            // A line for nothing is not a line. Some platforms leave a
            // zero-quantity row behind when an item is removed from an order.
            if ($quantity <= 0) {
                continue;
            }

            /*
             * The platform's own id for this line.
             *
             * Kept because it is the difference between editing an order and
             * rebuilding it. Pushing a line without an id makes the shop add a
             * new one beside the old, so an order edited here twice ends up with
             * the item on it three times. With the id, the same line is updated
             * in place and the shop keeps its own tax and stock working against
             * a row it recognises.
             */
            $lineId = isset($keys['id']) && $keys['id'] !== null && $keys['id'] !== ''
                ? FieldPath::resolve($row, (string) $keys['id'])
                : null;

            $lines[] = [
                'external_id' => $lineId === null || $lineId === '' || is_array($lineId) ? null : (string) $lineId,
                'sku' => trim((string) FieldPath::resolve($row, (string) $keys['sku'], '')),
                'name' => trim((string) FieldPath::resolve($row, (string) $keys['name'], '')) ?: 'Item',
                'quantity' => $quantity,
                'unit_price_minor' => self::minor($row, $keys['unit'], $scale),
                'total_minor' => self::minor($row, $keys['total'], $scale),
            ];
        }

        return $lines;
    }

    /**
     * Does this payload actually carry the order's items?
     *
     * ── Why absent and empty must be told apart ──────────────────────────────
     *
     * Because they mean opposite things. A payload with no line container is a
     * partial record — a webhook reporting that a status changed, say — and
     * says nothing at all about the items. A payload carrying an empty list is
     * a complete record of an order with nothing on it.
     *
     * Treating the first as the second empties orders. Treating the second as
     * the first leaves items on an order the shop has cleared. from() cannot
     * distinguish them, because both come back as no lines — so the question is
     * asked of the payload directly.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function present(Integration $integration, array $payload): bool
    {
        $path = match (strtolower((string) $integration->provider)) {
            'woocommerce', 'shopify' => 'line_items',
            'webflow' => 'purchasedItems',
            default => trim((string) $integration->config('lines_path', 'line_items')),
        };

        return $path !== '' && is_array(FieldPath::resolve($payload, $path));
    }

    /**
     * Our order lines, as the change the shop should apply to its own.
     *
     * ── Why this is a diff and not a list ────────────────────────────────────
     *
     * Because these APIs merge rather than replace. Sending the lines we hold
     * would add every one of them beside whatever is already there, so an order
     * edited twice would show each item three times. What they understand is an
     * instruction per row: this existing one now has this quantity, add this new
     * one, take that one off.
     *
     * So each of ours carries the shop's own line id where we know it — see the
     * external_id captured on the way in — and anything the shop still has that
     * we no longer do is asked to be removed. Removal is a quantity of zero,
     * which is how WooCommerce spells it.
     *
     * ── Why totals are sent and the grand total is not ───────────────────────
     *
     * Because the shop computes the order total from its lines, its shipping and
     * its own tax rules — its schema marks `total` read-only and would ignore
     * ours. Changing what an order costs therefore means changing what is on it,
     * and letting the shop do the arithmetic it insists on doing.
     *
     * @param  list<array{external_id: string|null, product_external_id: string|null, name: string, quantity: float, unit_price_minor: int, total_minor: int}>  $ours
     * @param  list<string>  $remoteIds  line ids the shop currently holds
     * @return array<string, mixed> the payload fragment, empty when unsupported
     */
    public static function toPlatform(Integration $integration, array $ours, array $remoteIds, string $currency): array
    {
        $provider = strtolower((string) $integration->provider);

        // Only where the shape is actually known. Guessing at a bespoke site's
        // line format would post nonsense into somebody's order.
        if ($provider !== 'woocommerce') {
            return [];
        }

        $scale = 10 ** Currencies::scale($currency);
        $money = static fn (int $minor): string => number_format($minor / $scale, Currencies::scale($currency), '.', '');

        $rows = [];
        $kept = [];

        foreach ($ours as $line) {
            $quantity = (float) $line['quantity'];

            if ($quantity <= 0) {
                continue;
            }

            $total = $money((int) $line['total_minor']);

            if (($line['external_id'] ?? null) !== null && $line['external_id'] !== '') {
                $kept[] = (string) $line['external_id'];

                $rows[] = [
                    'id' => (int) $line['external_id'],
                    'quantity' => $quantity,
                    // Subtotal is before discount and total after. We hold one
                    // figure, so both are sent as it: claiming a discount the
                    // order does not have would show one on the customer's copy.
                    'subtotal' => $total,
                    'total' => $total,
                ];

                continue;
            }

            /*
             * A line the shop has never seen.
             *
             * It needs the shop's own product id, because a line item's `sku` is
             * read-only in the schema — it is reported, not accepted. Without a
             * product link there is nothing to point at, so the line is left out
             * rather than posted as something the shop would reject or, worse,
             * accept as an unnamed row.
             */
            if (($line['product_external_id'] ?? null) === null) {
                continue;
            }

            $rows[] = [
                'product_id' => (int) $line['product_external_id'],
                'quantity' => $quantity,
                'subtotal' => $total,
                'total' => $total,
            ];
        }

        // Anything the shop still holds that we no longer do.
        foreach (array_diff($remoteIds, $kept) as $gone) {
            $rows[] = ['id' => (int) $gone, 'quantity' => 0];
        }

        return $rows === [] ? [] : ['line_items' => $rows];
    }

    /**
     * One money field off a line, in minor units.
     *
     * Webflow already reports minor units, so its paths point at `.value` and
     * the scaling below would be wrong — which is why its price paths are read
     * as integers rather than run through money_minor. Handled by checking
     * whether the resolved value is already an integer count of minor units.
     */
    private static function minor(array $row, ?string $key, int $scale): ?int
    {
        if ($key === null || $key === '') {
            return null;
        }

        $raw = FieldPath::resolve($row, $key);

        if ($raw === null || $raw === '') {
            return null;
        }

        // An integer under a `.value` path is already minor units; a decimal
        // string is major units and has to be scaled.
        if (is_int($raw) && str_contains($key, '.value')) {
            return $raw;
        }

        $decimal = Transform::apply($raw, 'decimal');

        return $decimal === null ? null : (int) round(((float) $decimal) * $scale);
    }
}
