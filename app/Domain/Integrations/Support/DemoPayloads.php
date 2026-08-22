<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Support;

/**
 * A sample record per platform, so mapping can be set up before the first sale.
 *
 * ── Why a fake record is the right answer here ───────────────────────────────
 *
 * Field mapping needs a record to read paths from. A shop connected five minutes
 * ago has not sent one yet, so the honest options are: show an empty screen and
 * tell somebody to come back after their first order, or show the shape that
 * platform is documented to send.
 *
 * The first is what makes people abandon setup. Somebody connects their shop on
 * a Tuesday afternoon because that is when they have half an hour, and telling
 * them the next step waits on a customer means the next step never happens.
 *
 * So the standard fields are offered from a documented sample, and the screen
 * says plainly that is where they came from. The mapping made against it is
 * real — `billing.phone` is `billing.phone` whether the record is live or not.
 *
 * ── What a sample cannot give ────────────────────────────────────────────────
 *
 * The custom fields. Those are whatever this particular shop's plugins added,
 * and no sample anywhere contains them — which is exactly why PullSync keeps a
 * real record the moment one arrives, and why this is a starting point rather
 * than a substitute.
 *
 * The generic REST driver gets nothing at all. A bespoke site's payload is
 * whatever its author decided, and a guess presented as a working sample would
 * have somebody mapping fields that do not exist.
 */
final class DemoPayloads
{
    /**
     * @return array<string, mixed>|null null when nothing can honestly be offered
     */
    public static function for(string $provider, string $entity = 'order'): ?array
    {
        $payloads = match (strtolower($provider)) {
            'woocommerce' => self::woocommerce(),
            'shopify' => self::shopify(),
            'webflow' => self::webflow(),
            default => [],
        };

        return $payloads[$entity] ?? null;
    }

    public static function exist(string $provider, string $entity = 'order'): bool
    {
        return self::for($provider, $entity) !== null;
    }

