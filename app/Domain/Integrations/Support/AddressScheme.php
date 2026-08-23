<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Support;

/**
 * Which set of place codes a platform speaks.
 *
 * ── Why this is not one list ─────────────────────────────────────────────────
 *
 * Because the same district has a different name in each platform's vocabulary,
 * and reading one shop's codes with another shop's list gives silence or, worse,
 * the wrong place.
 *
 *   WooCommerce   BD-58        prefixed, for 23 countries
 *   WooCommerce   CA           bare, for the other 46 — this is California
 *   Shopify       ON           bare province code, plus the name alongside
 *   Webflow       Ontario      the name, no code at all
 *
 * WooCommerce is not even consistent with itself: 23 of the 69 countries it
 * lists use `CC-NN`, and the remaining 46 use bare codes. That is not an error
 * in the data — it is what the shop sends, and matching it is the whole point.
 *
 * ── The one part that is shared ──────────────────────────────────────────────
 *
 * Countries. Every one of these platforms uses ISO alpha-2, so `BD` is `BD`
 * everywhere and there is nothing to split. The divergence begins exactly one
 * level down, which is where this splits with it.
 *
 * ── And the level below that ─────────────────────────────────────────────────
 *
 * Areas belong to no platform at all. A thana, an upazila, a barangay, a ward —
 * wherever one exists, a shop owner added a plugin that invented its own codes.
 * So areas are keyed by country rather than by platform, and a second business
 * with a different plugin gets a different file rather than a different scheme.
 */
final class AddressScheme
{
    /** The scheme used when a platform has no list of its own. */
    public const FALLBACK = 'woocommerce';

    /**
     * Which folder under resources/geo/subdivisions/ holds a platform's codes.
     *
     * ── Why most of these point at one folder ────────────────────────────────
     *
     * Not laziness, and not a claim that these platforms are identical. Sub-
     * division codes are largely shared vocabulary — ISO 3166-2 is where nearly
     * everyone got them, so Shopify's `ON` and WooCommerce's `ON` are the same
     * Ontario, and reading one with the other's list gives the right answer.
     *
     * Where a platform genuinely differs, the difference is usually that it
     * sends a name rather than a code, and Geography handles that by matching on
     * the name — which needs no second list at all.
     *
     * A platform that turns out to need its own list gets a folder here and a
     * line in this map, and nothing else changes.
     *
     * @var array<string, string>
     */
    private const SCHEMES = [
        'woocommerce' => 'woocommerce',

        /*
         * Shopify sends province_code alongside province, so a code that misses
         * still resolves by name. Pointed at the shared list because the codes
         * it does send are the same ISO ones.
         */
        'shopify' => 'woocommerce',

        // Webflow has no geography endpoint and sends names. Nothing here will
        // match a code, and nothing needs to — the name path handles it.
        'webflow' => 'woocommerce',
    ];

    /** The subdivision list a connection's platform should be read with. */
    public static function for(?string $provider): string
    {
        $key = mb_strtolower(trim((string) $provider));

        return self::SCHEMES[$key] ?? self::FALLBACK;
    }

    /**
     * Does this platform's own list exist, or is it borrowing the fallback?
     *
     * Worth being able to say out loud. A mapping screen that shows districts
     * for a Webflow site is showing WooCommerce's list, and somebody comparing
     * it against their site deserves to know that rather than conclude the
     * application is confused.
     */
    public static function isOwn(?string $provider): bool
    {
        $key = mb_strtolower(trim((string) $provider));

        return isset(self::SCHEMES[$key]) && self::SCHEMES[$key] === $key;
    }
}
