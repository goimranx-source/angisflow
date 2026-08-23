<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Support;

use App\Domain\Money\Currencies;
use Carbon\CarbonImmutable;

/**
 * Turning what a shop sent into what this application stores.
 *
 * ── Why a fixed list and not an expression ───────────────────────────────────
 *
 * The tempting design is to let a mapping carry a snippet — a callback, a
 * template, a little expression language — so any transformation is possible
 * without shipping code. It is also a way to let whoever can edit an
 * integration's settings run code on the server, and this is a product where
 * "whoever" includes every subscriber. A closed list of named transforms cannot
 * be talked into doing anything that is not on it.
 *
 * ── Why they never throw ─────────────────────────────────────────────────────
 *
 * These run mid-sync, over a page of records, on data nobody here controls. A
 * shop that sends "N/A" where a number was expected is an ordinary Tuesday, and
 * an exception would abandon the other forty-nine orders in the batch over one
 * bad field. Anything unconvertible comes back null, and the mapping layer
 * declines to write a null over a value that already exists.
 */
final class Transform
{
    /**
     * What a mapping may ask for, and what to call it on screen.
     *
     * @return array<string, string>
     */
    /**
     * What a mapping may ask for, and what to call it on screen.
     *
     * ── Why these names and not descriptive ones ─────────────────────────────
     *
     * They were written as descriptions of what happens to the value — "Text —
     * Title Case", "Reference — without a leading #". Accurate, and unusable:
     * somebody setting up a shop is looking for the name of a form field, and
     * the vocabulary they already have is the one every form builder uses.
     * Select, Radio, Textarea, WYSIWYG.
     *
     * The keys are untouched. They are stored in every existing mapping, and
     * renaming them would silently unmap every connected shop.
     *
     * ── The types that need options ──────────────────────────────────────────
     *
     * A select is not a select without its choices, and one order only ever
     * shows the one value it happens to carry — there is no way to learn from a
     * single order that Priority can also be High. So the types listed in
     * NEEDS_OPTIONS ask for them, and the mapping screen shows a place to enter
     * them.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            // Text
            'trim' => 'Text',
            'none' => 'Text (unchanged)',
            'textarea' => 'Textarea',
            'rich_text' => 'WYSIWYG (formatted)',
            'strip_tags' => 'Text (HTML removed)',
            'first_line' => 'Text (first line only)',
            'upper' => 'Text (UPPERCASE)',
            'lower' => 'Text (lowercase)',
            'title' => 'Text (Title Case)',
            'slug' => 'Slug',
            'strip_hash' => 'Reference',

            // Choices — the ones that carry options
            'select' => 'Select',
            'radio' => 'Radio',
            'checkbox' => 'Checkbox (multiple)',
            'boolean' => 'Switch (yes/no)',

            /*
             * ── Places, which bring their own choices ────────────────────────
             *
             * A select needs its options typed because nobody but the shop's
             * owner knows them. These three are the opposite: the lists are
             * known, they are long, and they are related — 250 countries, the
             * 64 districts inside Bangladesh, the 7 thanas inside Satkhira.
             *
             * Typing them would be absurd and choosing from all 2,040
             * sub-divisions at once would be worse, so they resolve themselves
             * from Geography and narrow as the level above is chosen.
             */
            'country' => 'Country',
            'state' => 'State / District',
            'area' => 'Area / Thana',

            // Numbers and money
            'integer' => 'Number (whole)',
            'decimal' => 'Number (decimal)',
            'money_minor' => 'Money',
            'percent' => 'Percentage',

            // Identity and contact
            'email' => 'Email',
            'digits' => 'Phone',
            'url' => 'URL',

            // Dates
            'date' => 'Date',
            'datetime' => 'Date and time',

            /*
             * ── Media and rich content ───────────────────────────────────────
             *
             * A shop's images arrive as a URL, or a list of them, or an object
             * with a src inside — and a description arrives as HTML that must
             * survive rather than be flattened. Treating either as plain text is
             * how a catalogue imports with no pictures and its formatting
             * stripped.
             */
            'image' => 'Image',
            'image_list' => 'Gallery',
            'file' => 'File',
            'video' => 'Video',

