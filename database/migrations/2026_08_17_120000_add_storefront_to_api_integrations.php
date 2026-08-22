<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which shop of ours a connection stands behind.
 *
 * ── The join that makes a multi-shop business work ───────────────────────────
 *
 * A storefront is the shop as we hold it — its name, its layout, the record
 * somebody manages from /storefronts. An integration is the live link to the
 * actual site out on the internet. They are different things and either can
 * exist without the other: a shop can be set up here before it is connected to
 * anything, and a marketplace feed can be connected without any page of ours
 * behind it.
 *
 * Joining them is what lets an order arriving over a connection be attributed
 * to a shop — which, with orders.storefront_id already in place, is what
 * finally answers "how did the Dubai shop do this month" for a business running
 * three of them.
 *
 * Nullable for the two cases above. nullOnDelete so removing a storefront
 * record breaks the association rather than destroying a working connection
 * and its credentials along with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_integrations', function (Blueprint $table) {
            $table->foreignId('storefront_id')
                ->nullable()
                ->after('business_id')
                ->constrained('storefronts')
                ->nullOnDelete();

            $table->index(['business_id', 'is_active'], 'api_integrations_business_active_index');
        });
    }

    public function down(): void
    {
        Schema::table('api_integrations', function (Blueprint $table) {
            $table->dropIndex('api_integrations_business_active_index');
            $table->dropConstrainedForeignId('storefront_id');
        });
    }
};
