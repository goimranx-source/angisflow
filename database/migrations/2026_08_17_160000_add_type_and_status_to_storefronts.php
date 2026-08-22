<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What kind of shop this is, and what state it is in.
 *
 * ── Why these are real columns ───────────────────────────────────────────────
 *
 * The list page has been filtering on both since it was written, and both were
 * invented on the way out: type was hardcoded 'main' for every shop, and status
 * was derived from is_active — so the filter offered four states a shop could
 * never be in, and every shop claimed to be the main one.
 *
 * ── Why status is not just is_active ─────────────────────────────────────────
 *
 * Because "switched off" and "temporarily down for work" are different things to
 * the person reading the list, and "not finished yet" is a third. is_active
 * stays as the flag everything else already reads; status is what a person sets
 * and sees.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('storefronts', function (Blueprint $table) {
            // main | online | brand | regional | category | pos
            $table->string('type', 20)->default('main')->after('slug');

            // active | inactive | maintenance | draft
            $table->string('status', 20)->default('active')->after('type');

            // Both are filtered on, and a business with several shops filters
            // constantly.
            $table->index(['business_id', 'type']);
            $table->index(['business_id', 'status']);
        });

        // Existing shops keep working: whatever is_active said becomes the
        // status, rather than every row silently claiming to be active.
        Schema::hasTable('storefronts') && \Illuminate\Support\Facades\DB::table('storefronts')
            ->where('is_active', false)
            ->update(['status' => 'inactive']);
    }

    public function down(): void
    {
        Schema::table('storefronts', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'type']);
            $table->dropIndex(['business_id', 'status']);
            $table->dropColumn(['type', 'status']);
        });
    }
};
