<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Services and Rate Cards.
 *
 * For businesses that sell time, expertise, or non-physical deliverables.
 * Services can be priced by hour, project, or custom units.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Service Categories
        Schema::create('service_categories', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('service_categories')->nullOnDelete();

            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['business_id', 'slug']);
            $table->index(['business_id', 'is_active', 'sort_order']);
        });

        // Services
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('service_categories')->nullOnDelete();

            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->text('summary')->nullable();
            
            $table->string('pricing_model', 20)->default('fixed'); // fixed, hourly, daily, custom
            $table->string('currency', 3)->default('USD');
            $table->unsignedBigInteger('price_minor')->default(0);
            $table->string('unit', 40)->nullable(); // hour, day, project, session, etc.
            
            $table->unsignedSmallInteger('duration_minutes')->nullable(); // Standard duration
            $table->boolean('is_taxable')->default(true);
            $table->decimal('tax_rate', 5, 2)->default(0);
            
            $table->boolean('requires_booking')->default(false);
            $table->boolean('is_active')->default(true);

            // Accounting
            $table->foreignId('revenue_account_id')->nullable()->constrained('ledger_accounts');

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['business_id', 'slug']);
            $table->index(['business_id', 'is_active']);
            $table->index('category_id');
        });

        // Rate Cards (pricing tiers for services)
        Schema::create('rate_cards', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['business_id', 'is_active']);
        });

        // Rate Card Lines
        Schema::create('rate_card_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rate_card_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();

            $table->string('currency', 3)->default('USD');
            $table->unsignedBigInteger('price_minor');
            $table->unsignedInteger('min_quantity')->default(1);
            $table->unsignedInteger('max_quantity')->nullable();

            $table->timestamps();

            $table->index(['rate_card_id', 'service_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rate_card_lines');
        Schema::dropIfExists('rate_cards');
        Schema::dropIfExists('services');
        Schema::dropIfExists('service_categories');
    }
};
