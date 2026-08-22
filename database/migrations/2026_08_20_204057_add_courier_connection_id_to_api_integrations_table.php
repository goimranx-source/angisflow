<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('api_integrations', function (Blueprint $table) {
            $table->foreignId('courier_connection_id')->nullable()->after('storefront_id')->constrained('courier_connections')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('api_integrations', function (Blueprint $table) {
            $table->dropForeign(['courier_connection_id']);
            $table->dropColumn('courier_connection_id');
        });
    }
};
