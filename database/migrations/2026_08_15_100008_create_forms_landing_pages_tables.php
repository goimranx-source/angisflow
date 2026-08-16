<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Forms and Landing Pages.
 *
 * Build custom forms for lead capture, surveys, registrations. Create landing
 * pages with tracking and conversion analytics.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Forms
        Schema::create('forms', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('type', 40)->default('lead_capture'); // lead_capture, contact, survey, registration
            
            $table->json('fields'); // Field definitions
            $table->json('settings')->nullable(); // Notification, redirect, etc.
            
            $table->string('submit_button_text')->default('Submit');
            $table->text('success_message')->nullable();
            $table->string('redirect_url')->nullable();
            
            $table->boolean('is_active')->default(true);
            $table->boolean('require_email')->default(true);
            $table->unsignedInteger('submission_count')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['business_id', 'is_active']);
            $table->index('slug');
        });

        // Form Submissions
        Schema::create('form_submissions', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('form_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained();

            $table->json('data'); // Submitted field values
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->string('referrer')->nullable();
            
            $table->string('status', 20)->default('new'); // new, contacted, converted, spam
            
            $table->timestamps();

            $table->index(['form_id', 'created_at']);
            $table->index('customer_id');
        });

        // Landing Pages
        Schema::create('landing_pages', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('form_id')->nullable()->constrained();

            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            
            $table->string('template', 40)->default('default');
            $table->json('content'); // Page sections and content
            $table->json('styles')->nullable(); // Custom CSS/theme
            $table->json('meta')->nullable(); // SEO meta tags
            
            $table->string('headline')->nullable();
            $table->text('subheadline')->nullable();
            $table->string('cta_text')->default('Get Started');
            $table->string('cta_url')->nullable();
            
            $table->boolean('is_published')->default(false);
            $table->unsignedInteger('view_count')->default(0);
            $table->unsignedInteger('conversion_count')->default(0);

            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['business_id', 'is_published']);
            $table->index('slug');
        });

        // Landing Page Analytics
        Schema::create('landing_page_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('landing_page_id')->constrained()->cascadeOnDelete();
            
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->string('referrer')->nullable();
            $table->string('utm_source')->nullable();
            $table->string('utm_medium')->nullable();
            $table->string('utm_campaign')->nullable();
            
            $table->boolean('converted')->default(false);
            $table->timestamp('converted_at')->nullable();
            
            $table->timestamp('visited_at');

            $table->index(['landing_page_id', 'visited_at']);
            $table->index('converted');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landing_page_visits');
        Schema::dropIfExists('landing_pages');
        Schema::dropIfExists('form_submissions');
        Schema::dropIfExists('forms');
    }
};
