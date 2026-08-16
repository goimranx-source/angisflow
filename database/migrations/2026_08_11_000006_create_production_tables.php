<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Making things out of other things.
 *
 * ── Why a bill of materials is versioned ─────────────────────────────────────
 *
 * A recipe changes: the supplier of a component switches, a step is dropped, a
 * quantity is trimmed to reduce waste. If the BOM is edited in place, every
 * production run ever made from it silently restates — last quarter's cost of
 * goods sold moves because somebody adjusted a recipe this morning, and there is
 * nothing to point at that explains why.
 *
 * So a BOM has a version, and a production order snapshots what it consumed at
 * the moment it was released. The recipe is a plan; the order is a record. They
 * are allowed to disagree, and when they do the difference is the interesting
 * number — that is what a variance report is.
 *
 * ── Raw materials are not a separate kind of thing ───────────────────────────
 *
 * There is no raw_materials table. A raw material is a product variant that
 * happens to be consumed rather than sold, and half of them are both — flour is
 * an ingredient to a bakery and stock to a wholesaler, often in the same
 * business. Splitting them means two catalogues, two stock systems, and a
 * transfer between them every time something is reclassified.
 *
 * What does differ is which inventory account they sit in, and that is already
 * on the product: 1410 Raw Materials, 1420 Packaging, 1400 Finished Goods. The
 * ledger tells them apart. The catalogue does not need to.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Recipes ─────────────────────────────────────────────────────────
        Schema::create('bills_of_materials', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            // What this recipe makes.
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->unsignedSmallInteger('version')->default(1);

            // How much one run of this recipe yields. Not always one: a dough
            // recipe makes forty loaves, and dividing by forty at the end is
            // how a per-loaf cost is arrived at honestly.
            $table->decimal('output_quantity', 18, 4)->default(1);

            // Where the labour and overhead of a run are posted. Nullable
            // because plenty of assembly has neither.
            $table->foreignId('labour_account_id')->nullable()->constrained('ledger_accounts')->nullOnDelete();
            $table->unsignedBigInteger('labour_cost_minor')->default(0);
            $table->unsignedBigInteger('overhead_cost_minor')->default(0);
            $table->char('currency', 3);

            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // One live recipe per product per version. A second active version
            // for the same output is ambiguous — a production order would not
            // know which it was following.
            $table->unique(['product_variant_id', 'version'], 'bom_variant_version_unique');
            $table->index(['business_id', 'is_active']);
        });

        Schema::create('bom_components', function (Blueprint $table) {
            $table->id();
            // Named explicitly: Laravel would infer `bill_of_materials` from the
            // column, and the table is `bills_of_materials` — the plural belongs
            // on the bill, not the materials.
            $table->foreignId('bill_of_materials_id')->constrained('bills_of_materials')->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();

            $table->unsignedSmallInteger('line_no')->default(1);
            $table->decimal('quantity', 18, 4);

            // What is lost in the making — offcuts, evaporation, breakage.
            // Recorded as a percentage rather than baked into the quantity so
            // the recipe still says what the product actually contains, and the
            // waste is a number somebody can try to reduce.
            $table->decimal('scrap_percent', 7, 4)->default(0);

            // A component that may be left out without the output being a
            // different product — a garnish, an optional fitting.
            $table->boolean('is_optional')->default(false);
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->index(['bill_of_materials_id', 'line_no']);
            // The index behind "what do we make that uses this?", which is the
            // first question asked when a supplier fails or a batch is recalled.
            $table->index('product_variant_id');
        });

        // ── Production orders ───────────────────────────────────────────────
        Schema::create('production_orders', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bill_of_materials_id')->nullable()->constrained('bills_of_materials')->nullOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->foreignId('stock_location_id')->constrained()->restrictOnDelete();

            $table->string('number', 40);

            // planned    intended, consumes nothing, reserves nothing
            // released   components reserved; the recipe is now snapshotted
            // started    materials consumed and gone from stock
            // completed  output received into stock at its computed cost
            // cancelled  reservations given back; nothing consumed
            $table->string('status', 12)->default('planned');

            $table->decimal('quantity_planned', 18, 4);
            $table->decimal('quantity_produced', 18, 4)->default(0);
            // Made but not good. Kept apart from the yield because a run that
            // produced ninety good and ten scrapped cost the same as one that
            // produced a hundred, and the per-unit cost of the good ones has to
            // reflect that.
            $table->decimal('quantity_scrapped', 18, 4)->default(0);

            $table->date('planned_for')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            // Totals as actually incurred, filled in as the run proceeds.
            $table->unsignedBigInteger('material_cost_minor')->default(0);
            $table->unsignedBigInteger('labour_cost_minor')->default(0);
            $table->unsignedBigInteger('overhead_cost_minor')->default(0);
            $table->unsignedBigInteger('unit_cost_minor')->default(0);
            $table->char('currency', 3);

            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'number']);
            $table->index(['business_id', 'status', 'planned_for']);
            $table->index(['product_variant_id', 'status']);
        });

        // The snapshot: what this run was supposed to use, and what it did.
        Schema::create('production_order_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();

            $table->unsignedSmallInteger('line_no')->default(1);
            // Copied from the BOM at release, and never re-read from it after —
            // this is the record of what the plan was at the time.
            $table->decimal('quantity_planned', 18, 4);
            $table->decimal('quantity_consumed', 18, 4)->default(0);
            $table->decimal('scrap_percent', 7, 4)->default(0);
            $table->boolean('is_optional')->default(false);

            // What the units actually taken cost, from the batches they came
            // from — not the standard cost, which is a plan and not a fact.
            $table->unsignedBigInteger('cost_minor')->default(0);
            $table->char('currency', 3);

            $table->foreignId('stock_reservation_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['production_order_id', 'line_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_order_components');
        Schema::dropIfExists('production_orders');
        Schema::dropIfExists('bom_components');
        Schema::dropIfExists('bills_of_materials');
    }
};
