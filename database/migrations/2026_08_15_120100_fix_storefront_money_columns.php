<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fix Task 33 (Storefront) money-as-float.
 *
 * `2026_08_12_000028_create_storefront_tables.php` put three money columns on
 * disk as `decimal(10,2)`: `storefronts.minimum_order_amount`,
 * `customer_wishlist_items.price_when_added` and `.target_price`. Every other
 * amount in the system is a bigint of minor units read through the `Money`
 * value object (see `partner_share_lines.amount_minor` for the shape this is
 * matching) — a decimal column invites exactly the float arithmetic
 * `StorefrontService::recalculateCartTotals()` was doing before this fix,
 * which rounds differently than integer minor units and drifts on repeated
 * add/update operations.
 *
 * `storefronts` never had a currency column at all (every other money column
 * in the app is minor-units-plus-currency), so one is added here. The
 * wishlist table already carries `currency` per row, so it is left alone.
 *
 * Both source tables are empty in every environment this migration has run
 * against (task board confirms no storefront or wishlist rows exist yet), so
 * this drops and re-adds rather than converting in place — there is no data
 * to lose, and a straight rename would leave the column named "amount" while
 * holding minor units, which is the exact ambiguity the `_minor` suffix
 * convention exists to prevent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('storefronts', function (Blueprint $table) {
            $table->dropColumn('minimum_order_amount');
        });

        Schema::table('storefronts', function (Blueprint $table) {
            $table->bigInteger('minimum_order_amount_minor')->nullable()->after('show_inventory_levels');
            $table->char('currency', 3)->nullable()->after('minimum_order_amount_minor');
        });

        Schema::table('customer_wishlist_items', function (Blueprint $table) {
            $table->dropColumn(['price_when_added', 'target_price']);
        });

        Schema::table('customer_wishlist_items', function (Blueprint $table) {
            $table->bigInteger('price_when_added_minor')->default(0)->after('variant_name');
            $table->bigInteger('target_price_minor')->nullable()->after('notify_back_in_stock');
        });
    }

    public function down(): void
    {
        Schema::table('storefronts', function (Blueprint $table) {
            $table->dropColumn(['minimum_order_amount_minor', 'currency']);
        });

        Schema::table('storefronts', function (Blueprint $table) {
            $table->decimal('minimum_order_amount', 10, 2)->nullable();
        });

        Schema::table('customer_wishlist_items', function (Blueprint $table) {
            $table->dropColumn(['price_when_added_minor', 'target_price_minor']);
        });

        Schema::table('customer_wishlist_items', function (Blueprint $table) {
            $table->decimal('price_when_added', 10, 2);
            $table->decimal('target_price', 10, 2)->nullable();
        });
    }
};
