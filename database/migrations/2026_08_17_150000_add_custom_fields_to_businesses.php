<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fields a business added beyond the ones this tool ships with.
 *
 * Named once per business and reused across every shop it connects, so three
 * shops spelling the same fact differently all point at one field. Null means
 * none defined — see CustomFields.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->json('custom_fields')->nullable()->after('order_statuses');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn('custom_fields');
        });
    }
};
