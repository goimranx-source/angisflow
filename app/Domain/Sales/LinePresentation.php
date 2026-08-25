<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use App\Domain\Catalogue\Models\ProductVariant;
use App\Domain\Sales\Models\OrderLine;

/**
 * The two things an order line needs before anybody can read it.
 *
 * ── The picture ──────────────────────────────────────────────────────────────
 *
 * A line says "Vorosa Ghani Vanga Mustard Oil 1l" and a number. Somebody
 * checking an order against what is on the shelf is matching a bottle, not a
 * string, and reads the picture faster than the words — which is why every
 * shop's own admin shows one and why an order screen without them feels like a
 * spreadsheet about products rather than a list of them.
 *
 * ── The list price ───────────────────────────────────────────────────────────
 *
 * A line stores what was charged. What it was *worth* lives on the variant, and
 * the difference between the two is the discount on that line — which is the
 * only way to work out an order's discount from its lines rather than taking a
 * figure somebody typed and hoping it agrees with them.
 *
 * Null when there is nothing to compare against: a line typed by hand with no
 * product behind it has no list price, and inventing one by copying what was
 * charged would report every such line as sold at full price.
 */
final class LinePresentation
{
    /**
     * The relations these two facts are read from.
     *
     * Named here so every caller loads the same set — the alternative is a
     * screen that works until somebody opens it with a different eager load and
     * gets a query per line, or a lazy-loading exception in development.
     *
     * @var list<string>
     */
    public const RELATIONS = [
        'order:id,business_id',
        'variant',
        'variant.product',
        'variant.product.media.mediaItem',
        'variant.media.mediaItem',
    ];

    /**
     * Variants found by SKU, for lines that never got linked to one.
     *
     * ── Why this is needed at all ────────────────────────────────────────────
     *
     * A line normally points at the variant it was sold from. Some do not: an
     * order imported before SKU matching existed, or one whose product arrived
     * in a later sync than the order did. Those lines still carry the SKU, and
     * the variant is sitting right there under it — so refusing to look is
     * showing a blank frame next to a product this application has a picture of.
     *
     * Held for the request only, and keyed by SKU, so a page of forty lines
     * with the same three unlinked SKUs costs three queries rather than forty.
     * Static rather than injected because it is a cache and nothing else: it is
     * never written to, never invalidated, and gone when the response is.
     *
     * @var array<string, ProductVariant|null>
     */
    private static array $bySku = [];

    /** The variant behind a line: the one it points at, or the one it names. */
    private static function variantFor(OrderLine $line): ?ProductVariant
    {
        if ($line->variant !== null) {
            return $line->variant;
        }

        $sku = trim((string) $line->sku);

        if ($sku === '') {
            return null;
        }

        return self::$bySku[$sku] ??= ProductVariant::query()
            ->where('business_id', $line->order?->business_id)
            ->where('sku', $sku)
            ->with(['product', 'product.media.mediaItem', 'media.mediaItem'])
            ->first();
    }

    /** What this line's product looks like, if anything knows. */
    public static function image(OrderLine $line): ?string
    {
        $variant = self::variantFor($line);

        if ($variant === null) {
            return null;
        }

        /*
         * The variant's own picture first.
         *
         * A product with four colours has four pictures, and showing the
         * product's default one against a line that says "blue" is confidently
         * wrong in a way that no picture at all is not.
         */
        $media = $variant->media->first() ?? $variant->product?->media->first();
        $uploaded = $media?->mediaItem?->thumbUrl();

        if ($uploaded !== null) {
            return $uploaded;
        }

        /*
         * Otherwise the address the shop already serves.
         *
         * An uploaded picture outranks it because somebody here chose that one
         * deliberately, where the shop's is whatever the shop happens to be
         * showing today. Most businesses have only the second, since every
         * product they sell arrived from a shop that already hosts its images —
         * see the migration that added the column.
         */
        return $variant->image_url ?? $variant->product?->image_url;
    }

    /** What the line's product normally sells for, per unit, in major units. */
    public static function listPrice(OrderLine $line, int $scale): ?float
    {
        $minor = self::variantFor($line)?->price_minor;

        return $minor === null ? null : round(((int) $minor) / $scale, 2);
    }
}
