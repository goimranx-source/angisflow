<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Support;

/**
 * What a mapping is allowed to write to, on our side.
 *
 * ── This is a security boundary, not a convenience list ──────────────────────
 *
 * A field mapping is configuration: it is edited by a subscriber, stored as
 * JSON, and then applied with something very like `$model->fill($mapped)`. If
 * the target of a mapping is whatever string was typed into it, then a mapping
 * of `account_id ← meta_data.x` is an instruction to move a record into another
 * tenant's account, written by the tenant, and applied by us on a schedule.
 *
 * So targets are an allowlist. A name that is not on this list is not written,
 * whatever the mapping says — which also means the tenancy columns, the primary
 * key, the public id and every figure this application derives for itself stay
 * out of reach of anything an external shop sends.
 *
 * The list doubles as the target dropdown on the mapping screen, so the two can
 * never disagree about what is mappable.
 */
final class EntityFields
{
    /** The prefix that sends a value to the link's custom fields, not a column. */
    public const CUSTOM_PREFIX = 'custom.';

    /**
     * Writable fields per entity: target => [label, default transform].
     *
     * Deliberately excluded everywhere: id, public_id, account_id, business_id,
     * created_by, timestamps, and anything this application computes — an order
     * count or a lifetime value arriving from a shop would overwrite a figure
     * derived from our own records with one derived from a fraction of them.
     *
     * @return array<string, array<string, array{0: string, 1: string}>>
     */
    private static function map(): array
    {
        return [
            'order' => [
                'number' => ['Order number', 'strip_hash'],
                'ordered_on' => ['Order date', 'date'],
                'status' => ['Status', 'lower'],
                'fulfilment_status' => ['Fulfilment status', 'lower'],
                'payment_status' => ['Payment status', 'lower'],
                'channel' => ['Channel', 'lower'],
                'external_ref' => ['External reference', 'trim'],
                'is_cod' => ['Cash on delivery', 'boolean'],

                /*
                 * ── The fields every platform has ────────────────────────────
                 *
                 * Kept whether or not a shop sends them. That is the point: a
                 * column with a name this application knows is the thing eight
                 * private conventions can be mapped *onto*, and until one
                 * exists there is nothing for `_orddd_timestamp` to become.
                 *
                 * See the migration that added them for where the list came
                 * from and what was deliberately left out.
                 */
                'payment_method' => ['Payment method', 'trim'],
                'transaction_ref' => ['Payment reference', 'trim'],
                'paid_at' => ['Paid on', 'datetime'],
                'promised_delivery_on' => ['Delivery date (promised)', 'date'],
                'shipping_method' => ['Shipping method', 'trim'],

                /*
                 * Where the shop says the customer came from — facebook, an ad,
                 * a marketplace. Not `channel`, which is which part of this
                 * application made the order.
                 */
                'source' => ['Order source', 'trim'],
                'refunded_minor' => ['Refunded', 'money_minor'],
                'currency' => ['Currency', 'upper'],
                'subtotal_minor' => ['Subtotal', 'money_minor'],
                'discount_minor' => ['Discount', 'money_minor'],
                'shipping_minor' => ['Shipping', 'money_minor'],
                'tax_minor' => ['Tax', 'money_minor'],
                'total_minor' => ['Total', 'money_minor'],
                'paid_minor' => ['Paid', 'money_minor'],
                /*
                 * Names, in both shapes.
                 *
                 * Nearly every platform stores a person as first_name and
                 * last_name; a few send one full name. Both are offered, and
                 * whichever is mapped ends up in the single column this
                 * application keeps — see Names::compose. A shop sending only a
                 * full name maps it to the first-name field and nothing is lost.
                 */
                'shipping_name' => ['Ship to name (full)', 'trim'],
                'shipping_first_name' => ['Ship to first name', 'trim'],
                'shipping_last_name' => ['Ship to last name', 'trim'],
                'shipping_phone' => ['Ship to phone', 'digits'],
                'shipping_address' => ['Ship to address', 'trim'],
                'shipping_city' => ['Ship to city', 'trim'],
                'shipping_postcode' => ['Ship to postcode', 'trim'],
                /*
                 * A country, not a word in capitals.
                 *
                 * `upper` is what you reach for when you are thinking about the
                 * string — it is stored as a code, so make sure it is
                 * upper-case. But the type is what every screen downstream uses
                 * to decide how to show the field, and "text in capitals" told
                 * the edit form to draw a free-text box for a value with 250
                 * legal answers. Declared as what it is, that form draws a
                 * picker without being told to.
                 */
                'shipping_country' => ['Ship to country', 'country'],
                'notes' => ['Notes', 'trim'],

                // The note the customer must not read. Six platforms hold both
                // kinds; this one held a single field that mixed them.
                'staff_notes' => ['Staff note (private)', 'textarea'],

                /*
                 * ── The buyer, mapped on the order ───────────────────────────
                 *
                 * A shop repeats the customer's details on every order it
                 * sends, so this is where they actually arrive. Mapping them on
                 * a separate customer screen meant configuring the same shop
                 * twice and then wondering which of the two an order obeyed.
                 *
                 * These do not write to the order. The sync lifts them out,
                 * finds or creates the customer, and attaches it — see
                 * PullSync::customerFrom.
                 */
                'customer.name' => ['Customer — Name (full)', 'trim'],
                'customer.first_name' => ['Customer — First name', 'trim'],
                'customer.last_name' => ['Customer — Last name', 'trim'],
                'customer.email' => ['Customer — Email', 'email'],
                'customer.phone' => ['Customer — Phone', 'digits'],
                'customer.company' => ['Customer — Company', 'trim'],
                'customer.tax_number' => ['Customer — Tax number', 'trim'],
                'customer.billing_address' => ['Customer — Billing address', 'trim'],
                'customer.billing_city' => ['Customer — Billing city', 'trim'],
                'customer.billing_postcode' => ['Customer — Billing postcode', 'trim'],
                'customer.billing_country' => ['Customer — Billing country', 'country'],
                'customer.notes' => ['Customer — Notes', 'trim'],
            ],

            'product' => [
                /*
                 * "Product name", not "Name".
                 *
                 * There is a "Variant name" a few rows below it in the same
                 * dropdown, and between the two of them a bare "Name" is a
                 * question rather than an answer — the name of what? The
                 * distinction matters more here than the shortness does: mapping
                 * a shop's title onto the variant instead of the product puts it
                 * on the thing with the SKU and leaves the catalogue entry
                 * blank.
                 */
                'name' => ['Product name', 'trim'],
                'slug' => ['Handle or slug', 'slug'],

                // Rich text, not stripped: a product description is formatted
                // copy, and flattening it loses the whole catalogue's layout.
                'description' => ['Description', 'rich_text'],
                'summary' => ['Short description', 'strip_tags'],

                'brand' => ['Brand or vendor', 'trim'],
                'kind' => ['Kind', 'lower'],
                'tax_rate' => ['Tax rate', 'percent'],
                'is_stocked' => ['Tracks stock', 'boolean'],
                'is_active' => ['Active', 'boolean'],

                /*
                 * ── What is actually sold ─────────────────────────────────────
                 *
                 * Here a product is the thing in the catalogue and a variant is
                 * the thing with a SKU, a price and a stock level. Every field a
                 * platform sends about "the product" that has a number attached
                 * — price, weight, barcode — belongs to the variant, and mapping
                 * them onto the product would have nowhere to land.
                 *
                 * Routed by ProductReconciler, which strips the prefix and
                 * writes them to the default variant.
                 */
                'variant.sku' => ['SKU', 'trim'],
                'variant.barcode' => ['Barcode or GTIN', 'trim'],
                'variant.name' => ['Variant name', 'trim'],
                'variant.price_minor' => ['Price', 'money_minor'],
                'variant.compare_at_minor' => ['Compare-at price', 'money_minor'],
                'variant.cost_minor' => ['Cost', 'money_minor'],
                'variant.currency' => ['Price currency', 'upper'],
                'variant.weight_grams' => ['Weight (grams)', 'decimal'],
                'variant.unit' => ['Unit', 'trim'],
                'variant.pack_quantity' => ['Pack quantity', 'decimal'],

                /*
                 * ── Media ─────────────────────────────────────────────────────
                 *
                 * Kept as custom fields rather than columns: the catalogue has
                 * no image columns yet, and inventing them here would be a
                 * migration made to satisfy a dropdown. Mapped this way the URLs
                 * are stored against the link and available to whatever renders
                 * them, per shop — which is right anyway, since two shops list
                 * the same product with different photography.
                 */
                'custom.image' => ['Main image', 'image'],
                'custom.gallery' => ['Image gallery', 'image_list'],
            ],

            'customer' => [
                'name' => ['Name', 'trim'],
                'email' => ['Email', 'lower'],
                'phone' => ['Phone', 'digits'],
                'company' => ['Company', 'trim'],
                'tax_number' => ['Tax number', 'trim'],
                'billing_address' => ['Billing address', 'trim'],
                'billing_city' => ['Billing city', 'trim'],
                'billing_postcode' => ['Billing postcode', 'trim'],
                'billing_country' => ['Billing country', 'upper'],
                'currency' => ['Currency', 'upper'],
                'notes' => ['Notes', 'trim'],
                'is_active' => ['Active', 'boolean'],
            ],
        ];
    }

