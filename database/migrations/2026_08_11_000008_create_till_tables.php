<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Selling over a counter.
 *
 * ── A till sale is an order; it does not get its own table ───────────────────
 *
 * The temptation is a `pos_sales` table, because a counter sale feels like a
 * different thing: no delivery address, no waiting, paid before the customer
 * leaves. It is not a different thing. It has a customer, lines, stock leaving,
 * revenue, tax and a payment — the same seven facts, and duplicating them means
 * two definitions of "what did we sell today" that will not agree by the end of
 * the first month a refund crosses between them.
 *
 * So a till sale is an Order with channel = 'pos', confirmed, fulfilled and
 * paid in one call. What is genuinely new is everything below.
 *
 * ── What a till actually needs that an order does not ────────────────────────
 *
 * A cash drawer. Money physically sits in it, somebody counts it at the end of
 * the day, and the count rarely matches exactly. That difference is a real
 * figure a business needs — it is the difference between a mistake and theft
 * being visible or not — and it has nowhere to live in an order.
 *
 * So a session opens with a float, accumulates sales, and closes with a count.
 * Expected minus counted is the variance, and it posts to the books rather than
 * being written on a scrap of paper.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('till_sessions', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_location_id')->constrained()->restrictOnDelete();

            // Which physical till. One shop may have three, and "which drawer
            // is short" is the entire point of counting them separately.
            $table->string('till_code', 20)->default('T1');
            $table->string('number', 40);

            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();

            $table->char('currency', 3);
            // What was in the drawer at the start.
            $table->unsignedBigInteger('opening_float_minor')->default(0);
            // Cash taken and cash paid out during the shift, kept apart so a
            // refund does not silently look like a shortfall.
            $table->unsignedBigInteger('cash_sales_minor')->default(0);
            $table->unsignedBigInteger('cash_refunds_minor')->default(0);
            // Money removed to the safe mid-shift, and money added.
            $table->unsignedBigInteger('cash_in_minor')->default(0);
            $table->unsignedBigInteger('cash_out_minor')->default(0);
            // Everything that did not touch the drawer — card, mobile, credit.
            $table->unsignedBigInteger('other_tenders_minor')->default(0);

            $table->unsignedBigInteger('counted_minor')->nullable();
            // Signed: positive is over, negative is short. Nullable until the
            // drawer has actually been counted, because zero variance and
            // "nobody counted" are very different states.
            $table->bigInteger('variance_minor')->nullable();

            $table->string('status', 12)->default('open'); // open | closed
            $table->text('notes')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'number']);
            $table->index(['business_id', 'status']);
            $table->index(['stock_location_id', 'till_code', 'status'], 'till_open_idx');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('till_session_id')->nullable()->after('stock_location_id')
                ->constrained()->nullOnDelete();

            // Sent by the till with every sale. A counter that loses its
            // connection mid-sale retries, and without this the retry becomes a
            // second sale with a second lot of stock leaving. Unique per
            // business, so the retry finds the original and returns it instead.
            $table->string('idempotency_key', 64)->nullable()->after('external_ref');

            $table->unique(['business_id', 'idempotency_key'], 'order_idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique('order_idempotency_unique');
            $table->dropConstrainedForeignId('till_session_id');
            $table->dropColumn('idempotency_key');
        });

        Schema::dropIfExists('till_sessions');
    }
};
