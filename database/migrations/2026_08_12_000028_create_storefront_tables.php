<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Storefront, Booking Pages, and Customer Portal Tables
 *
 * Creates the database schema for customer-facing functionality including
 * public storefronts, booking pages, and customer self-service portals.
 *
 * ── Design Philosophy ──────────────────────────────────────────────────────
 *
 * Three distinct but interconnected systems:
 *
 * 1. STOREFRONTS - Public product catalogs with ordering capability
 *    - Each business can have multiple storefronts (different brands, regions)
 *    - Theme customization and branding per storefront
 *    - SEO optimization and domain mapping
 *    - Product collections and featured items
 *
 * 2. BOOKING PAGES - Service booking interfaces
 *    - Build on existing booking infrastructure from Task 28
 *    - Public-facing appointment scheduling
 *    - Resource availability display
 *    - Customer self-service booking management
 *
 * 3. CUSTOMER PORTALS - Account management and order tracking
 *    - Secure customer login and registration
 *    - Order history and tracking
 *    - Profile management and preferences
 *    - Support ticket creation
 *
 * ── Security Model ─────────────────────────────────────────────────────────
 *
 * - Storefronts are public but tied to specific businesses
 * - Customer portals require authentication via customer accounts
 * - Booking pages can be public or require customer login
 * - All customer data properly scoped to prevent cross-business access
 *
 * ── Integration Points ─────────────────────────────────────────────────────
 *
 * - Products from existing product catalog (Task 4)
 * - Orders flow through existing order management (Task 8)
 * - Bookings use existing booking system (Task 28)
 * - Customers integrated with existing customer directory (Task 14)
 * - Chat widget integration for customer support
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Storefronts ─────────────────────────────────────────────────────────
        
        Schema::create('storefronts', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            
            // Basic configuration
            $table->string('name'); // Internal name for management
            $table->string('slug')->unique(); // URL slug: my-store
            $table->string('title'); // Public display title
            $table->text('description')->nullable();
            $table->json('settings'); // Theme, colors, logo, etc.
            
            // Domain and SEO
            $table->string('custom_domain')->nullable(); // shop.example.com
            $table->boolean('ssl_enabled')->default(true);
            $table->json('seo_config')->nullable(); // meta tags, analytics
            
            // Functionality flags
            $table->boolean('is_active')->default(true);
            $table->boolean('allow_guest_checkout')->default(true);
            $table->boolean('require_account')->default(false);
            $table->boolean('show_inventory_levels')->default(false);
            $table->boolean('enable_reviews')->default(true);
            $table->boolean('enable_wishlist')->default(true);
            
            // Business rules
            $table->decimal('minimum_order_amount', 10, 2)->nullable();
            $table->json('shipping_zones')->nullable(); // Geographic restrictions
            $table->json('payment_methods')->nullable(); // Enabled payment options
            $table->json('tax_settings')->nullable(); // Tax display preferences
            
            // Appearance
            $table->string('theme_template')->default('default');
            $table->json('theme_config')->nullable(); // Colors, fonts, layout
            $table->json('header_config')->nullable(); // Navigation, logo
            $table->json('footer_config')->nullable(); // Links, social media
            
            // Performance and content
            $table->boolean('enable_caching')->default(true);
            $table->json('featured_products')->nullable(); // Product IDs to highlight
            $table->json('collections')->nullable(); // Product groupings
            $table->text('announcement_bar')->nullable(); // Promotional messages
            
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['business_id', 'is_active']);
            $table->index(['slug']);
            $table->index(['custom_domain']);
        });

        // ── Storefront Pages ────────────────────────────────────────────────────
        
        Schema::create('storefront_pages', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('storefront_id')->constrained()->cascadeOnDelete();
            
            // Page identification
            $table->string('title');
            $table->string('slug'); // about-us, shipping-policy
            $table->enum('type', ['page', 'policy', 'blog', 'faq'])->default('page');
            
            // Content
            $table->longText('content'); // HTML content
            $table->json('meta_data')->nullable(); // SEO meta tags
            $table->text('excerpt')->nullable(); // Short description
            
            // Publishing
            $table->boolean('is_published')->default(true);
            $table->boolean('show_in_navigation')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamp('published_at')->nullable();
            
            // Template
            $table->string('template')->default('default');
            $table->json('template_data')->nullable(); // Template-specific data
            
            $table->timestamps();
            $table->softDeletes();
            
            $table->unique(['storefront_id', 'slug']);
            $table->index(['storefront_id', 'type', 'is_published']);
        });

        // ── Public Booking Pages ───────────────────────────────────────────────
        
        Schema::create('booking_pages', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            
            // Page configuration
            $table->string('name'); // Internal name
            $table->string('slug')->unique(); // hair-salon-booking
            $table->string('title'); // Public page title
            $table->text('description')->nullable();
            
            // Booking configuration
            $table->json('service_ids'); // Which services are bookable
            $table->json('resource_ids')->nullable(); // Specific resources if any
            $table->integer('booking_window_days')->default(30); // How far ahead
            $table->integer('min_advance_hours')->default(2); // Minimum notice
            $table->json('operating_hours'); // When bookings are available
            
            // Customer requirements
            $table->boolean('require_account')->default(false);
            $table->boolean('require_phone')->default(true);
            $table->boolean('require_email')->default(true);
            $table->json('custom_fields')->nullable(); // Additional form fields
            
            // Appearance and branding
            $table->string('theme_template')->default('default');
            $table->json('theme_config')->nullable(); // Colors, fonts
            $table->json('branding')->nullable(); // Logo, business info
            
            // Notifications
            $table->boolean('send_confirmations')->default(true);
            $table->boolean('send_reminders')->default(true);
            $table->json('notification_settings')->nullable(); // When to send
            
            // Integration
            $table->boolean('enable_chat_widget')->default(true);
            $table->string('chat_widget_id')->nullable();
            $table->json('analytics_config')->nullable(); // Google Analytics, etc.
            
            // Status
            $table->boolean('is_active')->default(true);
            $table->timestamp('published_at')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['business_id', 'is_active']);
            $table->index(['slug']);
        });

        // ── Customer Portal Sessions ────────────────────────────────────────────
        
        Schema::create('customer_portal_sessions', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            
            // Session identification
            $table->string('session_token')->unique(); // Secure random token
            $table->string('device_fingerprint')->nullable(); // Browser fingerprinting
            $table->ipAddress('ip_address');
            $table->text('user_agent')->nullable();
            
            // Session data
            $table->json('preferences')->nullable(); // UI preferences, language
            $table->json('cart_data')->nullable(); // Shopping cart contents
            $table->timestamp('last_activity_at');
            $table->timestamp('expires_at');
            
            // Security
            $table->boolean('is_active')->default(true);
            $table->integer('failed_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
            
            $table->timestamps();
            
            $table->index(['customer_id', 'is_active']);
            $table->index(['session_token']);
            $table->index(['expires_at']);
        });

        // ── Customer Support Tickets ────────────────────────────────────────────
        
        Schema::create('customer_support_tickets', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            
            // Ticket identification
            $table->string('ticket_number')->unique(); // Human-friendly: CS-2024-001
            $table->string('subject');
            $table->longText('description');
            
            // Classification
            $table->enum('category', [
                'order_inquiry', 'product_question', 'billing', 'technical', 
                'complaint', 'compliment', 'return_request', 'other'
            ])->default('other');
            $table->enum('priority', ['low', 'normal', 'high', 'urgent'])->default('normal');
            $table->enum('status', [
                'open', 'in_progress', 'waiting_customer', 'waiting_business', 
                'resolved', 'closed'
            ])->default('open');
            
            // Assignment
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            
            // Related records
            $table->string('related_order_id')->nullable(); // Order public_id
            $table->string('related_booking_id')->nullable(); // Booking public_id
            $table->json('related_products')->nullable(); // Product public_ids
            
            // Resolution
            $table->longText('resolution_notes')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->enum('resolution_type', [
                'resolved', 'duplicate', 'not_reproducible', 'working_as_designed', 
                'cancelled'
            ])->nullable();
            
            // Customer satisfaction
            $table->tinyInteger('satisfaction_rating')->nullable(); // 1-5
            $table->text('satisfaction_feedback')->nullable();
            $table->timestamp('feedback_submitted_at')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['business_id', 'status']);
            $table->index(['customer_id', 'status']);
            $table->index(['assigned_to', 'status']);
            $table->index(['ticket_number']);
        });

        // ── Customer Support Messages ───────────────────────────────────────────
        
        Schema::create('customer_support_messages', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('ticket_id')->constrained('customer_support_tickets')->cascadeOnDelete();
            
            // Message content
            $table->longText('message');
            $table->json('attachments')->nullable(); // File references
            $table->boolean('is_internal')->default(false); // Staff-only notes
            
            // Author (either customer or staff)
            $table->enum('author_type', ['customer', 'staff']);
            $table->foreignId('author_id'); // customer_id or user_id
            $table->string('author_name'); // Display name at time of writing
            $table->string('author_email')->nullable(); // Email at time of writing
            
            // Status
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();
            
            $table->timestamps();
            
            $table->index(['ticket_id', 'created_at']);
            $table->index(['author_type', 'author_id']);
        });

        // ── Customer Wishlist Items ─────────────────────────────────────────────
        
        Schema::create('customer_wishlist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->cascadeOnDelete();
            
            // Item details at time of adding
            $table->string('product_name'); // In case product changes
            $table->string('variant_name')->nullable();
            $table->decimal('price_when_added', 10, 2); // Track price changes
            $table->string('currency', 3);
            
            // Wishlist organization
            $table->string('list_name')->default('default'); // Multiple lists
            $table->integer('sort_order')->default(0);
            $table->text('notes')->nullable(); // Customer notes
            
            // Notifications
            $table->boolean('notify_price_drop')->default(false);
            $table->boolean('notify_back_in_stock')->default(false);
            $table->decimal('target_price', 10, 2)->nullable(); // Alert when below
            
            $table->timestamps();
            
            $table->unique(['customer_id', 'product_id', 'variant_id']);
            $table->index(['customer_id', 'list_name']);
        });

        // ── Product Reviews ─────────────────────────────────────────────────────
        
        Schema::create('product_reviews', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            
            // Review content
            $table->string('title')->nullable();
            $table->longText('review_text')->nullable();
            $table->tinyInteger('rating'); // 1-5 stars
            
            // Verification
            $table->boolean('is_verified_purchase')->default(false);
            $table->string('order_public_id')->nullable(); // Which order this relates to
            $table->timestamp('purchased_at')->nullable();
            
            // Status and moderation
            $table->enum('status', ['pending', 'approved', 'rejected', 'hidden'])->default('pending');
            $table->text('moderation_notes')->nullable();
            $table->foreignId('moderated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('moderated_at')->nullable();
            
            // Customer details (at time of review)
            $table->string('customer_name'); // Display name
            $table->boolean('show_customer_name')->default(true);
            $table->string('customer_location')->nullable(); // City, Country
            
            // Helpfulness voting
            $table->integer('helpful_votes')->default(0);
            $table->integer('total_votes')->default(0);
            
            // Media attachments
            $table->json('images')->nullable(); // Review photos
            $table->json('videos')->nullable(); // Review videos
            
            $table->timestamps();
            $table->softDeletes();
            
            $table->unique(['product_id', 'customer_id', 'order_public_id']);
            $table->index(['business_id', 'status']);
            $table->index(['product_id', 'status', 'rating']);
            $table->index(['customer_id']);
        });

        // ── Review Helpfulness Votes ────────────────────────────────────────────
        
        Schema::create('review_helpfulness_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained('product_reviews')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_helpful'); // true = helpful, false = not helpful
            $table->ipAddress('ip_address'); // Prevent gaming from same IP
            
            $table->timestamps();
            
            $table->unique(['review_id', 'customer_id']);
            $table->index(['review_id', 'is_helpful']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_helpfulness_votes');
        Schema::dropIfExists('product_reviews');
        Schema::dropIfExists('customer_wishlist_items');
        Schema::dropIfExists('customer_support_messages');
        Schema::dropIfExists('customer_support_tickets');
        Schema::dropIfExists('customer_portal_sessions');
        Schema::dropIfExists('booking_pages');
        Schema::dropIfExists('storefront_pages');
        Schema::dropIfExists('storefronts');
    }
};