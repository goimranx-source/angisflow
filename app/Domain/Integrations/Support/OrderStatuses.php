<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Support;

use App\Domain\Tenancy\Models\Business;
use Illuminate\Support\Str;

/**
 * This tool's order statuses, for one business.
 *
 * ── Whose vocabulary this is ─────────────────────────────────────────────────
 *
 * Ours. Not any shop's. A connected WooCommerce site says 'processing' and a
 * bespoke site says 'Done', and both are translated into one of these on the way
 * in — so these are the words that appear on the orders list, drive its filters,
 * and colour its badges.
 *
 * Ten are built in. A business may add more, because no fixed list survives
 * contact with how people actually run their operations — a shop doing repairs
 * needs "Awaiting parts" and nothing here could have guessed it.
 *
 * ── What a business may not do ───────────────────────────────────────────────
 *
 * Remove or rename a built-in. They are referenced by the sync, by the status
 * translation tables and by the orders screen, and a business deleting
 * 'completed' would leave every finished order pointing at a status that no
 * longer exists. Additions are safe; subtractions are not.
 */
final class OrderStatuses
{
    /**
     * The ten that always exist.
     *
     * `sets` is what choosing one writes. A status that says nothing about money
     * — On hold, Follow-up, Changed — leaves the payment column alone rather
     * than guessing, because plenty of held orders are paid and waiting on stock.
     *
     * @return array<string, array{label: string, tone: string, sets: array<string, string>}>
     */
    public static function builtIn(): array
    {
        return [
            'pending' => ['label' => 'Pending', 'tone' => 'neutral',
                'sets' => ['status' => 'pending', 'payment_status' => 'unpaid']],

            'processing' => ['label' => 'Processing', 'tone' => 'info',
                'sets' => ['status' => 'processing', 'payment_status' => 'paid', 'fulfilment_status' => 'unfulfilled']],

            'on_hold' => ['label' => 'On hold', 'tone' => 'warning',
                'sets' => ['status' => 'on_hold']],

            // Orange, not the amber On hold uses. Both are "somebody has to
            // do something", and telling which was which meant reading the word.
            'follow_up' => ['label' => 'Follow-up', 'tone' => 'orange',
                'sets' => ['status' => 'follow_up']],

            'completed' => ['label' => 'Completed', 'tone' => 'success',
                'sets' => ['status' => 'completed', 'payment_status' => 'paid', 'fulfilment_status' => 'fulfilled']],

            'shipped' => ['label' => 'Shipped', 'tone' => 'brand',
                'sets' => ['status' => 'shipped', 'fulfilment_status' => 'fulfilled']],

            'cancelled' => ['label' => 'Cancelled', 'tone' => 'danger',
                'sets' => ['status' => 'cancelled']],

            // Not 'unpaid'. The money arrived and then went back; calling it
            // unpaid would put the order into the receivables being chased.
            // Purple rather than a third grey. Money going back out is not a
            // quiet state, and it shared its colour with Pending and Changed.
            'refunded' => ['label' => 'Refunded', 'tone' => 'purple',
                'sets' => ['status' => 'refunded']],

            // Rose, near the red Cancelled uses but not it. They are both bad
            // endings and they are not the same one: cancelled is a decision,
            // failed is a thing that went wrong.
            'failed' => ['label' => 'Failed', 'tone' => 'rose',
                'sets' => ['status' => 'failed', 'payment_status' => 'unpaid']],

            // Teal. It is the state that says "look at this", which grey is
            // the wrong colour for.
            'changed' => ['label' => 'Changed', 'tone' => 'teal',
                'sets' => ['status' => 'changed']],
        ];
    }

    /**
     * Everything this business uses — the built-in ten plus its own additions.
     *
     * @return array<string, array{label: string, tone: string, sets: array<string, string>, custom: bool}>
     */
    public static function for(?Business $business): array
    {
        $all = [];

        foreach (self::builtIn() as $key => $status) {
            $all[$key] = $status + ['custom' => false];
        }

        foreach (self::custom($business) as $key => $label) {
            // A built-in cannot be shadowed by an addition of the same name —
            // that is how 'completed' would quietly stop meaning completed.
            if (isset($all[$key])) {
                continue;
            }

            $all[$key] = [
                'label' => $label,
                'tone' => self::customTone($key),
                // An added status settles the lifecycle column and nothing else.
                // Nothing here could know whether "Awaiting parts" means paid.
                'sets' => ['status' => $key],
                'custom' => true,
            ];
        }

        return $all;
    }

    /**
     * A colour for a status this application did not name.
     *
     * ── Why it is derived and not stored ─────────────────────────────────────
     *
     * Every added status was neutral, so a business with three of them had
     * three identical grey badges, all the same grey as Pending. Asking whoever
     * adds one to choose a colour is a question they have no basis to answer at
     * the moment they are asked -- they are naming a workflow step, not
     * designing a palette -- and a field nobody fills is a field that defaults
     * back to grey.
     *
     * Derived from the key, so it is the same colour on every screen and after
     * every deploy without anything being stored. crc32 rather than a running
     * index: an index would reshuffle every badge the day somebody deletes the
     * first of their custom statuses.
     *
     * Three tones, not ten. They are held apart from the built-in colours so an
     * added status never wears Cancelled's red or Completed's green and gets
     * read as one.
     */
    private static function customTone(string $key): string
    {
        $palette = ['indigo', 'lime', 'slate'];

        return $palette[crc32($key) % count($palette)];
    }

    /** @return array<string, string> key => label */
    public static function custom(?Business $business): array
    {
        $stored = $business?->order_statuses;

        if (! is_array($stored)) {
            return [];
        }

        $custom = [];

        foreach ($stored as $key => $label) {
            $key = self::key(is_int($key) ? (string) $label : (string) $key);

            if ($key !== '') {
                $custom[$key] = trim((string) $label) ?: Str::headline($key);
            }
        }

        return $custom;
    }

    /**
     * Add one, returning its key.
     *
     * Names are keyed the same way everywhere — "Awaiting Parts", "awaiting
     * parts" and "awaiting_parts" are one status, so adding it twice under two
     * spellings is not possible.
     */
    public static function add(Business $business, string $label): ?string
    {
        $label = trim($label);
        $key = self::key($label);

        if ($key === '' || isset(self::builtIn()[$key])) {
            return null;
        }

        $custom = self::custom($business);
        $custom[$key] = $label;

        $business->order_statuses = $custom;
        $business->save();

        return $key;
    }

    /** Additions only — a built-in cannot be removed, see the class note. */
    public static function remove(Business $business, string $key): bool
    {
        $key = self::key($key);
        $custom = self::custom($business);

        if (! isset($custom[$key])) {
            return false;
        }

        unset($custom[$key]);
        $business->order_statuses = $custom;
        $business->save();

        return true;
    }

    public static function label(?Business $business, string $key): string
    {
        return self::for($business)[self::key($key)]['label'] ?? Str::headline($key);
    }

    /** The stored form of a status name: lower, single underscores. */
    public static function key(string $name): string
    {
        $key = mb_strtolower(trim($name));
        $key = preg_replace('/[^a-z0-9]+/', '_', $key) ?? $key;

        return trim($key, '_');
    }
}