    /**
     * WooCommerce.
     *
     * Includes a `meta_data` pair not because we know this shop's fields, but so
     * the screen demonstrates that custom fields are addressable by name — the
     * one thing about this mapping people do not expect to work.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function woocommerce(): array
    {
        return [
            'order' => [
                'id' => 1042,
                'number' => '1042',
                'status' => 'processing',
                'currency' => 'USD',
                'date_created' => '2026-01-15T09:31:05',
                'date_created_gmt' => '2026-01-15T09:31:05',
                'date_modified_gmt' => '2026-01-15T11:02:44',
                'total' => '129.50',
                'total_tax' => '9.50',
                'shipping_total' => '10.00',
                'discount_total' => '0.00',
                'payment_method' => 'cod',
                'payment_method_title' => 'Cash on delivery',
                'customer_note' => 'Please ring the bell twice.',
                'billing' => [
                    'first_name' => 'Ada',
                    'last_name' => 'Lovelace',
                    'company' => 'Analytical Ltd',
                    'address_1' => '12 Marylebone Road',
                    'city' => 'London',
                    'postcode' => 'NW1 5LA',
                    'country' => 'GB',
                    'email' => 'ada@example.com',
                    'phone' => '+44 20 7000 0000',
                ],
                'shipping' => [
                    'first_name' => 'Ada',
                    'last_name' => 'Lovelace',
                    'address_1' => '12 Marylebone Road',
                    'city' => 'London',
                    'postcode' => 'NW1 5LA',
                    'country' => 'GB',
                ],
                'meta_data' => [
                    ['id' => 9, 'key' => '_delivery_slot', 'value' => 'Morning 9-12'],
                ],
                'line_items' => [
                    [
                        'id' => 1,
                        'name' => 'Black T-Shirt — Medium',
                        'sku' => 'TS-BLK-M',
                        'quantity' => 2,
                        'price' => '45.00',
                        'total' => '90.00',
                    ],
                ],
            ],

            'product' => [
                'id' => 501,
                'name' => 'Black T-Shirt',
                'sku' => 'TS-BLK-M',
                'status' => 'publish',
                'description' => '<p>Heavyweight cotton tee.</p>',
                'short_description' => '<p>Heavyweight cotton.</p>',
                'price' => '45.00',
                'regular_price' => '45.00',
                'manage_stock' => true,
                'stock_quantity' => 24,
                'weight' => '0.2',
                'categories' => [['id' => 12, 'name' => 'Apparel', 'slug' => 'apparel']],
            ],

            'customer' => [
                'id' => 88,
                'email' => 'ada@example.com',
                'first_name' => 'Ada',
                'last_name' => 'Lovelace',
                'username' => 'ada',
                'billing' => [
                    'first_name' => 'Ada',
                    'last_name' => 'Lovelace',
                    'company' => 'Analytical Ltd',
                    'address_1' => '12 Marylebone Road',
                    'city' => 'London',
                    'postcode' => 'NW1 5LA',
                    'country' => 'GB',
                    'email' => 'ada@example.com',
                    'phone' => '+44 20 7000 0000',
                ],
            ],
        ];
    }

    /**
     * Shopify.
     *
     * Its order name carries the leading hash the mapping strips, and its custom
     * fields sit in `note_attributes` — a differently named pair list, which the
     * resolver addresses the same way.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function shopify(): array
    {
        return [
            'order' => [
                'id' => 4501234567890,
                'name' => '#1001',
                'order_number' => 1001,
                'created_at' => '2026-01-15T09:31:05-05:00',
                'updated_at' => '2026-01-15T11:02:44-05:00',
                'currency' => 'USD',
                'total_price' => '129.50',
                'total_tax' => '9.50',
                'total_discounts' => '0.00',
                'financial_status' => 'paid',
                'fulfillment_status' => null,
                'note' => 'Gate code 1234.',
                'email' => 'grace@example.com',
                'customer' => [
                    'id' => 7788,
                    'first_name' => 'Grace',
                    'last_name' => 'Hopper',
                    'email' => 'grace@example.com',
                    'phone' => '+1 555 0100',
                ],
                'shipping_address' => [
                    'first_name' => 'Grace',
                    'last_name' => 'Hopper',
                    'address1' => '1 Navy Yard',
                    'city' => 'Arlington',
                    'province' => 'Virginia',
                    'zip' => '22202',
                    'country_code' => 'US',
                    'phone' => '+1 555 0100',
                ],
                'note_attributes' => [
                    ['name' => 'delivery_slot', 'value' => 'Afternoon'],
                ],
                'line_items' => [
                    [
                        'id' => 111,
                        'title' => 'Black T-Shirt',
                        'variant_title' => 'Medium',
                        'sku' => 'TS-BLK-M',
                        'quantity' => 2,
                        'price' => '45.00',
                    ],
                ],
            ],

            'product' => [
                'id' => 9001,
                'title' => 'Black T-Shirt',
                'body_html' => '<p>Heavyweight cotton tee.</p>',
                'vendor' => 'Acme',
                'product_type' => 'Apparel',
                'status' => 'active',
                'variants' => [
                    ['id' => 555, 'title' => 'Medium', 'sku' => 'TS-BLK-M', 'price' => '45.00', 'inventory_quantity' => 24],
                ],
            ],

            'customer' => [
                'id' => 7788,
                'email' => 'grace@example.com',
                'first_name' => 'Grace',
                'last_name' => 'Hopper',
                'phone' => '+1 555 0100',
                'default_address' => [
                    'company' => 'US Navy',
                    'address1' => '1 Navy Yard',
                    'city' => 'Arlington',
                    'zip' => '22202',
                    'country_code' => 'US',
                ],
            ],
        ];
    }

    /**
     * Webflow.
     *
     * Its money arrives already in minor units under `value`, which is why its
     * mappings read those as integers rather than scaling them — visible here so
     * somebody setting it up can see the difference rather than be surprised by
     * a hundredfold error.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function webflow(): array
    {
        return [
            'order' => [
                'orderId' => 'a1b2c3',
                'status' => 'unfulfilled',
                'acceptedOn' => '2026-01-15T09:31:05.000Z',
                'orderTotal' => ['unit' => 'USD', 'value' => 12950, 'string' => '$129.50'],
                'totalTax' => ['unit' => 'USD', 'value' => 950, 'string' => '$9.50'],
                'customerInfo' => ['fullName' => 'Alan Turing', 'identifier' => 'alan@example.com'],
                'shippingAddress' => [
                    'line1' => '2 Wilmslow Road',
                    'city' => 'Manchester',
                    'postalCode' => 'M14 6HR',
                    'country' => 'GB',
                ],
                'purchasedItems' => [
                    [
                        'productName' => 'Black T-Shirt',
                        'variantSKU' => 'TS-BLK-M',
                        'count' => 2,
                        'variantPrice' => ['unit' => 'USD', 'value' => 4500],
                        'rowTotal' => ['unit' => 'USD', 'value' => 9000],
                    ],
                ],
            ],

            'product' => [
                'id' => 'p-9001',
                'fieldData' => [
                    'name' => 'Black T-Shirt',
                    'slug' => 'black-t-shirt',
                    'description' => 'Heavyweight cotton tee.',
                    'price' => ['unit' => 'USD', 'value' => 4500],
                ],
            ],
        ];
    }
}
