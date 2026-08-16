<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the business sells.
 *
 * ── Every product has variants, including the ones that do not ───────────────
 *
 * The first Prism had no variants at all: a product was a SKU, so a shirt in
 * three sizes was three unrelated products that happened to have similar names.
 * Nothing tied them together, so "how many shirts did we sell" could not be
 * asked, and changing the description meant changing it three times.
 *
 * The obvious fix is a `variants` table used only by products that have them,
 * and it is a trap. Every query afterwards becomes "the price, unless it has
 * variants, in which case the variant's price", and every screen grows a branch
 * for each case. The branches get out of step, and the simple case is the one
 * that quietly breaks because nobody tests it.
 *
 * So a product always has at least one variant, and stock, price, barcode and
 * SKU live only on the variant. A simple product is one with a single variant
 * carrying no options. There is exactly one code path, and "does this have
 * variants" stops being a question anything needs to ask.
 *
 * ── The product is not the listing ───────────────────────────────────────────
 *
 * The old schema put store_id on the product, which meant the same shirt sold
 * in two places was two products with two separate stock figures for one pile
 * of shirts. A product here is storefront-agnostic; how it appears on a
 * particular storefront is a listing, and listings arrive with storefronts.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Categories ──────────────────────────────────────────────────────
        Schema::create('product_categories', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('product_categories')->nullOnDelete();
            $table->string('name');
            $table->string('slug', 120);
            $table->string('description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['business_id', 'slug']);
            $table->index(['business_id', 'parent_id', 'sort_order']);
        });

        // ── Products ────────────────────────────────────────────────────────
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('product_categories')->nullOnDelete();

            $table->string('name');
            $table->string('slug', 160);
            $table->text('description')->nullable();
            $table->string('summary')->nullable();
            $table->string('brand', 120)->nullable();

            // goods    something physical that is counted and moved
            // service  time or work; no stock, no shipping
            // digital  delivered as a file or a licence; sold but not counted
            // bundle   made of other products; its stock is theirs
            $table->string('kind', 12)->default('goods');

            // Which ledger accounts this product's sales and costs belong to.
            // Per product, because "how much did we make on furniture" is a
            // question about accounts and it cannot be answered if everything
            // posts to one Sales line.
            $table->foreignId('revenue_account_id')->nullable()->constrained('ledger_accounts')->nullOnDelete();
            $table->foreignId('cogs_account_id')->nullable()->constrained('ledger_accounts')->nullOnDelete();
            $table->foreignId('inventory_account_id')->nullable()->constrained('ledger_accounts')->nullOnDelete();

            $table->decimal('tax_rate', 7, 4)->nullable();
            $table->boolean('is_stocked')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['business_id', 'slug']);
            $table->index(['business_id', 'is_active', 'name']);
            $table->index(['business_id', 'category_id']);
        });

        // ── Options: the axes a product varies along ────────────────────────
        Schema::create('product_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);          // Size, Colour, Material
            $table->unsignedSmallInteger('position')->default(1);
            $table->timestamps();

            $table->unique(['product_id', 'name']);
        });

        Schema::create('product_option_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_option_id')->constrained()->cascadeOnDelete();
            $table->string('value', 80);         // Small, Red, Cotton
            $table->unsignedSmallInteger('position')->default(1);
            $table->timestamps();

            $table->unique(['product_option_id', 'value']);
        });

        // ── Variants: the thing that is actually stocked and sold ───────────
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            $table->string('sku', 60);
            $table->string('barcode', 40)->nullable();
            // "Small / Red", built from the option values. Stored because it is
            // read on every order line and every stock report, and rebuilding
            // it means loading two more tables to print a label.
            $table->string('name')->nullable();

            $table->unsignedBigInteger('price_minor')->default(0);
            $table->unsignedBigInteger('compare_at_minor')->nullable();
            // What it costs us. Kept here as the current standard cost; what a
            // specific batch actually cost belongs to stock, which knows about
            // batches and landed costs.
            $table->unsignedBigInteger('cost_minor')->default(0);
            $table->char('currency', 3);

            $table->decimal('weight_grams', 12, 3)->nullable();
            $table->string('unit', 20)->default('pcs');
            // Sold in multiples: a case of 24, a 500g bag. Separate from weight
            // because "how many units in the box" and "how heavy is it" are
            // different questions and shipping needs both.
            $table->decimal('pack_quantity', 14, 4)->nullable();

            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('position')->default(1);
            $table->timestamps();
            $table->softDeletes();

            // A SKU identifies one thing in one book. Two businesses in a group
            // may each have their own SKU-001 and they are different things.
            $table->unique(['business_id', 'sku']);
            $table->index(['business_id', 'barcode']);
            $table->index(['product_id', 'position']);
        });

        // Which option values a variant is. Small + Red for one row.
        Schema::create('variant_option_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_option_value_id')->constrained()->cascadeOnDelete();

            $table->unique(['product_variant_id', 'product_option_value_id'], 'variant_value_unique');
            $table->index('product_option_value_id');
        });

        // ── Media ───────────────────────────────────────────────────────────
        Schema::create('product_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            // Null means it belongs to the product as a whole; set means this
            // picture is of the red one specifically.
            $table->foreignId('product_variant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('media_item_id')->constrained('media_items')->cascadeOnDelete();
            $table->string('alt')->nullable();
            $table->unsignedSmallInteger('position')->default(1);
            $table->timestamps();

            $table->index(['product_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_media');
        Schema::dropIfExists('variant_option_values');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('product_option_values');
        Schema::dropIfExists('product_options');
        Schema::dropIfExists('products');
        Schema::dropIfExists('product_categories');
    }
};
