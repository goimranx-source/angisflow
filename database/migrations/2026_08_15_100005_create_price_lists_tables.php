<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Price Lists.
 *
 * Different pricing for different customer segments, channels, or regions.
 * B2B wholesalers, VIP customers, or seasonal promotions.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Price Lists
        Schema::create('price_lists', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->text('description')->nullable();
            $table->string('currency', 3)->default('USD');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();
            
            $table->string('applies_to', 20)->default('all'); // all, customer_segment, channel, region
            $table->unsignedSmallInteger('priority')->default(0); // Higher priority wins

            $table->timestamps();
            $table->softDeletes();

            $table->index(['business_id', 'is_active', 'priority']);
        });

        // Price List Items
        Schema::create('price_list_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('price_list_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->cascadeOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('pricing_method', 20)->default('fixed'); // fixed, markup, discount
            $table->unsignedBigInteger('price_minor')->nullable(); // For fixed pricing
            $table->decimal('markup_percent', 8, 4)->nullable();
            $table->decimal('discount_percent', 8, 4)->nullable();
            
            $table->unsignedInteger('min_quantity')->default(1);
            $table->unsignedInteger('max_quantity')->nullable();

            $table->timestamps();

            $table->index(['price_list_id', 'variant_id']);
            $table->index(['price_list_id', 'service_id']);
        });

        // Customer - Price List assignments
        Schema::create('customer_price_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('price_list_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('priority')->default(0);

            $table->timestamps();

            $table->unique(['customer_id', 'price_list_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_price_lists');
        Schema::dropIfExists('price_list_items');
        Schema::dropIfExists('price_lists');
    }
};
