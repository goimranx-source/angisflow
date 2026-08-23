<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Support;

use App\Domain\Integrations\PlatformPresets;
/**
 * What each platform's standard fields mean, without anybody having to say.
 *
 * ── Why defaults exist at all ────────────────────────────────────────────────
 *
 * The standard fields of a standard platform are not a mystery. WooCommerce
 * has called its order total `total` for a decade and its billing phone
 * `billing.phone` for as long. Presenting somebody who has just connected their
 * shop with an empty mapping table and twenty rows to fill in is asking them to
 * tell us something we already know, before they can have the thing they came
 * for.
 *
 * So a connection works the moment it is made, and the mapping screen becomes
 * what it should be: the place to handle the part that genuinely cannot be
 * guessed — the custom fields somebody's own plugin or theme added.
 *
 * ── The generic driver has none ──────────────────────────────────────────────
 *
 * Deliberately. A bespoke site's payload is whatever its author decided, and a
 * guess there would be a wrong guess presented as a working configuration. That
 * connection's mapping is built by fetching one real record and picking from
 * the paths actually in it — see FieldPath::flatten.
 */
final class DefaultFieldMaps
{
    /**
     * @return list<FieldMap>
     */
    public static function for(string $provider, string $entity): array
    {
        $key = strtolower($provider);

        $rows = match ($key) {
            'woocommerce' => self::woocommerce()[$entity] ?? [],
            'shopify' => self::shopify()[$entity] ?? [],
            'webflow' => self::webflow()[$entity] ?? [],

            /*
             * A catalogued platform inherits the family its API resembles.
             *
             * ── Why a guess beats nothing ────────────────────────────────────
             *
             * The alternative for a platform without its own defaults is an
             * empty mapping screen: thirty-six fields, none of them filled in,
             * and somebody reading their shop's API documentation to complete
             * a form this application could have half-completed for them.
             *
             * Storefront APIs descend from a small number of conventions, and
             * most of the names — id, status, currency, total, line_items,
             * billing.email — are the same across them because they were
             * copied from each other. Starting from the nearest family puts
             * those in front of somebody to correct rather than to compose.
             *
             * It is a starting point and the mapping screen is where it is
             * corrected; nothing here is claimed to have been verified against
             * that platform.
             */
            default => match (PlatformPresets::familyFor($key)) {
                PlatformPresets::FAMILY_WOO => self::woocommerce()[$entity] ?? [],
                PlatformPresets::FAMILY_SHOPIFY => self::shopify()[$entity] ?? [],
                default => self::flat()[$entity] ?? [],
            },
        };

        return array_map(fn (array $row): FieldMap => FieldMap::fromArray($row, $entity), $rows);
    }

    /**
     * The names a plain JSON commerce API most often uses.
     *
     * ── Where these come from ────────────────────────────────────────────────
     *
     * Not from one platform. These are the field names that recur across
     * BigCommerce, Magento, Medusa, Square and most of the rest: a flat object
     * with an id and a status, money as decimal strings, the buyer under
     * billing or customer, and the items under line_items or items.
     *
     * Every one of them is a starting point to be corrected on the mapping
     * screen, which is why they are offered rather than applied silently.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private static function flat(): array
    {
        return [
            'order' => [
                self::row('id', 'external_ref'),
                self::row('order_number', 'number'),
                self::row('number', 'number'),
                self::row('created_at', 'ordered_on'),
                self::row('status', 'status'),
                self::row('financial_status', 'payment_status'),
                self::row('currency', 'currency'),
                self::row('currency_code', 'currency'),

                // Money in, only: totals are the shop's to compute from its own
                // lines, and pushing ours back is discarded by most of them.
                self::row('subtotal', 'subtotal_minor', FieldMap::IN),
                self::row('discount_total', 'discount_minor', FieldMap::IN),
                self::row('shipping_total', 'shipping_minor', FieldMap::IN),
                self::row('tax_total', 'tax_minor', FieldMap::IN),
                self::row('total', 'total_minor', FieldMap::IN),
                self::row('total_paid', 'paid_minor', FieldMap::IN),

                self::row('customer.email', 'customer.email'),
                self::row('customer.first_name', 'customer.first_name'),
                self::row('customer.last_name', 'customer.last_name'),
                self::row('customer.phone', 'customer.phone'),

                self::row('billing.first_name', 'customer.first_name'),
                self::row('billing.email', 'customer.email'),
                self::row('billing.phone', 'customer.phone'),
                self::row('billing.address_1', 'customer.billing_address'),
                self::row('billing.city', 'customer.billing_city'),
                self::row('billing.postcode', 'customer.billing_postcode'),
                self::row('billing.country', 'customer.billing_country'),

                self::row('shipping.first_name', 'shipping_name'),
                self::row('shipping.phone', 'shipping_phone'),
                self::row('shipping.address_1', 'shipping_address'),
                self::row('shipping.city', 'shipping_city'),
                self::row('shipping.postcode', 'shipping_postcode'),
                self::row('shipping.country', 'shipping_country'),

                self::row('note', 'notes'),
                self::row('customer_note', 'notes'),
            ],

            'product' => [
                self::row('id', 'external_ref'),
                self::row('name', 'name'),
                self::row('title', 'name'),
                self::row('slug', 'slug'),
                self::row('handle', 'slug'),
                self::row('description', 'description'),
                self::row('sku', 'variant.sku'),
                self::row('price', 'variant.price_minor'),
                self::row('images.0.src', 'image'),
            ],

            'customer' => [
                self::row('id', 'external_ref'),
                self::row('email', 'email'),
                self::row('first_name', 'first_name'),
                self::row('last_name', 'last_name'),
                self::row('phone', 'phone'),
            ],
        ];
    }

    /** Is there anything to start from for this platform? */
    public static function exist(string $provider, string $entity): bool
    {
        return self::for($provider, $entity) !== [];
    }

