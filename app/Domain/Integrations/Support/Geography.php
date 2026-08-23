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
 * ── One of the three is shared, and two are not ──────────────────────────────
 *
 * Countries are ISO alpha-2 on every platform here, so `BD` is `BD` whether it
 * came from WooCommerce, Shopify or a bespoke site. There is nothing to split.
 *
 * Sub-divisions are where platforms diverge, so they are stored per scheme and
 * read through AddressScheme. WooCommerce is not even consistent with itself:
 * 23 of the 69 countries it lists use `BD-58`, and the other 46 use bare codes
 * where `CA` means California. A bare code cannot be read without knowing its
 * country, and this refuses to guess rather than returning Canada for it.
 *
 * Areas belong to no platform at all. Wherever a third level exists — a thana,
 * an upazila, a barangay, a ward — a shop owner added a plugin that invented
 * its own codes, so these are keyed by country and a second business with a
 * different plugin gets a different file rather than a different scheme.
 *
 * ── Names as well as codes ───────────────────────────────────────────────────
 *
 * A platform that sends "Ontario" where another sends "ON" is not a platform
 * that needs its own list built for it. Every lookup falls back to matching the
 * written name with case and punctuation set aside, which covers Webflow, which
 * has no codes at all, and the Shopify codes this list happens not to carry.
 */
final class Geography
{
    /** @var array<string, string>|null */
    private static ?array $countries = null;

    /** @var array<string, array<string, string>> keyed 'scheme/country' */
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
    public static function stateName(string $code, ?string $country = null, ?string $scheme = null): ?string
    {
        $code = self::clean($code);
        $country = self::countryFor($code, $country);

        if ($country === null) {
            return null;
        }

        $states = self::states($country, $scheme);

        /*
         * The code, then the name.
         *
         * Not every platform sends a code. Webflow has no geography endpoint at
         * all and sends 'Ontario'; Shopify sends both, and its code is
         * occasionally one this list does not carry. Recognising the name costs
         * one pass over at most 128 entries, and is the difference between a
         * platform working and needing its own list built for it.
         */
        return $states[$code] ?? self::matchByName($states, $code);
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

            $byName = self::matchByName($inState, $code);

            if ($byName !== null) {
                return $byName;
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
    public static function name(string $code, ?string $country = null, ?string $scheme = null): ?string
    {
        $clean = self::clean($code);

        if ($clean === '') {
            return null;
        }

        /*
         * The shape of a code says which level it is — but only for platforms
         * that use a shape.
         *
         * BD, BD-58 and BD-58-05 are told apart by their dashes. A bare `CA` is
         * not: it is Canada as a country and California as a state of the US,
         * and nothing in those two characters says which was meant. So a bare
         * code is read as a subdivision when a country is known and as a
         * country otherwise — a caller who supplied the country is asking about
         * a place inside it, or they would not have said which country.
         */
        $dashes = substr_count($clean, '-');

        if ($dashes >= 2) {
            return self::areaName($clean, $country);
        }

        if ($dashes === 1) {
            return self::stateName($clean, $country, $scheme) ?? self::areaName($clean, $country);
        }

        if ($country !== null && self::clean($country) !== $clean) {
            $inside = self::stateName($clean, $country, $scheme) ?? self::areaName($clean, $country);

            if ($inside !== null) {
                return $inside;
            }
        }

        return self::countryName($clean) ?? self::stateName($clean, $country, $scheme);
    }

    /**
     * A place written out rather than coded.
     *
     * Compared with case and punctuation removed, because the same district is
     * written "Cox's Bazar", "Coxs Bazar" and "COX'S BAZAR" by three different
     * shops and all three mean the one place.
     *
     * @param  array<string, string>  $map
     */
    private static function matchByName(array $map, string $wanted): ?string
    {
        $needle = self::fold($wanted);

        if ($needle === '') {
            return null;
        }

        foreach ($map as $name) {
            if (self::fold($name) === $needle) {
                return $name;
            }
        }

        return null;
    }

    /**
     * The code a written name stands for.
     *
     * The reverse journey, for sending a value back to a shop that wants a code
     * where a person chose a name.
     */
    public static function codeForName(string $name, string $country, string $level = 'state', ?string $scheme = null): ?string
    {
        $needle = self::fold($name);

        if ($needle === '') {
            return null;
        }

        $map = match ($level) {
            'country' => self::countries(),
            'area' => self::flatAreas($country),
            default => self::states($country, $scheme),
        };

        foreach ($map as $code => $label) {
            if (self::fold($label) === $needle) {
                return (string) $code;
            }
        }

        return null;
    }

    /**
     * Every area of a country, with which district it sits in set aside.
     *
     * @return array<string, string>
     */
    private static function flatAreas(string $country): array
    {
        $flat = [];

        foreach (self::areas($country) as $inState) {
            $flat += $inState;
        }

        return $flat;
    }

    /** Case, spacing and punctuation removed, for comparing two written names. */
    private static function fold(string $value): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', mb_strtolower(trim($value)));
    }

    // ── Lists, for a dropdown ───────────────────────────────────────────────

    /** @return list<array{label: string, value: string}> */
    public static function countryOptions(): array
    {
        return self::asOptions(self::countries());
    }

    /** @return list<array{label: string, value: string}> */
    public static function stateOptions(string $country, ?string $scheme = null): array
    {
        return self::asOptions(self::states(self::clean($country), $scheme));
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
    public static function states(string $country, ?string $scheme = null): array
    {
        $country = self::clean($country);
        $scheme = AddressScheme::for($scheme);

        return self::$states[$scheme.'/'.$country] ??= self::read(
            'subdivisions/'.$scheme.'/'.mb_strtolower($country).'.json',
        );
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
