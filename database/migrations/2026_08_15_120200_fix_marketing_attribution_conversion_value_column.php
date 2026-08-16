<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fix Task 34 (Growth) money-as-float.
 *
 * `2026_08_13_000029_create_growth_marketing_tables.php` gave
 * `marketing_attribution` two columns for what `MarketingService` treats as
 * the same figure — the order's value at conversion: `attributed_revenue`
 * (bigint, minor units, correct) and `conversion_value` (decimal(10,2),
 * float-shaped, wrong). Keeping both in different shapes means every report
 * that sums them can silently disagree with itself depending on which column
 * it happened to read. This converts `conversion_value` to the same
 * `*_minor` bigint shape as `attributed_revenue`.
 *
 * No currency column is added here: neither this column nor
 * `attributed_revenue` carries one — both are implicitly the order's own
 * currency, consistent with how the rest of this table already works.
 *
 * The table is empty in every environment this has run against, so this
 * drops and re-adds rather than converting decimal data in place.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_attribution', function (Blueprint $table) {
            $table->dropColumn('conversion_value');
        });

        Schema::table('marketing_attribution', function (Blueprint $table) {
            $table->bigInteger('conversion_value_minor')->default(0)->after('attributed_revenue');
        });
    }

    public function down(): void
    {
        Schema::table('marketing_attribution', function (Blueprint $table) {
            $table->dropColumn('conversion_value_minor');
        });

        Schema::table('marketing_attribution', function (Blueprint $table) {
            $table->decimal('conversion_value', 10, 2)->default(0.00);
        });
    }
};