    /**
     * Shorthand for the rows below.
     *
     * The transform is left to EntityFields unless a platform needs a different
     * one, so the money fields, dates and booleans get the right handling from
     * one declaration rather than twenty repetitions.
     *
     * @param  list<string>  $also
     * @return array<string, mixed>
     */
    private static function row(string $source, string $target, string $direction = FieldMap::BOTH, array $also = []): array
    {
        return ['source' => $source, 'target' => $target, 'direction' => $direction, 'also' => $also];
    }

    /**
     * WooCommerce.
     *
     * Money arrives as decimal strings — "19.99" — and the totals are
     * calculated by the shop from its own line items and tax rules. Marked
     * inbound only: pushing our figure over theirs would have this application
     * arguing with the thing that actually took the payment.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private static function woocommerce(): array
    {
        return [
            'order' => [
                self::row('number', 'number', FieldMap::IN),
                self::row('date_created_gmt', 'ordered_on', FieldMap::IN),
                self::row('currency', 'currency', FieldMap::IN),

                self::row('total', 'total_minor', FieldMap::IN),
                self::row('total_tax', 'tax_minor', FieldMap::IN),
                self::row('shipping_total', 'shipping_minor', FieldMap::IN),
                self::row('discount_total', 'discount_minor', FieldMap::IN),

                // Status is absent on purpose. Woo says 'processing'; this
                // application says 'confirmed'. That correspondence is a
                // judgement about what the words mean in a set of books, not a
                // field to copy across — see StatusVocabulary, which the sync
                // applies separately. Mapping it here as text would write
                // 'processing' into a column whose vocabulary has no such value.
                self::row('customer_note', 'notes'),

                // Woo keeps names in two fields; we keep one. Composite, and so
                // inbound only — see FieldMap::writesToPlatform.
                self::row('shipping.first_name', 'shipping_name', FieldMap::IN, ['shipping.last_name']),
                self::row('billing.phone', 'shipping_phone'),
                self::row('shipping.address_1', 'shipping_address'),
                self::row('shipping.city', 'shipping_city'),
                self::row('shipping.postcode', 'shipping_postcode'),
                self::row('shipping.country', 'shipping_country'),
            ],

            'customer' => [
                self::row('billing.first_name', 'name', FieldMap::IN, ['billing.last_name']),

                /*
                 * billing.email rather than the top-level `email`, and that is
                 * not an arbitrary preference.
                 *
                 * This same map is run over an *order* payload to work out who
                 * placed it — which is how orders can be imported without first
                 * importing the customer list. A Woo order has no top-level
                 * email; it keeps the buyer's address under `billing`. Reading
                 * the top-level field would create every order's customer with a
                 * phone and no email, and email is what the linker matches on —
                 * so the same buyer would be created afresh on every order.
                 *
                 * Woo fills billing.email on its customer records too, so one
                 * path serves both.
                 */
                self::row('billing.email', 'email'),
                self::row('billing.phone', 'phone'),
                self::row('billing.company', 'company'),
                self::row('billing.address_1', 'billing_address'),
                self::row('billing.city', 'billing_city'),
                self::row('billing.postcode', 'billing_postcode'),
                self::row('billing.country', 'billing_country'),
            ],

            'product' => [
                self::row('name', 'name'),
                self::row('slug', 'slug'),

                // Kept as formatted copy rather than flattened — a catalogue
                // description is layout, and stripping it loses the whole shop's.
                self::row('description', 'description'),
                self::row('short_description', 'summary'),

                // A real boolean, unlike Woo's product `status` — which is
                // 'publish' or 'draft' and so is left to StatusVocabulary.
                self::row('manage_stock', 'is_stocked'),

                /*
                 * What is actually sold.
                 *
                 * These are knowable for WooCommerce — it has called them the
                 * same thing for a decade — so they are mapped by default
                 * rather than left for somebody to wire up by hand. Only the
                 * fields a shop's own plugins added cannot be guessed.
                 */
                self::row('sku', 'variant.sku'),
                /*
                 * ── Which of WooCommerce's three prices is the real one ──────
                 *
                 * `price` is read-only. WooCommerce computes it from the other
                 * two — regular_price normally, sale_price while a sale is on —
                 * and silently discards anything written to it. Mapped both
                 * ways, as it was, a price changed here was sent, accepted with
                 * a 200, and ignored: the shop kept the old figure and nothing
                 * anywhere said why.
                 *
                 * So it is read only, and `regular_price` — the writable one —
                 * carries our price in both directions.
                 *
                 * compare_at_minor is our "was" price and belongs against
                 * sale_price's counterpart, not against regular_price; mapping
                 * it there made a discount overwrite the normal price.
                 */
                self::row('regular_price', 'variant.price_minor'),
                self::row('sale_price', 'variant.compare_at_minor', FieldMap::IN),
                self::row('weight', 'variant.weight_grams'),

                /*
                 * Images, per shop, on the link — two shops list the same
                 * product with different photography.
                 *
                 * Brought in only, by default. A shop's media library is its
                 * own: sending the URLs back makes it fetch and store the same
                 * pictures again as fresh attachments, so a catalogue quietly
                 * grows a duplicate of every image each time a product is
                 * saved here.
                 *
                 * Anybody who genuinely wants to publish photography from this
                 * side can set these to Both ways on the mapping screen, and
                 * the transform now sends the shape a shop expects rather than
                 * the joined text we store.
                 */
                self::row('images', 'custom.image', FieldMap::IN),
                self::row('images', 'custom.gallery', FieldMap::IN),
            ],
        ];
    }

    /**
     * Shopify.
     *
     * Its order name carries a leading hash — '#1001' — which EntityFields
     * strips, because a hash in a reference breaks every URL it later appears
     * in. Custom fields live in `note_attributes` and `metafields`, both of
     * which FieldPath addresses by name rather than by index.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private static function shopify(): array
    {
        return [
            'order' => [
                self::row('name', 'number', FieldMap::IN),
                self::row('created_at', 'ordered_on', FieldMap::IN),
                self::row('currency', 'currency', FieldMap::IN),

                self::row('total_price', 'total_minor', FieldMap::IN),
                self::row('total_tax', 'tax_minor', FieldMap::IN),
                self::row('total_discounts', 'discount_minor', FieldMap::IN),

                // financial_status and fulfillment_status are vocabulary, not
                // data — StatusVocabulary translates them.
                self::row('note', 'notes'),

                self::row('shipping_address.first_name', 'shipping_name', FieldMap::IN, ['shipping_address.last_name']),
                self::row('shipping_address.phone', 'shipping_phone'),
                self::row('shipping_address.address1', 'shipping_address'),
                self::row('shipping_address.city', 'shipping_city'),
                self::row('shipping_address.zip', 'shipping_postcode'),
                self::row('shipping_address.country_code', 'shipping_country'),
            ],

            'customer' => [
                self::row('first_name', 'name', FieldMap::IN, ['last_name']),
                self::row('email', 'email'),
                self::row('phone', 'phone'),
                self::row('default_address.company', 'company'),
                self::row('default_address.address1', 'billing_address'),
                self::row('default_address.city', 'billing_city'),
                self::row('default_address.zip', 'billing_postcode'),
                self::row('default_address.country_code', 'billing_country'),
            ],

            'product' => [
                self::row('title', 'name'),
                self::row('handle', 'slug'),
                self::row('body_html', 'description'),
                self::row('vendor', 'brand'),

                // Shopify keeps the sellable facts on the first variant.
                self::row('variants.0.sku', 'variant.sku'),
                self::row('variants.0.price', 'variant.price_minor'),
                self::row('variants.0.compare_at_price', 'variant.compare_at_minor'),
                self::row('variants.0.barcode', 'variant.barcode'),

                // Brought in only, for the reason given on the WooCommerce
                // block above: a shop's media library is its own, and sending
                // the URLs back has it re-fetch every picture as a new
                // attachment.
                self::row('images', 'custom.image', FieldMap::IN),
                self::row('images', 'custom.gallery', FieldMap::IN),
            ],
        ];
    }

    /**
     * Webflow.
     *
     * Narrower than the others because its API is: no customer entity, and its
     * money arrives already in minor units under `value`, so those rows want no
     * money transform at all — hence the explicit 'integer'.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private static function webflow(): array
    {
        return [
            'order' => [
                self::row('orderId', 'number', FieldMap::IN),
                self::row('acceptedOn', 'ordered_on', FieldMap::IN),
                ['source' => 'orderTotal.unit', 'target' => 'currency', 'direction' => FieldMap::IN],
                ['source' => 'orderTotal.value', 'target' => 'total_minor', 'direction' => FieldMap::IN, 'transform' => 'integer'],
                ['source' => 'totalTax.value', 'target' => 'tax_minor', 'direction' => FieldMap::IN, 'transform' => 'integer'],
                self::row('customerInfo.fullName', 'shipping_name', FieldMap::IN),
                self::row('shippingAddress.line1', 'shipping_address'),
                self::row('shippingAddress.city', 'shipping_city'),
                self::row('shippingAddress.postalCode', 'shipping_postcode'),
                self::row('shippingAddress.country', 'shipping_country'),
            ],

            'product' => [
                self::row('fieldData.name', 'name'),
                self::row('fieldData.description', 'description'),
            ],
        ];
    }
}
