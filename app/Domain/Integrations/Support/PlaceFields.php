<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Support;

/**
 * Recognising an address field from the path it arrives at.
 *
 * ── Why this is worth guessing rather than asking ────────────────────────────
 *
 * Country and district are the two fields on an order most certain to be codes
 * and least likely to be read as such. `billing.state` holding `BD-58` renders
 * as `BD-58` on every screen until somebody notices, works out what it means,
 * finds the mapping row and changes its type — and the reward for doing that is
 * a screen that finally says Satkhira, which it could have said from the start.
 *
 * The paths are not a mystery. Every platform in this application names them
 * the same way, because they all inherited the same checkout form: a country
 * field called country, a sub-division called state or province, sitting under
 * billing or shipping.
 *
 * ── The third level is different ─────────────────────────────────────────────
 *
 * No platform has one. Where a shop wants a thana, an upazila, a barangay or a
 * ward, somebody has added a plugin, and that plugin invented its own meta key.
 * So the third level is matched on the words shops actually use for it, and it
 * is matched loosely on purpose: `billing_thana`, `_shipping_thana`,
 * `meta_data.customer_upazila` should all land as an area, and a shop that
 * called it something nobody has heard of falls through to plain text, which is
 * what it would have been anyway.
 */
final class PlaceFields
{
    /**
     * Words that name a country field, once the path is broken into pieces.
     *
     * @var list<string>
     */
    private const COUNTRY = ['country', 'country_code', 'countrycode'];

    /**
     * And a sub-division. Every one of these means the same box on a checkout
     * form; which word a platform picked is a matter of where it was written.
     *
     * @var list<string>
     */
    private const STATE = ['state', 'province', 'region', 'county', 'district', 'prefecture'];

    /**
     * The level below, which only ever exists because somebody added it.
     *
     * @var list<string>
     */
    private const AREA = ['thana', 'upazila', 'upazilla', 'tehsil', 'taluka', 'barangay', 'ward', 'sub_district', 'subdistrict'];

    /**
     * The type this path should be treated as, or null when it is not a place.
     *
     * Matched on whole words rather than substrings: `country` must not be found
     * inside `country_of_manufacture`, and more to the point `state` must not be
     * found inside `order_status` or `estate_agent`, which is the sort of match
     * that turns a status field into a dropdown of Bangladeshi districts.
     */
    public static function typeFor(string $path): ?string
    {
        $words = self::words($path);

        if ($words === []) {
            return null;
        }

        /*
         * The narrowest level first.
         *
         * A path can name more than one level — `shipping_thana` carries the
         * word shipping, and a shop is free to call its field
         * `billing_district_thana`. Read narrowest-first, such a path is the
         * more specific of the two, which is the one somebody went out of their
         * way to add.
         */
        $levels = [
            ['type' => 'area', 'words' => self::AREA],
            ['type' => 'state', 'words' => self::STATE],
            ['type' => 'country', 'words' => self::COUNTRY],
        ];

        foreach ($levels as $level) {
            if (array_intersect($level['words'], $words) !== []) {
                return $level['type'];
            }
        }

        return null;
    }

    /**
     * A path broken into the words it is made of.
     *
     * `meta_data._billing_thana` becomes [meta, data, billing, thana], so a
     * leading underscore, a dot and an underscore separator all stop mattering.
     * Two-word names survive as well: `sub_district` is kept whole alongside its
     * pieces, or it could never be told from a district.
     *
     * @return list<string>
     */
    private static function words(string $path): array
    {
        $last = (string) (explode('.', $path)[count(explode('.', $path)) - 1] ?? '');
        $last = mb_strtolower(trim($last, '_'));

        if ($last === '') {
            return [];
        }

        $pieces = array_values(array_filter(preg_split('/[_\-\s]+/', $last) ?: []));

        // Adjacent pairs, so a name written as two words is still one word.
        $joined = [];

        for ($i = 0; $i < count($pieces) - 1; $i++) {
            $joined[] = $pieces[$i].'_'.$pieces[$i + 1];
        }

        return [...$pieces, ...$joined, $last];
    }
}
