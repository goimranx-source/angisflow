<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The statuses this business uses for its own orders.
 *
 * ── Why these belong to the business ─────────────────────────────────────────
 *
 * They are this tool's vocabulary, not any shop's. Ten are built in — Pending,
 * Processing, On hold, Follow-up, Completed, Shipped, Cancelled, Refunded,
 * Failed, Changed — and a business may add its own, because no fixed list
 * survives contact with how people actually run their operations.
 *
 * On the business rather than on a connection, because a status means the same
 * thing whichever shop an order came through. Kept per business rather than
 * globally, because two businesses in one account genuinely work differently.
 *
 * Null means "the built-in ten", so an existing business needs no backfill and
 * nothing has to be seeded for this to work.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            // Only the additions. The built-in ten are defined in code, so a
            // deployment that renames one does not have to migrate every row —
            // and a business cannot lose them by saving an empty list.
            $table->json('order_statuses')->nullable()->after('base_currency');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn('order_statuses');
        });
    }
};
