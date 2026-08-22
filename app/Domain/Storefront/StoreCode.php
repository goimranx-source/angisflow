<?php

declare(strict_types=1);

namespace App\Domain\Storefront;

use App\Models\Storefront;

/**
 * A two- or three-letter tag for a shop, for putting in front of an order number.
 *
 * ── Why an order number needs one ────────────────────────────────────────────
 *
 * Because the number alone stops identifying anything the moment a business
 * sells in more than one place. Every platform numbers its own orders from its
 * own sequence, so a business with a website, a marketplace and a counter can
 * genuinely have three different orders called 1043. On a list that mixes them
 * — which is the whole point of the orders screen — "#1043" is ambiguous, and
 * the only way to resolve it is to look across at another column.
 *
 * "VB-1043" resolves it where somebody is already looking.
 *
 * ── The shop's own tag, or initials until it has one ─────────────────────────
 *
 * The tag belongs on the storefront, because it is a name people learn and say
 * out loud, and only the owner knows what they actually call the place.
 * Initials cannot know: a shop everybody calls VB reads as VBO because its
 * record happens to say "Vorosa Bajar Online", and it would change again the
 * day somebody renamed it — after the old one had been read off a hundred order
 * rows.
 *
 * So a stored `code` wins whenever there is one. Derivation stays as the
 * fallback rather than being removed, because a blank tag is worse than an
 * imperfect one: every shop is legible from the day it is created, and setting
 * the field is an improvement somebody makes when they care, not a chore
 * standing between them and a working screen.
 *
 * Either way the result is unique within a business — two shops sharing a tag
 * is exactly the ambiguity this exists to remove. A stored code is already
 * unique by database constraint; colliding *derived* ones take a numbered
 * variant, ordered by id so a shop's tag does not change when another is added
 * beside it.
 */
final class StoreCode
{
    /** Long enough to distinguish, short enough not to bury the number. */
    private const MAX = 3;

    /**
     * Codes for every shop in a business, keyed by storefront id.
     *
     * Built for the whole business at once rather than one shop at a time,
     * because uniqueness is a property of the set — a single storefront cannot
     * know whether its initials clash with anybody else's.
     *
     * @return array<int, string>
     */
    public static function forBusiness(int $businessId): array
    {
        $shops = Storefront::query()
            ->withoutGlobalScopes()
            ->where('business_id', $businessId)
            // By id, so a code is stable: adding a shop tomorrow must not
            // renumber the ones already printed on today's screens.
            ->orderBy('id')
            ->get(['id', 'name', 'code']);

        $codes = [];
        $taken = [];

        /*
         * Chosen tags are claimed first, before any are derived.
         *
         * Otherwise a shop that set its tag to VB could find it already taken
         * by a shop with a lower id that merely happens to be called something
         * starting with V and B — and the deliberate choice would lose to the
         * guess.
         */
        foreach ($shops as $shop) {
            $chosen = self::clean((string) ($shop->code ?? ''));

            if ($chosen !== '') {
                $taken[$chosen] = true;
                $codes[(int) $shop->id] = $chosen;
            }
        }

        foreach ($shops as $shop) {
            if (isset($codes[(int) $shop->id])) {
                continue;
            }

            $base = self::initials((string) $shop->name);
            $code = $base;
            $suffix = 2;

            while (isset($taken[$code])) {
                $code = $base.$suffix++;
            }

            $taken[$code] = true;
            $codes[(int) $shop->id] = $code;
        }

        return $codes;
    }

    /**
     * A tag as it should be stored and shown.
     *
     * Upper case and stripped of anything that is not a letter or digit, so the
     * same intent typed three ways — "vb", "V.B.", " Vb " — is one tag rather
     * than three, and so a badge beside an order number cannot be widened by a
     * stray space.
     */
    public static function clean(string $code): string
    {
        $cleaned = preg_replace('/[^\p{L}\p{N}]/u', '', trim($code)) ?? '';

        return mb_strtoupper(mb_substr($cleaned, 0, 8));
    }

    /**
     * The initials of a name, or the first letters of it when it is one word.
     *
     * Digits are kept — plenty of shops are called "Shop 2" and the 2 is the
     * distinguishing part. Anything else is dropped, so punctuation and
     * whichever script the name is written in cannot produce a tag that is
     * unreadable beside a number.
     */
    public static function initials(string $name): string
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($words === []) {
            return 'ST';
        }

        if (count($words) === 1) {
            // One word gives no initials to take, so the opening letters of it
            // stand in: "Vorosabajar" becomes VOR.
            return mb_strtoupper(mb_substr($words[0], 0, self::MAX));
        }

        $letters = '';

        foreach ($words as $word) {
            if (mb_strlen($letters) >= self::MAX) {
                break;
            }

            $letters .= mb_substr($word, 0, 1);
        }

        return mb_strtoupper($letters);
    }
}
