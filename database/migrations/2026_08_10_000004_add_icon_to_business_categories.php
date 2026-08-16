<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A mark for each category, so the picker can be looked at rather than read.
 *
 * Stored rather than mapped in the client for the same reason the rest of the
 * catalogue moved into tables: a category added by an operator later would
 * otherwise render as a blank square until somebody remembered to ship a
 * matching entry in a front-end constant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_categories', function (Blueprint $table) {
            $table->string('icon', 40)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('business_categories', function (Blueprint $table) {
            $table->dropColumn('icon');
        });
    }
};
