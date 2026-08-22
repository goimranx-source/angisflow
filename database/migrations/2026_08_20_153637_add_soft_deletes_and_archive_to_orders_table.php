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
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('cancelled_at');
            $table->softDeletes()->after('updated_at');
            
            // Add indexes for performance
            $table->index('archived_at');
            $table->index('deleted_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['archived_at']);
            $table->dropIndex(['deleted_at']);
            $table->dropColumn(['archived_at', 'deleted_at']);
        });
    }
};
