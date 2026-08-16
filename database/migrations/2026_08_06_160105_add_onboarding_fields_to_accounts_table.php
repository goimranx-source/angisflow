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
        Schema::table('accounts', function (Blueprint $table) {
            $table->json('onboarding_steps')->nullable()->after('trial_ends_at');
            $table->boolean('onboarding_completed')->default(false)->after('onboarding_steps');
            $table->timestamp('onboarding_completed_at')->nullable()->after('onboarding_completed');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn(['onboarding_steps', 'onboarding_completed', 'onboarding_completed_at']);
        });
    }
};
