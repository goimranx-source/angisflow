<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which of a business's shops an order came through.
 *
 * ── Why `channel` was not enough ─────────────────────────────────────────────
 *
 * An order already carries `channel` ('manual', 'shopify', …) and
 * `external_ref`, and together they are the idempotency key that stops a
 * replayed webhook becoming two orders. That pair answers "what kind of system
 * sent this", which is a different question from "which of my shops was it".
 *
 * A business selling through three storefronts — one taking dirhams, one taka,
 * one dollars — cannot tell them apart on `channel` alone: two of them may be
 * the same platform. Without this column, "how did the Dubai shop do this
 * month" has no answer, and neither does routing a push back to the right one.
 *
 * ── Nullable, and staying that way ───────────────────────────────────────────
 *
 * Most orders have no storefront and never will: a sale taken at the counter,
 * one keyed in by hand, one imported from a spreadsheet. Null means "not
 * through a shop of ours", which is a real and common answer rather than
 * missing data. Nothing is backfilled — existing rows keep the channel and
 * reference they already had, and a guess about which shop they came from
 * would be exactly that.
 *
 * nullOnDelete: deleting a storefront must not delete its history. The orders
 * stay, their revenue stays counted, and they simply stop naming a shop that
 * no longer exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('storefront_id')
                ->nullable()
                ->after('channel')
                ->constrained('storefronts')
                ->nullOnDelete();

            // The question this column exists to answer is always asked for
            // one business over a span of days — "how did the Dubai shop do
            // last month" — so the index leads with the business and carries
            // the date, matching the shape of every other order aggregate.
            $table->index(['business_id', 'storefront_id', 'ordered_on'], 'orders_business_storefront_date_index');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_business_storefront_date_index');
            $table->dropConstrainedForeignId('storefront_id');
        });
    }
};