            'list' => 'Tags / list',
            'colour' => 'Colour',
            'json' => 'JSON',
        ];
    }

    /**
     * Types that are meaningless without their choices.
     *
     * @var list<string>
     */
    public const NEEDS_OPTIONS = ['select', 'radio', 'checkbox'];

    /** Does this type want a list of options entered against it? */
    public static function needsOptions(?string $transform): bool
    {
        return in_array((string) $transform, self::NEEDS_OPTIONS, true);
    }

    /**
     * Types that are a dropdown whose choices this application already holds.
     *
     * Distinct from NEEDS_OPTIONS, which is the same shape of control with the
     * opposite problem: there, nobody but the shop's owner can supply the list;
     * here, asking them to would be asking them to type out the world.
     *
     * @var list<string>
     */
    public const RESOLVED_OPTIONS = ['country', 'state', 'area'];

    public static function resolvesOptions(?string $transform): bool
    {
        return in_array((string) $transform, self::RESOLVED_OPTIONS, true);
    }

    /**
     * Types whose value is a picture or a file, so a screen can put them
     * somewhere a picture belongs rather than in a row of inputs.
     *
     * @var list<string>
     */
    public const MEDIA = ['image', 'image_list', 'file', 'video'];

    public static function isMedia(?string $transform): bool
    {
        return in_array((string) $transform, self::MEDIA, true);
    }

    public static function exists(string $name): bool
    {
        return array_key_exists($name, self::options());
    }

    /**
     * Turn one of our values back into what the platform expects.
     *
     * ── Why sending it unchanged was wrong ───────────────────────────────────
     *
     * Because apply() is not symmetrical, and money is the case that matters. A
     * shop sends "2320.00" and we store 232000 — minor units, because a total
     * held as a float eventually loses a penny. Pushing that number back
     * unchanged does not send the same amount in a different notation; it sends
     * a hundred times the amount, and the shop believes it.
     *
     * Dates have the same shape of problem: a column holding a date is not the
     * ISO 8601 timestamp most APIs want.
     *
     * ── Why most transforms return the value untouched ───────────────────────
     *
     * Because they are not encodings, they are cleanups. Trimming, upper-casing
     * or stripping a leading hash lose information rather than encode it, and
     * there is nothing to undo — the cleaned value is the honest one to send.
     * Only the transforms that genuinely change a representation are inverted.
     *
     * @param  array<string, mixed>  $context  'currency' for money
     */
    public static function reverse(mixed $value, ?string $transform, array $context = []): mixed
    {
        if ($value === null || $transform === null || $transform === '' || $transform === 'none') {
            return $value;
        }

        return match ($transform) {
            'money_minor' => self::fromMinor($value, (string) ($context['currency'] ?? 'USD')),
            'date' => self::toIsoDate($value),
            'boolean' => (bool) $value,
            'integer' => is_numeric($value) ? (int) $value : $value,

            /*
             * Media goes back as a list of objects, not as the text we stored.
             *
             * ── Why the stored form cannot simply be sent ────────────────────
             *
             * Coming in, a gallery is flattened to one readable string so it
             * can live in a single column. Sent back unchanged that string is
             * what the shop receives — and WooCommerce answers a joined string
             * in `images` with "Invalid parameter(s): images" and rejects the
             * entire request, so a price change travelling beside it never
             * lands either.
             *
             * Every platform in the catalogue that accepts images at all wants
             * the same shape: a list of objects carrying a src. Restoring it
             * here means the reverse of an import is an export, which is what
             * a transform is for.
             */
            'image', 'image_list' => self::toImageList($value),

            // A checkbox holds several chosen values; a list holds several of
            // anything. Both go back as the separate values they were made from.
            'list', 'checkbox' => self::toList($value),

            default => $value,
        };
    }

    /**
     * The shape a platform expects a gallery in.
     *
     * @return list<array{src: string}>
     */
    private static function toImageList(mixed $value): array
    {
        return array_values(array_map(
            static fn (string $url): array => ['src' => $url],
            self::toList($value),
        ));
    }

    /**
     * Back to the separate values a joined string was made from.
     *
     * @return list<string>
     */
    private static function toList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map(
                static fn ($item): string => is_string($item) ? trim($item) : '',
                $value,
            )));
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        // Split on the separator the inbound side joins with. Newlines too,
        // because a value edited by hand in a text box will have them.
        return array_values(array_filter(array_map(
            'trim',
            preg_split('/[,\n]+/', $value) ?: [],
        )));
    }

    /**
     * Minor units back to the decimal string a shop's API expects.
     *
     * Scaled by the currency rather than by a constant two: yen has no minor
     * unit at all and dinars have three, so a fixed divisor is wrong for a
     * quarter of the world and wrong by a factor of a thousand for some of it.
     *
     * Returned as a string, because that is what these APIs send and accept, and
     * because a float re-introduces exactly the rounding this representation
     * exists to avoid.
     */
    private static function fromMinor(mixed $value, string $currency): ?string
    {
        if (! is_numeric($value)) {
            return null;
        }

        $scale = Currencies::scale($currency);

        return number_format((int) $value / (10 ** $scale), $scale, '.', '');
    }

    /** A date column as an ISO 8601 instant, which is what these APIs read. */
    private static function toIsoDate(mixed $value): mixed
    {
        try {
            return CarbonImmutable::parse((string) $value)->toIso8601String();
        } catch (\Throwable) {
            return $value;
        }
    }

    /**
     * @param  array<string, mixed>  $context  'currency' for money
     */
    public static function apply(mixed $value, ?string $transform, array $context = []): mixed
    {
        if ($transform === null || $transform === '' || $transform === 'none') {
            return $value;
        }

        if ($value === null) {
            return null;
        }

        /*
         * Some types are *meant* to receive a structure.
         *
         * A gallery arrives as a list, and an image often as an object with a
         * `src` inside — so those are handled before the scalar guard below,
         * which would otherwise pass the array straight through and store a
         * PHP array where a URL was expected.
         */
        if (is_array($value)) {
            return match ($transform) {
                'image' => self::firstUrl($value),
                'image_list', 'list', 'checkbox' => self::urlList($value, $transform === 'image_list'),
                'json' => $value,
                'integer', 'decimal', 'money_minor', 'percent' => null,
                default => $value,
            };
        }

        $text = is_bool($value) ? ($value ? '1' : '0') : (string) $value;

        return match ($transform) {
            'trim' => trim($text),

            /*
             * ── The types that describe a control rather than a conversion ───
             *
             * A select, a radio and a textarea all hold text. What separates
             * them from 'trim' is not what happens to the value — it is what
             * the person editing an order is shown: a dropdown of the mapped
             * options, a row of radios, a box with room to type.
             *
             * Trimmed and otherwise left alone, because a chosen value has to
             * come back byte-identical or it stops matching its option.
             */
            'select', 'radio' => self::blankToNull(trim($text)),

            /*
             * A place code, kept exactly as the shop stores it.
             *
             * Uppercased because checkout forms are inconsistent about it and
             * `bd-58` must match `BD-58` to resolve, but otherwise untouched:
             * the code is the value, and the name it stands for is worked out
             * at the moment of display rather than stored in its place. Storing
             * the name instead would mean a district renamed next year silently
             * disagreeing with every order taken before it.
             */
            'country', 'state', 'area' => self::blankToNull(mb_strtoupper(trim($text))),

            // Newlines are the point of a textarea, so only the ends are cut.
            'textarea' => self::blankToNull(trim($text)),

            // Several chosen values arriving already flattened to a string.
            'checkbox' => self::blankToNull(implode(', ', array_filter(array_map('trim', explode(',', $text))))),
            'upper' => mb_strtoupper($text),
            'lower' => mb_strtolower($text),
            'title' => mb_convert_case(mb_strtolower(trim($text)), MB_CASE_TITLE, 'UTF-8'),

            // Shopify names its orders '#1001'. Ours is stored without it, and
            // a hash in a reference field breaks every URL it later appears in.
            'strip_hash' => ltrim(trim($text), '#'),

            // Kept as text, not cast: a leading zero and a leading + are both
            // significant in a phone number, and both are lost to an integer.
            'digits' => self::blankToNull(preg_replace('/[^\d+]/', '', $text)),

            // Woo returns product descriptions as rendered HTML. Stored raw it
            // becomes markup on screen, or a stripped mess in a plain-text field.
            'strip_tags' => self::blankToNull(trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'))),

            'first_line' => self::blankToNull(trim(strtok($text, "\r\n") ?: '')),

            // A slug for a shop that sends a name where a handle is wanted.
            'slug' => self::blankToNull(trim((string) preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($text)), '-')),

            /*
             * An address, lowercased and trimmed — the two differences that
             * make the same person look like two customers, since the linker
             * matches on email.
             */
            'email' => self::blankToNull(mb_strtolower(trim($text))),

            // Kept as text: a percentage is a figure people read, and 12.5%
            // arriving as "12.5" or "0.125" is a mapping decision, not a cast.
            'percent' => self::toDecimal($text),

            // Scheme added when a shop sends a bare host, or the link is dead
            // the moment anything renders it as an href.
            'url' => self::toUrl($text),

            // A single media address. Same treatment as any link, since that is
            // what an image, a file and a video all are once they arrive.
            'image', 'file', 'video' => self::toUrl($text),

            // One address given where a gallery was expected is a gallery of one.
            'image_list' => self::blankToNull(self::toUrl($text)),

            'rich_text' => self::toRichText($text),

            'list' => self::blankToNull(implode(', ', array_filter(array_map('trim', explode(',', $text))))),

            // #abc123, or a named colour left as it is. Anything else is not a
            // colour and is refused rather than stored for a swatch to choke on.
            'colour' => preg_match('/^#?[0-9a-f]{3,8}$/i', trim($text)) === 1
                ? '#'.ltrim(trim($text), '#')
                : (preg_match('/^[a-z ]{3,20}$/i', trim($text)) === 1 ? mb_strtolower(trim($text)) : null),

            'json' => json_decode($text, true) ?? $text,

            'integer' => self::toInteger($text),
            'decimal' => self::toDecimal($text),
            'boolean' => self::toBoolean($text),
            'money_minor' => self::toMinor($text, (string) ($context['currency'] ?? 'USD')),
            'date' => self::toDate($text, 'Y-m-d'),
            'datetime' => self::toDate($text, 'Y-m-d H:i:s'),

            // An unknown transform on a saved mapping — a name that was valid
            // when it was written and has since been removed. Passing the value
            // through unchanged beats discarding it.
            default => $value,
        };
    }

    /**
     * The first usable address out of whatever shape the shop used.
     *
     * WooCommerce sends `images: [{src: …}]`, Shopify `images: [{src: …}]` too,
     * a bespoke site often just a list of strings. All three are one image to
     * somebody mapping a product photo.
     *
     * @param  array<mixed>  $value
     */
    private static function firstUrl(array $value): ?string
    {
        foreach ($value as $item) {
            $candidate = is_array($item)
                ? ($item['src'] ?? $item['url'] ?? $item['href'] ?? null)
                : $item;

            if (is_string($candidate) && trim($candidate) !== '') {
                return self::toUrl($candidate);
            }
        }

        return null;
    }

    /**
     * Every address in a list, as a comma-separated string.
     *
     * Stored as text rather than an array because that is what a custom field
     * holds, and because a gallery read back for display wants one value it can
     * split — not a structure whose shape depends on which platform sent it.
     *
     * @param  array<mixed>  $value
     */
    private static function urlList(array $value, bool $asUrls): ?string
    {
        $found = [];

        foreach ($value as $item) {
            $candidate = is_array($item)
                ? ($item['src'] ?? $item['url'] ?? $item['name'] ?? null)
                : $item;

            if (! is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            $found[] = $asUrls ? (string) self::toUrl($candidate) : trim($candidate);
        }

        return $found === [] ? null : implode(', ', $found);
    }

    /**
     * Formatted text, with the markup kept and the dangerous parts removed.
     *
     * The WYSIWYG type. Stripping tags here would flatten every product
     * description in the catalogue; keeping them wholesale would render a
     * connected shop's HTML on our pages, and a shop is not a source we can
     * trust with a script tag or an onclick.
     */
    private static function toRichText(string $text): ?string
    {
        $clean = preg_replace('#<\s*(script|style|iframe|object|embed)\b[^>]*>.*?<\s*/\s*\1\s*>#is', '', $text) ?? $text;

        // Inline handlers and javascript: URLs, which survive tag removal.
        $clean = preg_replace('/\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $clean) ?? $clean;
        $clean = preg_replace('#(href|src)\s*=\s*("|\')\s*javascript:[^"\']*\2#i', '', $clean) ?? $clean;

        return self::blankToNull(trim($clean));
    }

    /** A bare host becomes a real link, or anything rendering it as an href dies. */
    private static function toUrl(string $text): ?string
    {
        $clean = trim($text);

        if ($clean === '') {
            return null;
        }

        return preg_match('#^https?://#i', $clean) === 1 ? $clean : 'https://'.ltrim($clean, '/');
    }

    private static function blankToNull(?string $value): ?string
    {
        return $value === null || $value === '' ? null : $value;
    }

    private static function toInteger(string $text): ?int
    {
        $clean = preg_replace('/[^\d\-]/', '', $text) ?? '';

        return $clean === '' || $clean === '-' ? null : (int) $clean;
    }

    private static function toDecimal(string $text): ?float
    {
        return is_numeric($clean = self::normaliseNumber($text)) ? (float) $clean : null;
    }

    /**
     * Yes, no, and the eleven ways a REST API says either.
     *
     * WooCommerce sends true; a bespoke site sends "1", "yes" or "Y"; a
     * spreadsheet-backed one sends "TRUE". Anything unrecognised is null rather
     * than false — "we could not tell" and "no" are different answers, and
     * guessing the second silently switches something off.
     */
    private static function toBoolean(string $text): ?bool
    {
        return match (mb_strtolower(trim($text))) {
            '1', 'true', 'yes', 'y', 'on', 'enabled', 'active' => true,
            '0', 'false', 'no', 'n', 'off', 'disabled', 'inactive' => false,
            default => null,
        };
    }

    /**
     * Money, in the smallest unit the currency actually has.
     *
     * Scaled by the currency rather than by a hard 100. Sending ¥1,200 through
     * a fixed ×100 stores ¥120,000, and the shop that notices is the one whose
     * books are already wrong.
     *
     * Rounded at the end, not truncated: (int) (19.99 * 100) is 1998 on a
     * binary float, and a penny lost per line is a ledger that will not balance.
     */
    private static function toMinor(string $text, string $currency): ?int
    {
        $clean = self::normaliseNumber($text);

        if (! is_numeric($clean)) {
            return null;
        }

        return (int) round(((float) $clean) * (10 ** Currencies::scale($currency)));
    }

    /**
     * Strip the currency dressing a shop puts on a number.
     *
     * Handles a symbol, spaces and thousands separators. Also the European
     * convention — "1.234,56" — where the separators are the other way round:
     * read naively that is one and a bit, not twelve hundred.
     */
    private static function normaliseNumber(string $text): string
    {
        $clean = preg_replace('/[^\d.,\-]/', '', trim($text)) ?? '';

        $lastComma = strrpos($clean, ',');
        $lastDot = strrpos($clean, '.');

        if ($lastComma !== false && ($lastDot === false || $lastComma > $lastDot)) {
            // Comma is the decimal point; dots are grouping.
            return str_replace(',', '.', str_replace('.', '', $clean));
        }

        return str_replace(',', '', $clean);
    }

    /**
     * A date, in one format, in UTC.
     *
     * Platforms disagree about almost everything here: ISO 8601 with an offset,
     * ISO without one, a plain date, a unix timestamp. Parsed loosely and
     * written strictly, so what lands in the column is comparable with what is
     * already there.
     *
     * A value with no offset is read as UTC rather than as server local time —
     * WooCommerce's `_gmt` fields carry no marker, and reading those as local
     * shifts every order by the server's offset.
     */
    private static function toDate(string $text, string $format): ?string
    {
        $text = trim($text);

        if ($text === '') {
            return null;
        }

        try {
            if (ctype_digit($text) && mb_strlen($text) >= 9) {
                return CarbonImmutable::createFromTimestampUTC((int) $text)->format($format);
            }

            $hasZone = str_contains($text, '+')
                || str_ends_with($text, 'Z')
                || preg_match('/\d-\d{2}:\d{2}$/', $text) === 1;

            return CarbonImmutable::parse($text, $hasZone ? null : 'UTC')->utc()->format($format);
        } catch (\Throwable) {
            return null;
        }
    }
}