    /** @return list<string> */
    public static function entities(): array
    {
        return array_keys(self::map());
    }

    public static function isEntity(string $entity): bool
    {
        return array_key_exists($entity, self::map());
    }

    /**
     * Fields the shop computes for itself, and we must never write back.
     *
     * ── Why a guard and not just a direction on the mapping ──────────────────
     *
     * There is already a direction on every mapping row, and the drivers set
     * these to inbound-only. It was not enough: the mapping is stored per
     * connection, and a save that flattened every row to "both" silently armed
     * a push that would have rewritten live orders — their totals, their
     * numbers, the dates they were placed.
     *
     * The damage is not symmetrical. Getting an inbound field wrong shows up
     * here as one bad row somebody can correct; getting an outbound one wrong
     * rewrites the shop's own record of a sale, which is the customer's receipt
     * and quite possibly an accounting document. A total is the shop's
     * arithmetic over its own line items and tax rules — we hold a copy of the
     * answer, not the authority for it.
     *
     * So this does not depend on configuration and cannot be switched off by
     * one. Status and fulfilment are deliberately absent: telling the shop an
     * order is now dispatched is the whole point of pushing.
     */
    public static function isPlatformOwned(string $entity, string $target): bool
    {
        return in_array($target, self::platformOwned()[$entity] ?? [], true);
    }

