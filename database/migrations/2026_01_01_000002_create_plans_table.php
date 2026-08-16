<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a subscriber can buy.
 *
 * The one table with no account_id: plans are the product, not anybody's data.
 * Small, rarely written, read through cache — it never appears in a hot query.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();

            $table->string('code', 40)->unique();
            $table->string('name');
            $table->string('description')->nullable();

            $table->bigInteger('price_minor')->default(0);
            $table->char('currency', 3)->default('BDT');
            $table->string('interval', 12)->default('monthly');
            $table->unsignedSmallInteger('trial_days')->default(14);

            // Limits and features as JSON rather than a column each. A new
            // limit is then a deploy of the code enforcing it; a column per
            // limit is an ALTER TABLE every time pricing changes, and pricing
            // changes more often than anyone plans for.
            $table->json('limits')->nullable();
            $table->json('features')->nullable();

            $table->boolean('is_public')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['is_public', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
