<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where the stock is, how much there is, and how it got there.
 *
 * ── Movements are the truth; levels are a cache that can be checked ──────────
 *
 * The first Prism kept quantity_on_hand as a column on the product and updated
 * it as things happened. That is the same defect as a stored ledger balance: the
 * day it disagrees with the movements beneath it, every stock figure is wrong
 * and nothing says so.
 *
 * The ledger's answer was to have no stored balance at all. Stock cannot quite
 * do that, for two reasons. It is read on every product page, every order line
 * and every barcode scan, and — the real one — a *reservation* is not derivable
 * from movements. Nothing has moved when an order is placed; a promise has been
 * made. That promise has to live somewhere.
 *
 * So stock_levels exists, holding on_hand and reserved per variant per place,
 * written inside the same transaction as the movement that changed it. What
 * makes it honest rather than another stored balance is that on_hand is
 * reproducible: it is the sum of the movements, and StockService::recalculate()
 * rebuilds it and reports any drift instead of assuming there is none.
 *
 * ── Quantities are decimal, not minor units ──────────────────────────────────
 *
 * Money gets integers because a fraction of a poisha does not exist. Stock is
 * genuinely fractional and in units that vary — 2.5 kg, 0.75 litres, 1000 g of
 * a 1 kg bag — so DECIMAL(18,4) is the right shape here. It is exact in the
 * database, and the arithmetic is addition rather than the multiply-and-round
 * that makes floats dangerous with money.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Places stock can be ─────────────────────────────────────────────
        Schema::create('stock_locations', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('code', 20);
            // warehouse | shop | van | supplier | customer | transit
            // The last three matter: goods in a courier's van are still yours
            // and still an asset, and a system with nowhere to put them either
            // loses them or pretends they have already been sold.
            $table->string('kind', 20)->default('warehouse');

            $table->string('address')->nullable();
            $table->char('country', 2)->nullable();

            // Whether what is here can be promised to a customer. A returns
            // quarantine bay holds real stock that must not be sold yet.
            $table->boolean('is_sellable')->default(true);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['business_id', 'code']);
            $table->index(['business_id', 'is_active']);
        });

        // ── Batches ─────────────────────────────────────────────────────────
        //
        // A batch is a quantity that arrived together at one cost, optionally
        // with an expiry and the supplier's own lot number. Costing and recall
        // both need it: "what did the units we sold actually cost" and "which
        // customers got lot 4471" are unanswerable without one.
        Schema::create('stock_batches', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();

            $table->string('batch_number', 60);
            $table->string('supplier_lot', 60)->nullable();
            $table->date('received_on');
            $table->date('expires_on')->nullable();

            $table->decimal('quantity_received', 18, 4)->default(0);
            // Kept per batch and decremented as it is consumed, so choosing
            // which batch to draw from does not mean summing every movement
            // against every batch first.
            $table->decimal('quantity_remaining', 18, 4)->default(0);

            // What one unit of this batch cost, landed — including carriage and
            // duty, which is why it is per batch and not per variant. The same
            // shirt bought twice cost two different amounts.
            $table->unsignedBigInteger('unit_cost_minor')->default(0);
            $table->char('currency', 3);

            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('bill_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['business_id', 'product_variant_id', 'batch_number']);
            // The index that makes FEFO cheap: soonest to expire, oldest first.
            $table->index(['product_variant_id', 'expires_on', 'received_on'], 'batch_fefo_idx');
        });

        // ── Movements: the source of truth ──────────────────────────────────
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_location_id')->constrained()->restrictOnDelete();
            $table->foreignId('stock_batch_id')->nullable()->constrained()->nullOnDelete();

            // receipt | issue | adjustment | transfer_in | transfer_out
            // | return_in | return_out | write_off | opening | production_in
            // | production_out
            $table->string('kind', 20);

            // Signed: positive brings stock in, negative takes it out. One
            // column rather than a quantity plus a direction flag, because the
            // flag is the thing somebody forgets and then the sum is wrong in a
            // way that looks plausible.
            $table->decimal('quantity', 18, 4);

            // What this movement was worth, so stock value is a sum over the
            // same rows as stock quantity rather than a separate calculation
            // that can disagree with it.
            $table->unsignedBigInteger('unit_cost_minor')->default(0);
            $table->char('currency', 3);

            $table->date('moved_on');
            $table->string('reason')->nullable();

            // What caused it — an order, a bill, a stocktake, a production run.
            $table->string('subject_type', 60)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'product_variant_id', 'moved_on'], 'movement_variant_date_idx');
            $table->index(['stock_location_id', 'product_variant_id'], 'movement_location_variant_idx');
            $table->index(['subject_type', 'subject_id']);
        });

        // ── Levels: the cache, rebuildable from the movements ───────────────
        Schema::create('stock_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_location_id')->constrained()->cascadeOnDelete();

            $table->decimal('on_hand', 18, 4)->default(0);
            // Promised to somebody but not yet gone. Not derivable from
            // movements — nothing has moved — which is the reason this table
            // exists at all.
            $table->decimal('reserved', 18, 4)->default(0);

            $table->decimal('reorder_level', 18, 4)->nullable();
            $table->decimal('reorder_quantity', 18, 4)->nullable();
            $table->timestamp('counted_at')->nullable();
            $table->timestamps();

            $table->unique(['product_variant_id', 'stock_location_id'], 'level_variant_location_unique');
            $table->index(['business_id', 'product_variant_id']);
        });

        // ── Reservations ────────────────────────────────────────────────────
        //
        // stock_levels.reserved is a number; this says who holds it and why.
        // Without the detail there is no way to release a specific order's
        // claim, and a reservation nobody can release is stock permanently
        // unsellable.
        Schema::create('stock_reservations', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_location_id')->constrained()->cascadeOnDelete();

            $table->decimal('quantity', 18, 4);
            // held | released | consumed — kept rather than deleted, so "why
            // was this not available on Tuesday" has an answer.
            $table->string('status', 12)->default('held');

            $table->string('subject_type', 60)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            // Web baskets and quotes should not hold stock for ever. Null means
            // it is held until something releases it deliberately.
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'status', 'expires_at']);
            $table->index(['subject_type', 'subject_id']);
            $table->index(['product_variant_id', 'stock_location_id', 'status'], 'reservation_variant_loc_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_reservations');
        Schema::dropIfExists('stock_levels');
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('stock_batches');
        Schema::dropIfExists('stock_locations');
    }
};
