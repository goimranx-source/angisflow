<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Goods coming back.
 *
 * ── Two different events that look like one ──────────────────────────────────
 *
 * A parcel that never reached the customer and a parcel the customer sent back
 * are both "a return", and treating them as one thing gets the money wrong in
 * opposite directions.
 *
 *   RTO      Nobody was in, the address was wrong, the customer refused it at
 *            the door. No sale ever completed. On a cash-on-delivery parcel no
 *            money was ever collected — but the courier still charges, often
 *            for both legs. So: reverse the sale, restock, and keep an expense
 *            that bought nothing.
 *
 *   Return   The customer had it, used it or looked at it, and sent it back.
 *            The sale did complete, the money was taken, and it has to go back.
 *            Stock returns in whatever condition it is in, which is frequently
 *            not saleable.
 *
 * One `is_rto` flag rather than two tables, because everything else about them
 * is identical — lines, condition, restocking, the credit note. What differs is
 * which postings run, and that is a branch in one service rather than a
 * duplicate of the whole thing.
 *
 * ── Condition is per line, and it decides where the stock goes ───────────────
 *
 * A three-item return where one is resaleable, one is damaged and one never
 * arrived is ordinary. Recording a single condition for the whole return means
 * either writing off good stock or putting broken stock back on the shelf, and
 * both are found later by a customer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goods_returns', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('shipment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();

            $table->string('number', 40);
            $table->date('returned_on');

            // The distinction the whole file is about.
            $table->boolean('is_rto')->default(false);

            // expected  we know it is coming back
            // received  it is in our hands and has been looked at
            // settled   stock and money both dealt with
            // cancelled it never actually came back
            $table->string('status', 12)->default('expected');

            $table->string('reason', 60)->nullable();   // refused | not_home | wrong_item | damaged | changed_mind | faulty
            $table->text('notes')->nullable();

            $table->char('currency', 3);
            // What the returned goods were sold for, and what is actually being
            // given back. They differ whenever a restocking fee or a delivery
            // charge is kept.
            $table->unsignedBigInteger('goods_minor')->default(0);
            $table->unsignedBigInteger('restocking_fee_minor')->default(0);
            $table->unsignedBigInteger('refund_minor')->default(0);
            // What the returned units had cost us, from the batches that went
            // out. Needed to unwind cost of sales by the right amount rather
            // than by today's standard cost.
            $table->unsignedBigInteger('cost_minor')->default(0);

            // Where returned stock lands. Usually a quarantine bay, which is a
            // location with is_sellable false — see the stock tables.
            $table->foreignId('stock_location_id')->nullable()->constrained()->nullOnDelete();

            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('refund_payment_id')->nullable()->constrained('payments')->nullOnDelete();

            $table->timestamp('received_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'number']);
            $table->index(['business_id', 'status', 'returned_on']);
            $table->index(['business_id', 'is_rto']);
            $table->index('order_id');
        });

        Schema::create('goods_return_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goods_return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_line_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedSmallInteger('line_no')->default(1);
            $table->string('description');
            $table->decimal('quantity', 18, 4);

            // resaleable  back into sellable stock at its original cost
            // damaged     back into stock but not sellable, or written off
            // missing     never arrived; no stock movement, no refund
            $table->string('condition', 12)->default('resaleable');

            $table->unsignedBigInteger('unit_price_minor')->default(0);
            $table->unsignedBigInteger('refund_minor')->default(0);
            $table->unsignedBigInteger('cost_minor')->default(0);
            $table->char('currency', 3);

            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['goods_return_id', 'line_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_return_lines');
        Schema::dropIfExists('goods_returns');
    }
};
