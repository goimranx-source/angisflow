<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payment Links.
 *
 * Generate shareable payment links for one-time or recurring payments.
 * No full online store needed — just send a link and get paid.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Payment Links
        Schema::create('payment_links', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained();

            $table->string('name'); // Internal name
            $table->string('slug')->unique(); // URL-friendly identifier
            $table->text('description')->nullable();
            
            $table->string('type', 20)->default('one_time'); // one_time, recurring, donation
            $table->string('currency', 3)->default('USD');
            $table->unsignedBigInteger('amount_minor')->nullable(); // Null = customer decides
            $table->boolean('amount_is_fixed')->default(true);
            $table->unsignedBigInteger('min_amount_minor')->nullable();
            $table->unsignedBigInteger('max_amount_minor')->nullable();
            
            $table->string('payment_method', 40)->default('any'); // any, card, bank_transfer, etc.
            $table->unsignedInteger('max_uses')->nullable(); // Null = unlimited
            $table->unsignedInteger('use_count')->default(0);
            
            $table->boolean('collect_shipping')->default(false);
            $table->boolean('collect_phone')->default(false);
            $table->json('custom_fields')->nullable();
            
            $table->string('success_url')->nullable();
            $table->text('success_message')->nullable();
            
            $table->boolean('is_active')->default(true);
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['business_id', 'is_active']);
            $table->index('slug');
        });

        // Payment Link Items (for multi-item links)
        Schema::create('payment_link_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_link_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants');
            $table->foreignId('service_id')->nullable()->constrained();

            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('price_minor');
            $table->unsignedInteger('quantity')->default(1);
            $table->boolean('quantity_is_fixed')->default(true);

            $table->timestamps();

            $table->index('payment_link_id');
        });

        // Payment Link Transactions
        Schema::create('payment_link_transactions', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('payment_link_id')->constrained();
            $table->foreignId('customer_id')->nullable()->constrained();

            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('name')->nullable();
            
            $table->string('currency', 3);
            $table->unsignedBigInteger('amount_minor');
            $table->string('status', 20)->default('pending'); // pending, completed, failed, refunded
            
            $table->json('payment_details')->nullable();
            $table->json('custom_data')->nullable();
            
            $table->foreignId('transaction_id')->nullable()->constrained();
            $table->foreignId('order_id')->nullable()->constrained();
            
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index(['payment_link_id', 'status']);
            $table->index('customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_link_transactions');
        Schema::dropIfExists('payment_link_items');
        Schema::dropIfExists('payment_links');
    }
};
