<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Support;

/**
 * What another shop's word for a state means in ours.
 *
 * ── Why this is not a field mapping ──────────────────────────────────────────
 *
 * Every other field is a value to copy: their `billing.phone` holds a phone
 * number, ours holds a phone number, and moving it across changes nothing about
 * what it means. Status is not like that. WooCommerce's 'processing' and this
 * application's 'confirmed' are two different vocabularies describing the same
 * moment, and deciding they correspond is a judgement about what those words
 * mean in a set of books — not a path to copy.
 *
 * Left in the field maps it would be a text copy, and 'processing' would land in
 * a column whose vocabulary is confirmed, completed and cancelled. The column
 * would then hold values nothing filters on and no badge knows how to draw.
 *
 * ── One word in, three out ───────────────────────────────────────────────────
 *
 * These platforms fold into one status what this application keeps in three: an
 * order's lifecycle, whether it has been paid, and whether it has been sent.
 * WooCommerce's 'completed' asserts all three at once. So a translation returns
 * only the columns it can honestly speak for, and says nothing about the rest —
 * an empty answer leaves what is already recorded alone, which matters when the
 * shop knows about payment and this application knows about dispatch.
 */
final class StatusVocabulary
{
    /**
     * Translate one platform status into whichever of our columns it settles.
     *
     * @return array<string, string> a subset of status, payment_status, fulfilment_status
     */
    public static function order(string $provider, ?string $status): array
    {
        $status = mb_strtolower(trim((string) $status));

        if ($status === '') {
            return [];
        }

        return match (strtolower($provider)) {
            'woocommerce' => self::wooOrder($status),
            'shopify' => self::shopifyOrder($status),
            'webflow' => self::webflowOrder($status),
            default => self::ours($status),
        };
    }

    /**
     * Shopify reports payment and fulfilment separately, so each is asked on
     * its own rather than folded into one answer.
     *
     * @return array<string, string>
     */
    public static function shopifyPayment(?string $financial): array
    {
        return match (mb_strtolower(trim((string) $financial))) {
            'paid' => ['payment_status' => 'paid'],
            'pending', 'authorized', 'partially_paid' => ['payment_status' => 'unpaid'],
            // Refunded and voided are settlements of their own. Calling either
            // 'unpaid' would put the order back in the receivables the business
            // is chasing, which is the opposite of what happened.
            default => [],
        };
    }

    /** @return array<string, string> */
    public static function shopifyFulfilment(?string $fulfilment): array
    {
        return match (mb_strtolower(trim((string) $fulfilment))) {
            'fulfilled' => ['fulfilment_status' => 'fulfilled'],
            // 'partial' is genuinely neither, and this application has no word
            // for it yet. Saying nothing beats rounding it to one of the two.
            'null', '', 'restocked' => ['fulfilment_status' => 'unfulfilled'],
            default => [],
        };
    }

    /**
     * WooCommerce.
     *
     * 'pending' means awaiting payment and 'on-hold' means awaiting something
     * else; neither is a confirmed sale, so neither sets the lifecycle. Both
     * still say the money has not arrived, which is worth recording.
     *
     * @return array<string, string>
     */
    private static function wooOrder(string $status): array
    {
        return match ($status) {
            'pending', 'on-hold' => ['payment_status' => 'unpaid'],
            'processing' => ['status' => 'confirmed', 'payment_status' => 'paid', 'fulfilment_status' => 'unfulfilled'],
            'completed' => ['status' => 'completed', 'payment_status' => 'paid', 'fulfilment_status' => 'fulfilled'],
            'cancelled', 'failed' => ['status' => 'cancelled'],
            // A refund reverses money that was taken and needs a credit note,
            // not a status change. Left for the returns flow to record properly.
            'refunded' => [],
            default => [],
        };
    }

    /** @return array<string, string> */
    private static function shopifyOrder(string $status): array
    {
        // Shopify's own order-level word is 'open', 'closed' or 'cancelled';
        // payment and fulfilment come from their own fields above.
        return match ($status) {
            'closed' => ['status' => 'completed'],
            'cancelled' => ['status' => 'cancelled'],
            'open' => ['status' => 'confirmed'],
            default => [],
        };
    }

    /** @return array<string, string> */
    private static function webflowOrder(string $status): array
    {
        return match ($status) {
            'fulfilled' => ['status' => 'completed', 'payment_status' => 'paid', 'fulfilment_status' => 'fulfilled'],
            'unfulfilled' => ['status' => 'confirmed', 'payment_status' => 'paid', 'fulfilment_status' => 'unfulfilled'],
            'refunded', 'disputed' => [],
            default => [],
        };
    }

    /**
     * A bespoke site is asked to speak our vocabulary, and anything else is
     * ignored rather than guessed at. Nothing here could know what a word
     * somebody invented was meant to mean.
     *
     * @return array<string, string>
     */
    private static function ours(string $status): array
    {
        return match ($status) {
            'confirmed', 'completed', 'cancelled' => ['status' => $status],
            'paid', 'unpaid' => ['payment_status' => $status],
            'fulfilled', 'unfulfilled' => ['fulfilment_status' => $status],
            default => [],
        };
    }

    /**
     * The other direction: our status, in their words.
     *
     * Narrower than the inbound table on purpose. This application only ever
     * has news about two things — an order was dispatched, or it was cancelled
     * — and a push that tried to say more would be overwriting a shop's own
     * record of a payment it processed and we did not.
     */
    public static function toPlatform(string $provider, string $status): ?string
    {
        return match (strtolower($provider)) {
            'woocommerce' => match ($status) {
                'completed' => 'completed',
                'cancelled' => 'cancelled',
                'confirmed' => 'processing',
                default => null,
            },
            'shopify' => match ($status) {
                'cancelled' => 'cancelled',
                'completed' => 'closed',
                default => null,
            },
            'webflow' => match ($status) {
                'completed' => 'fulfilled',
                default => null,
            },
            default => $status,
        };
    }
}
