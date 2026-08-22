<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Support;

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
        $rows = match (strtolower($provider)) {
            'woocommerce' => self::woocommerce()[$entity] ?? [],
            'shopify' => self::shopify()[$entity] ?? [],
            'webflow' => self::webflow()[$entity] ?? [],
            default => [],
        };

        return array_map(fn (array $row): FieldMap => FieldMap::fromArray($row, $entity), $rows);
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
                self::row('price', 'variant.price_minor'),
                self::row('regular_price', 'variant.compare_at_minor'),
                self::row('weight', 'variant.weight_grams'),

                // Images, per shop, on the link — two shops list the same
                // product with different photography.
                self::row('images', 'custom.image'),
                self::row('images', 'custom.gallery'),
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

                self::row('images', 'custom.image'),
                self::row('images', 'custom.gallery'),
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
