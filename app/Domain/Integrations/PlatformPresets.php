<?php

declare(strict_types=1);

namespace App\Domain\Integrations;

/**
 * The shops of the world, and what it takes to talk to each.
 *
 * ── Why a catalogue and not more drivers ─────────────────────────────────────
 *
 * Writing a driver per platform is the right answer for the handful a business
 * is most likely to run, and the wrong one for the long tail. There are dozens
 * of selling platforms in real use — regional ones with more local share than
 * the famous names — and shipping a hand-written client for each would mean
 * most of them never arrive, while a business that runs one of them is told its
 * shop does not exist.
 *
 * So a platform is data. It says what it is called, where it belongs in a list,
 * what credentials it needs, and which family of field shapes its API belongs
 * to. The generic REST driver does the talking. A platform that later earns a
 * driver of its own keeps its entry and gains `driver`.
 *
 * ── Why every entry declares how well it is supported ────────────────────────
 *
 * Because the alternative is a list where WooCommerce and a platform nobody has
 * tested look identical, and somebody picks the second on the strength of the
 * first. `support` is shown in the form:
 *
 *   built     a driver written and used against the real API
 *   preset    the right credentials and field shapes, over the generic client
 *   manual    listed so it can be found; expect to map fields by hand
 *
 * A guess presented as a certainty is worse than an honest gap.
 */
final class PlatformPresets
{
    /**
     * How a platform's API is shaped, for choosing default field maps.
     *
     * Most storefront APIs descend from a small number of conventions: the
     * WooCommerce/WordPress REST shape, Shopify's, or a flat one. Grouping by
     * family means a new platform inherits sensible defaults instead of
     * starting from an empty mapping screen.
     */
    public const FAMILY_WOO = 'woocommerce';

    public const FAMILY_SHOPIFY = 'shopify';

    public const FAMILY_FLAT = 'flat';

