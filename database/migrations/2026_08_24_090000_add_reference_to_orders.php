<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * This tool's own number for an order, beside the shop's.
 *
 * ── Why `number` could not just be reused ────────────────────────────────────
 *
 * `number` holds whatever identifies the order where it came from. For an order
 * taken at the counter that is this application's own `SO-2026-0001`; for one
 * pulled from a shop it is the shop's — 9577, and 9577 again next year, and
 * 9577 on a second shop at the same time. It is the number to quote when
 * ringing the shop about an order, so it has to stay exactly as the shop wrote
 * it.
 *
 * What it cannot be is this business's own name for the order. Two shops will
 * eventually both send an order 1001, and there is nowhere to put the second
 * one. So the shop's number stays where it is and stays untouched, and every
 * order gains a reference of its own.
 *
 * ── One sequence per business, per year ──────────────────────────────────────
 *
 * The same shape OrderService has been minting for counter sales since before
 * this migration — SO-YYYY-NNNN — because a second format for the same idea is
 * how an application ends up with two names for one thing. Existing counter
 * orders keep the number they were given: their `number` already is a reference
 * in this format, and it is copied across rather than reissued, so nothing
 * anybody has written down or printed stops matching.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('reference')->nullable()->after('number');
        });

        $this->backfill();

        Schema::table('orders', function (Blueprint $table): void {
            // Unique within a business, not globally: two businesses on this
            // installation both start at SO-2026-0001 and neither is wrong.
            $table->unique(['business_id', 'reference']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropUnique(['business_id', 'reference']);
            $table->dropColumn('reference');
        });
    }

    /**
     * A reference for every order that already exists.
     *
     * In the order they were placed rather than the order they were imported,
     * so the sequence reads as a history of the business rather than of the
     * syncing. Ties broken by id, which is stable.
     */
    private function backfill(): void
    {
        $counters = [];

        DB::table('orders')
            ->select('id', 'business_id', 'number', 'ordered_on', 'created_at')
            ->orderBy('ordered_on')
            ->orderBy('id')
            ->chunkById(500, function ($orders) use (&$counters): void {
                foreach ($orders as $order) {
                    $business = (int) $order->business_id;

                    $year = substr(
                        (string) ($order->ordered_on ?: $order->created_at ?: date('Y-m-d')),
                        0,
                        4,
                    );

                    // Already in this shape — it was minted here. Keep it, so a
                    // printed picking slip still matches the screen.
                    if (preg_match('/^SO-\d{4}-\d{4}$/', (string) $order->number) === 1) {
                        DB::table('orders')
                            ->where('id', $order->id)
                            ->update(['reference' => $order->number]);

                        continue;
                    }

                    $key = $business.':'.$year;
                    $counters[$key] = ($counters[$key] ?? $this->highest($business, $year)) + 1;

                    DB::table('orders')->where('id', $order->id)->update([
                        'reference' => sprintf('SO-%s-%04d', $year, $counters[$key]),
                    ]);
                }
            });
    }

    /** The highest number already taken for a business and year, or zero. */
    private function highest(int $business, string $year): int
    {
        $prefix = 'SO-'.$year.'-';

        $last = DB::table('orders')
            ->where('business_id', $business)
            ->where('number', 'like', $prefix.'%')
            ->orderByDesc('number')
            ->value('number');

        return $last === null ? 0 : (int) substr((string) $last, strlen($prefix));
    }
};