    /** @return array<string, list<string>> */
    private static function platformOwned(): array
    {
        return [
            'order' => [
                /*
                 * Identity and provenance. The shop issued the number and
                 * recorded when the sale happened; both are its facts, and both
                 * are marked read-only in WooCommerce's own schema.
                 *
                 * `currency` is deliberately absent: Woo accepts it, so blocking
                 * it here would be this application being stricter than the
                 * platform for no reason anybody could act on.
                 */
                'number', 'ordered_on', 'channel', 'external_ref',

                /*
                 * Money. Every one of these is derived by the shop from its own
                 * line items, shipping rules and tax configuration, and every one
                 * is read-only in its schema — a total sent here is discarded.
                 *
                 * This is not a limit on what can be changed from this
                 * application. Changing what an order costs is done by changing
                 * what is on it: the lines are pushed, and the shop recomputes
                 * these from them. See LineItems::toPlatform.
                 */
                'subtotal_minor', 'discount_minor', 'shipping_minor',
                'tax_minor', 'total_minor', 'paid_minor',
            ],

            // A product's slug is its public URL. Rewriting it breaks every link
            // to that page that exists anywhere.
            'product' => ['slug'],

            'customer' => [],
        ];
    }

    /**
     * May a mapping write here?
     *
     * Custom targets always may: they land on the link row rather than on the
     * record, so there is no column to collide with and nothing to escalate to.
     */
    public static function isWritable(string $entity, string $target): bool
    {
        if (self::isCustom($target)) {
            return self::customKey($target) !== '';
        }

        return isset(self::map()[$entity][$target]);
    }

    public static function isCustom(string $target): bool
    {
        return str_starts_with($target, self::CUSTOM_PREFIX);
    }

    /**
     * The key a custom value is stored under, cleaned.
     *
     * Restricted to plain characters because these keys become object keys in
     * JSON and labels on a screen. Anything else is stripped rather than
     * refused, so a shop's `_wc-delivery/slot` still lands somewhere sensible.
     */
    public static function customKey(string $target): string
    {
        $key = mb_substr($target, mb_strlen(self::CUSTOM_PREFIX));

        return trim((string) preg_replace('/[^A-Za-z0-9_.\- ]/', '', $key));
    }

    /** The transform that is right for this target unless somebody says otherwise. */
    public static function defaultTransform(string $entity, string $target): string
    {
        return self::map()[$entity][$target][1] ?? 'trim';
    }

    public static function label(string $entity, string $target): string
    {
        if (self::isCustom($target)) {
            return ucfirst(str_replace(['_', '-', '.'], ' ', self::customKey($target)));
        }

        return self::map()[$entity][$target][0] ?? $target;
    }

    /**
     * The target dropdown for one entity.
     *
     * @return list<array{value: string, label: string, transform: string}>
     */
    public static function options(string $entity): array
    {
        $options = [];

        foreach (self::map()[$entity] ?? [] as $target => [$label, $transform]) {
            $options[] = ['value' => $target, 'label' => $label, 'transform' => $transform];
        }

        return $options;
    }
}
