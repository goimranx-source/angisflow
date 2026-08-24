<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SO26-0001. Not SO-2026-0001, and not SO/2026/0001.
 *
 * ── The problem with three parts ─────────────────────────────────────────────
 *
 * Any format that puts a four-digit year between two separators reads as a
 * date, whichever separator is used. SO-2026-0001 is a long word with two
 * stumbles in it; SO/2026/0001 is worse, because a slash between a year and a
 * number is exactly how a date is written. Neither looks like an order number,
 * which is the one job the string has.
 *
 * ── What fixes it ────────────────────────────────────────────────────────────
 *
 * Two changes, and either alone is not enough.
 *
 * The year becomes two digits and fuses to the prefix, so "SO26" is one token
 * that reads as a label rather than as a number in its own right. And there is
 * one separator instead of two, so what is left is plainly a label and a
 * counter: SO26-0001.
 *
 * Six characters and a hyphen, short enough to read back over the phone in one
 * go, and impossible to mistake for a date. The convention is common in ERP —
 * INV24-0001 and its relatives — for exactly this reason.
 *
 * ── What is not changing ─────────────────────────────────────────────────────
 *
 * The annual reset, the per-business sequence, the four-digit padding, and the
 * shop's own `number`, which has never been touched by any of this.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('orders')
            ->whereNotNull('reference')
            ->orderBy('id')
            ->chunkById(500, function ($orders): void {
                foreach ($orders as $order) {
                    $reference = (string) $order->reference;

                    // SO-2026-0001 or SO/2026/0001, in either separator.
                    if (preg_match('/^SO[-\/](\d{4})[-\/](\d+)$/', $reference, $found) !== 1) {
                        continue;
                    }

                    DB::table('orders')->where('id', $order->id)->update([
                        'reference' => sprintf('SO%s-%s', mb_substr($found[1], 2), $found[2]),
                    ]);
                }
            });
    }

    /**
     * Left empty on purpose.
     *
     * Going back would mean guessing a century for every two-digit year, and
     * anything quoted or printed in between stops matching either way. Changing
     * this again is a decision to make deliberately rather than a rollback.
     */
    public function down(): void
    {
        //
    }
};