    /**
     * @return array<string, array{
     *     label: string,
     *     group: string,
     *     kinds: list<string>,
     *     family: string,
     *     support: string,
     *     driver?: string,
     *     auth?: string,
     *     help?: string
     * }>
     */
    public static function all(): array
    {
        return [
            // ── Written and used against the real API ────────────────────────

            'woocommerce' => [
                'label' => 'WooCommerce',
                'group' => 'Popular',
                'kinds' => ['store'],
                'family' => self::FAMILY_WOO,
                'support' => 'built',
            ],
            'shopify' => [
                'label' => 'Shopify',
                'group' => 'Popular',
                'kinds' => ['store'],
                'family' => self::FAMILY_SHOPIFY,
                'support' => 'built',
            ],
            'webflow' => [
                'label' => 'Webflow',
                'group' => 'Popular',
                'kinds' => ['store'],
                'family' => self::FAMILY_FLAT,
                'support' => 'built',
            ],

            // ── Hosted storefronts ───────────────────────────────────────────

            'bigcommerce' => [
                'label' => 'BigCommerce',
                'group' => 'Hosted storefronts',
                'kinds' => ['store'],
                'family' => self::FAMILY_FLAT,
                'support' => 'preset',
                'auth' => 'token',
                'help' => 'Store hash and an API account token.',
            ],
            'squarespace' => [
                'label' => 'Squarespace Commerce',
                'group' => 'Hosted storefronts',
                'kinds' => ['store'],
                'family' => self::FAMILY_FLAT,
                'support' => 'preset',
                'auth' => 'token',
            ],
            'wix' => [
                'label' => 'Wix Stores',
                'group' => 'Hosted storefronts',
                'kinds' => ['store'],
                'family' => self::FAMILY_FLAT,
                'support' => 'preset',
                'auth' => 'token',
            ],
            'ecwid' => [
                'label' => 'Ecwid by Lightspeed',
                'group' => 'Hosted storefronts',
                'kinds' => ['store'],
                'family' => self::FAMILY_FLAT,
                'support' => 'preset',
                'auth' => 'token',
            ],
            'bigcartel' => [
                'label' => 'Big Cartel',
                'group' => 'Hosted storefronts',
                'kinds' => ['store'],
                'family' => self::FAMILY_FLAT,
                'support' => 'manual',
                'auth' => 'basic',
            ],
            'shift4shop' => [
                'label' => 'Shift4Shop',
                'group' => 'Hosted storefronts',
                'kinds' => ['store'],
                'family' => self::FAMILY_FLAT,
                'support' => 'manual',
                'auth' => 'token',
            ],
            'shopbase' => [
                'label' => 'ShopBase',
                'group' => 'Hosted storefronts',
                'kinds' => ['store'],
                'family' => self::FAMILY_SHOPIFY,
                'support' => 'manual',
                'auth' => 'token',
            ],

            // ── Self-hosted and open source ──────────────────────────────────

            'magento' => [
                'label' => 'Magento / Adobe Commerce',
                'group' => 'Self-hosted',
                'kinds' => ['store'],
                'family' => self::FAMILY_FLAT,
                'support' => 'preset',
                'auth' => 'token',
                'help' => 'A bearer token from an integration in the admin.',
            ],
            'prestashop' => [
                'label' => 'PrestaShop',
                'group' => 'Self-hosted',
                'kinds' => ['store'],
                'family' => self::FAMILY_FLAT,
                'support' => 'preset',
                'auth' => 'basic',
                'help' => 'The webservice key goes in the username, password blank.',
            ],
            'opencart' => [
                'label' => 'OpenCart',
                'group' => 'Self-hosted',
                'kinds' => ['store'],
                'family' => self::FAMILY_FLAT,
                'support' => 'manual',
                'auth' => 'token',
            ],
            'shopware' => [
                'label' => 'Shopware',
                'group' => 'Self-hosted',
                'kinds' => ['store'],
                'family' => self::FAMILY_FLAT,
                'support' => 'preset',
                'auth' => 'oauth_client',
            ],
            'prestashop_addons' => [
                'label' => 'nopCommerce',
                'group' => 'Self-hosted',
                'kinds' => ['store'],
                'family' => self::FAMILY_FLAT,
                'support' => 'manual',
                'auth' => 'token',
            ],
            'drupal_commerce' => [
                'label' => 'Drupal Commerce',
                'group' => 'Self-hosted',
                'kinds' => ['store'],
                'family' => self::FAMILY_FLAT,
                'support' => 'manual',
                'auth' => 'token',
            ],

            // ── Headless and API-first ───────────────────────────────────────

            'medusa' => [
                'label' => 'Medusa',
                'group' => 'Headless',
                'kinds' => ['store'],
                'family' => self::FAMILY_FLAT,
                'support' => 'preset',
                'auth' => 'token',
            ],
            'saleor' => [
                'label' => 'Saleor',
                'group' => 'Headless',
                'kinds' => ['store'],
                'family' => self::FAMILY_FLAT,
                'support' => 'manual',
                'auth' => 'token',
            ],
            'commercetools' => [
                'label' => 'commercetools',
                'group' => 'Headless',
                'kinds' => ['store'],
                'family' => self::FAMILY_FLAT,
                'support' => 'manual',
                'auth' => 'oauth_client',
            ],
            'swell' => [
                'label' => 'Swell',
                'group' => 'Headless',
                'kinds' => ['store'],
                'family' => self::FAMILY_FLAT,
                'support' => 'preset',
                'auth' => 'token',
            ],
            'vendure' => [
                'label' => 'Vendure',
                'group' => 'Headless',
                'kinds' => ['store'],
                'family' => self::FAMILY_FLAT,
                'support' => 'manual',
                'auth' => 'token',
            ],

            // ── Marketplaces ─────────────────────────────────────────────────
            //
            // Orders arrive from these; the catalogue usually lives elsewhere.

            'amazon' => [
                'label' => 'Amazon (Selling Partner)',
                'group' => 'Marketplaces',
                'kinds' => ['store'],
                'family' => self::FAMILY_FLAT,
                'support' => 'manual',
                'auth' => 'oauth_client',
            ],
            'ebay' => [
                'label' => 'eBay',
                'group' => 'Marketplaces',
                'kinds' => ['store'],
                'family' => self::FAMILY_FLAT,
                'support' => 'manual',
                'auth' => 'oauth_client',
            ],
            'etsy' => [
                'label' => 'Etsy',
                'group' => 'Marketplaces',
                'kinds' => ['store'],
                'family' => self::FAMILY_FLAT,
                'support' => 'manual',
                'auth' => 'oauth_client',
            ],
            'daraz' => [
                'label' => 'Daraz',
                'group' => 'Marketplaces',
                'kinds' => ['store'],
                'family' => self::FAMILY_FLAT,
                'support' => 'manual',
                'auth' => 'oauth_client',
            ],
            'lazada' => [
                'label' => 'Lazada',
                'group' => 'Marketplaces',
                'kinds' => ['store'],
                'family' => self::FAMILY_FLAT,
                'support' => 'manual',
                'auth' => 'oauth_client',
            ],
            'shopee' => [
                'label' => 'Shopee',
                'group' => 'Marketplaces',
                'kinds' => ['store'],
                'family' => self::FAMILY_FLAT,
                'support' => 'manual',
                'auth' => 'oauth_client',
            ],
            'noon' => [
                'label' => 'noon',
                'group' => 'Marketplaces',
                'kinds' => ['store'],
                'family' => self::FAMILY_FLAT,
                'support' => 'manual',
                'auth' => 'token',
            ],
            'trendyol' => [
                'label' => 'Trendyol',
                'group' => 'Marketplaces',
                'kinds' => ['store'],
                'family' => self::FAMILY_FLAT,
                'support' => 'manual',
                'auth' => 'basic',
            ],

            // ── Regional ─────────────────────────────────────────────────────

            'salla' => [
                'label' => 'Salla',
                'group' => 'Regional',
                'kinds' => ['store'],
                'family' => self::FAMILY_FLAT,
                'support' => 'manual',
                'auth' => 'token',
            ],
            'zid' => [
                'label' => 'Zid',
                'group' => 'Regional',
                'kinds' => ['store'],
                'family' => self::FAMILY_FLAT,
                'support' => 'manual',
                'auth' => 'token',
            ],
            'jumia' => [
                'label' => 'Jumia',
                'group' => 'Regional',
                'kinds' => ['store'],
                'family' => self::FAMILY_FLAT,
                'support' => 'manual',
                'auth' => 'token',
            ],

            // ── Point of sale ────────────────────────────────────────────────

            'square' => [
                'label' => 'Square',
                'group' => 'Point of sale',
                'kinds' => ['store'],
                'family' => self::FAMILY_FLAT,
                'support' => 'preset',
                'auth' => 'token',
            ],
            'lightspeed' => [
                'label' => 'Lightspeed Retail',
                'group' => 'Point of sale',
                'kinds' => ['store'],
                'family' => self::FAMILY_FLAT,
                'support' => 'manual',
                'auth' => 'oauth_client',
            ],
            'clover' => [
                'label' => 'Clover',
                'group' => 'Point of sale',
                'kinds' => ['store'],
                'family' => self::FAMILY_FLAT,
                'support' => 'manual',
                'auth' => 'token',
            ],
        ];
    }

    /** @return array{label: string, group: string, kinds: list<string>, family: string, support: string, driver?: string, auth?: string, help?: string}|null */
    public static function find(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /**
     * Which field-map family a platform's API belongs to.
     *
     * Falls back to flat rather than to nothing: an unknown platform with no
     * defaults leaves somebody staring at an empty mapping screen, and a flat
     * guess at least puts the common names in front of them to correct.
     */
    public static function familyFor(string $key): string
    {
        return self::all()[$key]['family'] ?? self::FAMILY_FLAT;
    }

    /** The order groups appear in, most likely first. */
    public static function groups(): array
    {
        return [
            'Popular',
            'Hosted storefronts',
            'Self-hosted',
            'Headless',
            'Marketplaces',
            'Regional',
            'Point of sale',
            'Anything else',
        ];
    }
}
