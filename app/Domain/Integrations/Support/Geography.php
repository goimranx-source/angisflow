<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Support;

/**
 * Places, by the codes shops store them under.
 *
 * ── The problem ──────────────────────────────────────────────────────────────
 *
 * An order arrives saying its delivery address is `BD-58-05`. That is not an
 * address anybody can read, act on, or check — it is three numbers, and the only
 * way to learn that it means Satkhira Sadar is to look it up somewhere.
 *
 * Shops store codes because codes are stable: a district can be renamed and its
 * code will not change, and two shops using the same code mean the same place
 * even if one spells it Satkhira and the other Shatkhira. That is the right way
 * to store it and the wrong way to show it.
 *
 * ── Why the lists live in files rather than being fetched ────────────────────
 *
 * WooCommerce will serve its country list on request, and doing so for every
 * screen that shows an address would be one network round trip per page to
 * somebody else's shop, to learn something that changes when a country does.
 *
 * Held here, they work for a shop that is offline, for a platform that has no
 * such endpoint at all — Webflow has none, Shopify lists only the countries a
 * shop happens to ship to — and for an order read out of the database long
 * after the connection it came through was deleted.
 *
 * ── The three levels ─────────────────────────────────────────────────────────
 *
 * Country, then sub-division, then area:
 *
 *   BD           Bangladesh          from WooCommerce, 250 of them
 *   BD-58        Satkhira            from WooCommerce, 2,040 across 69 countries
 *   BD-58-05     Satkhira Sadar      from the shop's own plugin, 581 for Bangladesh
 *
 * The first two are WooCommerce's own lists, taken from a live shop so they
 * match exactly what arrives on an order. The third is not WooCommerce's at all
 * — no platform models a third level, and this one comes from the address plugin
 * this business wrote. Which is precisely why it is kept separately: a different
 * shop with a different plugin has a different third level, or none.
 */
final class Geography
{
    /** @var array<string, string>|null */
    private static ?array $countries = null;

    /** @var array<string, array<string, string>> */
    private static array $states = [];

    /** @var array<string, array<string, array<string, string>>> */
    private static array $areas = [];

    // ── Names ───────────────────────────────────────────────────────────────

    /** 'BD' → 'Bangladesh', or null when it is not a code this knows. */
    public static function countryName(string $code): ?string
    {
        return self::countries()[self::clean($code)] ?? null;
    }

    /**
     * 'BD-58' → 'Satkhira'.
     *
     * The country is worked out from the code itself when it is not given,
     * because a state code carries its country as a prefix and an order that
     * names a state without a country is common — this shop's own orders do
     * exactly that.
     */
    public static function stateName(string $code, ?string $country = null): ?string
    {
        $code = self::clean($code);
        $country = self::countryFor($code, $country);

        return $country === null ? null : (self::states($country)[$code] ?? null);
    }

    /** 'BD-58-05' → 'Satkhira Sadar'. */
    public static function areaName(string $code, ?string $country = null): ?string
    {
        $code = self::clean($code);
        $country = self::countryFor($code, $country);

        if ($country === null) {
            return null;
        }

        foreach (self::areas($country) as $inState) {
            if (isset($inState[$code])) {
                return $inState[$code];
            }
        }

        return null;
    }

    /**
     * A code of any of the three levels, read as a name.
     *
     * Which level it is can be told from the code: BD, BD-58, BD-58-05. So a
     * screen showing "whatever this address field holds" does not have to know
     * which of the three it was handed.
     */
    public static function name(string $code, ?string $country = null): ?string
    {
        return match (substr_count(self::clean($code), '-')) {
            0 => self::countryName($code),
            1 => self::stateName($code, $country),
            default => self::areaName($code, $country),
        };
    }

    // ── Lists, for a dropdown ───────────────────────────────────────────────

    /** @return list<array{label: string, value: string}> */
    public static function countryOptions(): array
    {
        return self::asOptions(self::countries());
    }

    /** @return list<array{label: string, value: string}> */
    public static function stateOptions(string $country): array
    {
        return self::asOptions(self::states(self::clean($country)));
    }

    /**
     * The areas within one sub-division — Satkhira's seven thanas, not all 581.
     *
     * @return list<array{label: string, value: string}>
     */
    public static function areaOptions(string $state, ?string $country = null): array
    {
        $state = self::clean($state);
        $country = self::countryFor($state, $country);

        return $country === null ? [] : self::asOptions(self::areas($country)[$state] ?? []);
    }

    /** Does this application hold a third level for this country at all? */
    public static function hasAreas(string $country): bool
    {
        return self::areas(self::clean($country)) !== [];
    }

    // ── Loading ─────────────────────────────────────────────────────────────

    /** @return array<string, string> */
    public static function countries(): array
    {
        return self::$countries ??= self::read('countries.json');
    }

    /** @return array<string, string> */
    public static function states(string $country): array
    {
        $country = self::clean($country);

        return self::$states[$country] ??= self::read('states/'.mb_strtolower($country).'.json');
    }

    /** @return array<string, array<string, string>> */
    public static function areas(string $country): array
    {
        $country = self::clean($country);

        return self::$areas[$country] ??= self::read('areas/'.mb_strtolower($country).'.json');
    }

    /**
     * The country a code belongs to.
     *
     * Taken from the code's own prefix when nothing better is offered. `BD-58`
     * says Bangladesh as plainly as a separate country field would, and relying
     * on the prefix means a state arriving without its country still resolves —
     * which is not a hypothetical, since this shop's orders send exactly that.
     */
    private static function countryFor(string $code, ?string $country): ?string
    {
        $given = self::clean((string) $country);

        if ($given !== '' && isset(self::countries()[$given])) {
            return $given;
        }

        $prefix = explode('-', $code)[0] ?? '';

        return isset(self::countries()[$prefix]) ? $prefix : null;
    }

    /**
     * @param  array<string, string>  $map
     * @return list<array{label: string, value: string}>
     */
    private static function asOptions(array $map): array
    {
        $options = [];

        foreach ($map as $value => $label) {
            $options[] = ['label' => $label, 'value' => (string) $value];
        }

        // By name, because that is what somebody reads down. The files are
        // keyed by code, and code order puts Bagerhat next to Bandarban only by
        // accident and Satkhira nowhere near either.
        usort($options, fn (array $a, array $b): int => strcmp($a['label'], $b['label']));

        return $options;
    }

    /** @return array<string, mixed> */
    private static function read(string $file): array
    {
        $path = base_path('resources/geo/'.$file);

        if (! is_file($path)) {
            // A country with no sub-divisions, or none this application holds.
            // Not a fault: 181 of the 250 countries have no state list, and a
            // missing file is how that is represented.
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    /** Codes arrive with stray spaces and inconsistent case from checkout forms. */
    private static function clean(string $code): string
    {
        return mb_strtoupper(trim($code));
    }
}
