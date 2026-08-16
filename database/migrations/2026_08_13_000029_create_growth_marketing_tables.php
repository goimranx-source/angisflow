<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration: Growth Marketing Tables
 * 
 * Creates comprehensive tables for marketing campaigns, customer loyalty programs,
 * and enhanced review management to drive business growth through customer
 * acquisition, retention, and advocacy.
 * 
 * DESIGN DECISIONS:
 * 
 * 1. MARKETING CAMPAIGNS
 *    - Unified campaign model supporting email, SMS, social, paid ads
 *    - Flexible targeting with customer segments and behavioral triggers
 *    - A/B testing capabilities with variant tracking
 *    - Performance analytics with conversion tracking
 *    - Budget management and ROI calculation
 * 
 * 2. LOYALTY PROGRAMS  
 *    - Points-based system with flexible earning rules
 *    - Tier-based programs with automatic progression
 *    - Reward catalog with various redemption options
 *    - Referral programs with dual rewards
 *    - Expiration policies and point transfer capabilities
 * 
 * 3. ENHANCED REVIEWS
 *    - Multi-aspect reviews (product, service, delivery)
 *    - Photo/video attachments for rich feedback
 *    - Review moderation workflow with auto-approval rules
 *    - Review incentives to increase participation
 *    - Aggregate analytics for business intelligence
 * 
 * 4. GROWTH ANALYTICS
 *    - Customer lifetime value tracking
 *    - Cohort analysis for retention insights
 *    - Attribution modeling for marketing effectiveness
 *    - Churn prediction and prevention workflows
 * 
 * WHY THIS STRUCTURE:
 * - Campaigns table supports all channel types through flexible JSON configuration
 * - Loyalty system designed for scalability with point transactions and tier tracking
 * - Reviews enhanced with media and incentive tracking for higher engagement
 * - Analytics tables provide actionable insights for growth optimization
 * - All entities properly tenant-scoped for multi-business support
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Marketing Campaigns ──────────────────────────────────────────────
        
        /**
         * Marketing campaigns with multi-channel support and A/B testing
         * 
         * Supports email, SMS, push notifications, social media, and paid advertising
         * campaigns with flexible targeting, automation, and performance tracking.
         */
        Schema::create('marketing_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 26)->unique(); // ULID
            
            // Tenancy
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            
            // Campaign details
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('type', [
                'email', 'sms', 'push', 'social', 'paid_ads', 'direct_mail',
                'in_store', 'automation', 'lifecycle', 'abandoned_cart'
            ]);
            $table->enum('status', ['draft', 'scheduled', 'running', 'paused', 'completed', 'cancelled'])
                  ->default('draft');
            
            // Campaign configuration
            $table->json('configuration'); // Channel-specific settings, templates, etc.
            $table->json('targeting_rules'); // Audience segmentation and filters
            $table->json('automation_triggers')->nullable(); // Behavioral triggers
            $table->json('personalization_rules')->nullable(); // Dynamic content rules
            
            // Scheduling and timing
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->integer('duration_days')->nullable(); // For ongoing campaigns
            $table->string('timezone', 50)->default('UTC');
            
            // Budget and limits
            $table->integer('budget_amount')->nullable(); // In minor currency units
            $table->string('budget_currency', 3)->default('USD');
            $table->enum('budget_type', ['total', 'daily', 'monthly'])->default('total');
            $table->integer('send_limit')->nullable(); // Max sends per period
            $table->enum('send_limit_period', ['hour', 'day', 'week', 'month'])->nullable();
            
            // A/B Testing
            $table->boolean('is_ab_test')->default(false);
            $table->json('ab_variants')->nullable(); // Variant configurations
            $table->decimal('traffic_split', 3, 2)->default(1.00); // Percentage of audience
            $table->enum('winning_metric', ['open_rate', 'click_rate', 'conversion_rate', 'revenue'])->nullable();
            $table->string('winning_variant')->nullable();
            
            // Performance tracking (updated by automation)
            $table->integer('sent_count')->default(0);
            $table->integer('delivered_count')->default(0);
            $table->integer('opened_count')->default(0);
            $table->integer('clicked_count')->default(0);
            $table->integer('converted_count')->default(0);
            $table->integer('revenue_generated')->default(0); // Minor units
            $table->integer('unsubscribed_count')->default(0);
            $table->integer('complained_count')->default(0);
            $table->integer('bounced_count')->default(0);
            
            // Metadata
            $table->integer('created_by')->nullable(); // User ID
            $table->json('tags')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes for performance
            $table->index(['account_id', 'business_id']);
            $table->index(['status', 'scheduled_at']);
            $table->index(['type', 'status']);
            $table->index('created_by');
        });
        
        /**
         * Individual campaign executions and message deliveries
         * 
         * Tracks each message sent as part of a campaign for detailed analytics
         * and delivery status monitoring.
         */
        Schema::create('campaign_deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 26)->unique(); // ULID
            
            // Relationships
            $table->foreignId('campaign_id')->constrained('marketing_campaigns')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('recipient_email')->nullable(); // For non-customers
            $table->string('recipient_phone')->nullable(); // For SMS campaigns
            
            // Delivery details
            $table->string('channel'); // email, sms, push, etc.
            $table->string('variant')->nullable(); // A/B test variant
            $table->json('message_content'); // Rendered content
            $table->json('personalization_data')->nullable(); // Used personalization
            
            // Status tracking
            $table->enum('status', [
                'queued', 'sending', 'sent', 'delivered', 'opened', 'clicked',
                'converted', 'failed', 'bounced', 'unsubscribed', 'complained'
            ])->default('queued');
            $table->text('failure_reason')->nullable();
            $table->string('external_id')->nullable(); // Provider message ID
            
            // Engagement timestamps
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('clicked_at')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            
            // Revenue attribution
            $table->integer('attributed_revenue')->default(0); // Minor units
            $table->integer('attributed_orders')->default(0);
            $table->json('conversion_data')->nullable(); // Order IDs, etc.
            
            $table->timestamps();
            
            // Indexes for analytics and performance
            $table->index(['campaign_id', 'status']);
            $table->index(['customer_id', 'status']);
            $table->index(['channel', 'status']);
            $table->index(['sent_at']);
            $table->index(['converted_at']);
        });
        
        // ── Loyalty Programs ─────────────────────────────────────────────────
        
        /**
         * Loyalty program configurations
         * 
         * Defines the rules and structure for customer loyalty programs including
         * points earning, tier systems, and reward catalogs.
         */
        Schema::create('loyalty_programs', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 26)->unique(); // ULID
            
            // Tenancy
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            
            // Program details
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('type', ['points', 'tiers', 'cashback', 'stamps', 'referrals']);
            $table->boolean('is_active')->default(true);
            $table->boolean('auto_enroll')->default(false); // Auto-enroll new customers
            
            // Points configuration
            $table->json('earning_rules'); // How customers earn points/rewards
            $table->json('redemption_rules'); // How rewards can be redeemed
            $table->integer('points_per_currency_unit')->default(1); // e.g., 1 point per $1
            $table->string('points_currency', 3)->default('USD'); // Base currency
            $table->integer('minimum_redemption_points')->default(100);
            
            // Tier system configuration
            $table->json('tier_levels')->nullable(); // Tier definitions and benefits
            $table->boolean('tier_auto_upgrade')->default(true);
            $table->boolean('tier_auto_downgrade')->default(false);
            $table->enum('tier_period', ['monthly', 'quarterly', 'yearly', 'lifetime'])->default('yearly');
            
            // Expiration and limits
            $table->integer('points_expire_days')->nullable(); // Points expiry
            $table->boolean('points_transferable')->default(false);
            $table->integer('max_points_per_transaction')->nullable();
            $table->integer('max_points_per_day')->nullable();
            
            // Referral program
            $table->json('referral_config')->nullable(); // Referral rewards and rules
            $table->integer('referrer_reward_points')->nullable();
            $table->integer('referee_reward_points')->nullable();
            $table->integer('referral_minimum_purchase')->nullable(); // Minor units
            
            // Display and branding
            $table->string('points_name')->default('Points'); // "Points", "Stars", etc.
            $table->string('logo_url')->nullable();
            $table->json('branding_config')->nullable(); // Colors, fonts, etc.
            $table->json('terms_and_conditions')->nullable();
            
            // Analytics (updated by automation)
            $table->integer('total_members')->default(0);
            $table->integer('active_members')->default(0); // Earned/redeemed recently
            $table->integer('total_points_issued')->default(0);
            $table->integer('total_points_redeemed')->default(0);
            $table->integer('total_rewards_claimed')->default(0);
            
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->index(['account_id', 'business_id']);
            $table->index(['type', 'is_active']);
        });
        
        /**
         * Customer loyalty program memberships
         * 
         * Tracks individual customer participation in loyalty programs including
         * points balance, tier status, and program history.
         */
        Schema::create('loyalty_memberships', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 26)->unique(); // ULID
            
            // Relationships
            $table->foreignId('loyalty_program_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            
            // Membership status
            $table->enum('status', ['active', 'inactive', 'suspended', 'cancelled'])->default('active');
            $table->timestamp('enrolled_at');
            $table->timestamp('last_activity_at')->nullable();
            
            // Points and balance
            $table->integer('points_balance')->default(0);
            $table->integer('points_lifetime_earned')->default(0);
            $table->integer('points_lifetime_redeemed')->default(0);
            $table->integer('points_pending')->default(0); // Pending approval
            $table->timestamp('points_last_earned_at')->nullable();
            $table->timestamp('points_last_redeemed_at')->nullable();
            
            // Tier information
            $table->string('current_tier')->nullable();
            $table->integer('tier_points')->default(0); // Points towards next tier
            $table->integer('tier_spend_amount')->default(0); // Spend towards tier (minor units)
            $table->timestamp('tier_achieved_at')->nullable();
            $table->timestamp('tier_expires_at')->nullable();
            $table->json('tier_benefits_used')->nullable(); // Track benefit usage
            
            // Referral tracking
            $table->string('referral_code', 20)->nullable()->unique();
            $table->integer('referrals_made')->default(0);
            $table->integer('referrals_successful')->default(0); // Completed purchases
            $table->integer('referral_points_earned')->default(0);
            $table->foreignId('referred_by_customer_id')->nullable()->constrained('customers');
            
            // Preferences and notifications
            $table->json('notification_preferences')->nullable(); // Email, SMS, push
            $table->json('preferences')->nullable(); // Program-specific preferences
            $table->json('tags')->nullable(); // Segmentation tags
            
            $table->timestamps();
            
            // Indexes and constraints
            $table->index(['loyalty_program_id', 'customer_id']);
            $table->index(['status', 'last_activity_at']);
            $table->index(['current_tier']);
            $table->index(['referral_code']);
            $table->unique(['loyalty_program_id', 'customer_id']); // One membership per program
        });
        
        /**
         * Loyalty point transactions
         * 
         * Detailed log of all point earning and redemption activities with
         * full audit trail and attribution.
         */
        Schema::create('loyalty_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 26)->unique(); // ULID
            
            // Relationships
            $table->foreignId('loyalty_membership_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            
            // Transaction details
            $table->enum('type', ['earned', 'redeemed', 'expired', 'adjusted', 'transferred', 'refunded']);
            $table->integer('points_amount'); // Positive for earning, negative for spending
            $table->integer('balance_after'); // Points balance after this transaction
            $table->text('description'); // Human-readable description
            $table->enum('status', ['pending', 'completed', 'cancelled', 'failed'])->default('completed');
            
            // Attribution and source
            $table->string('source_type')->nullable(); // Order, review, referral, etc.
            $table->string('source_id')->nullable(); // Related entity ID
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained('marketing_campaigns')->nullOnDelete();
            $table->string('reference_code')->nullable(); // External reference
            
            // Expiration tracking
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_expired')->default(false);
            $table->timestamp('expired_at')->nullable();
            
            // Processing information
            $table->integer('processed_by_user_id')->nullable(); // Staff member for manual adjustments
            $table->json('metadata')->nullable(); // Additional context data
            $table->text('notes')->nullable(); // Internal notes
            
            $table->timestamps();
            
            // Indexes for reporting and analytics
            $table->index(['loyalty_membership_id', 'type']);
            $table->index(['customer_id', 'created_at']);
            $table->index(['type', 'created_at']);
            $table->index(['source_type', 'source_id']);
            $table->index(['expires_at', 'is_expired']);
        });
        
        /**
         * Loyalty rewards catalog
         * 
         * Available rewards that customers can redeem with their points,
         * including products, discounts, and experiences.
         */
        Schema::create('loyalty_rewards', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 26)->unique(); // ULID
            
            // Tenancy
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('loyalty_program_id')->constrained()->cascadeOnDelete();
            
            // Reward details
            $table->string('name');
            $table->text('description');
            $table->enum('type', ['product', 'discount', 'shipping', 'service', 'experience', 'cashback']);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            
            // Points and pricing
            $table->integer('points_cost');
            $table->integer('cash_value')->nullable(); // Equivalent cash value in minor units
            $table->string('currency', 3)->default('USD');
            
            // Availability and limits
            $table->integer('stock_quantity')->nullable(); // Limited quantity rewards
            $table->integer('max_per_customer')->nullable(); // Redemption limit per customer
            $table->integer('max_per_period')->nullable(); // Period-based limits
            $table->enum('max_period_type', ['day', 'week', 'month', 'year'])->nullable();
            $table->json('eligibility_rules')->nullable(); // Tier requirements, etc.
            
            // Reward configuration
            $table->json('reward_config'); // Type-specific configuration
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete(); // For product rewards
            $table->string('discount_code')->nullable(); // For discount rewards
            $table->decimal('discount_percentage', 5, 2)->nullable();
            $table->integer('discount_amount')->nullable(); // Fixed amount discounts
            
            // Display and media
            $table->string('image_url')->nullable();
            $table->json('images')->nullable(); // Multiple images
            $table->json('terms_conditions')->nullable();
            $table->json('redemption_instructions')->nullable();
            
            // Analytics
            $table->integer('redemption_count')->default(0);
            $table->integer('total_points_redeemed')->default(0);
            $table->timestamp('last_redeemed_at')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->index(['loyalty_program_id', 'is_active']);
            $table->index(['type', 'is_active']);
            $table->index(['points_cost']);
        });
        
        // ── Enhanced Reviews ─────────────────────────────────────────────────
        
        /**
         * Review incentive campaigns
         * 
         * Campaigns to encourage customers to leave reviews through rewards,
         * follow-ups, and gamification.
         */
        Schema::create('review_incentive_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 26)->unique(); // ULID
            
            // Tenancy
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            
            // Campaign details
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            
            // Targeting rules
            $table->json('trigger_rules'); // When to send review requests
            $table->json('customer_filters')->nullable(); // Which customers to target
            $table->integer('days_after_purchase')->default(7); // Default delay
            $table->integer('max_requests_per_customer')->default(3);
            $table->integer('request_interval_days')->default(30);
            
            // Incentive configuration
            $table->enum('incentive_type', ['points', 'discount', 'cashback', 'none'])->default('none');
            $table->integer('incentive_amount')->nullable(); // Points or cash amount
            $table->decimal('incentive_percentage', 5, 2)->nullable(); // Discount percentage
            $table->string('incentive_description')->nullable(); // Display text
            $table->boolean('incentive_requires_approval')->default(false);
            
            // Message templates
            $table->json('email_template')->nullable();
            $table->json('sms_template')->nullable();
            $table->json('push_template')->nullable();
            $table->string('review_page_url')->nullable(); // Custom review page
            
            // Performance tracking
            $table->integer('requests_sent')->default(0);
            $table->integer('reviews_received')->default(0);
            $table->integer('incentives_granted')->default(0);
            $table->decimal('conversion_rate', 5, 2)->default(0.00);
            
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->index(['account_id', 'business_id']);
            $table->index(['is_active']);
        });
        
        /**
         * Review requests and follow-ups
         * 
         * Tracks individual review requests sent to customers and their responses.
         */
        Schema::create('review_requests', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 26)->unique(); // ULID
            
            // Relationships
            $table->foreignId('review_incentive_campaign_id')->nullable()
                  ->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            
            // Request details
            $table->string('request_type')->default('email'); // email, sms, push
            $table->enum('status', ['sent', 'opened', 'clicked', 'reviewed', 'declined', 'expired'])
                  ->default('sent');
            $table->text('message_content');
            $table->string('review_token', 64)->unique(); // Secure token for review link
            
            // Engagement tracking
            $table->timestamp('sent_at');
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('clicked_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('expires_at');
            
            // Incentive tracking
            $table->boolean('incentive_offered')->default(false);
            $table->boolean('incentive_claimed')->default(false);
            $table->integer('incentive_amount')->nullable();
            $table->timestamp('incentive_granted_at')->nullable();
            
            // Associated review
            $table->foreignId('product_review_id')->nullable()
                  ->constrained()->nullOnDelete();
            
            $table->timestamps();
            
            // Indexes
            $table->index(['customer_id', 'status']);
            $table->index(['order_id']);
            $table->index(['review_token']);
            $table->index(['expires_at']);
        });
        
        // ── Growth Analytics ─────────────────────────────────────────────────
        
        /**
         * Customer lifetime value tracking
         * 
         * Tracks customer value metrics over time for segmentation and
         * marketing optimization.
         */
        Schema::create('customer_ltv_analytics', function (Blueprint $table) {
            $table->id();
            
            // Relationships
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            
            // LTV calculations
            $table->integer('total_orders')->default(0);
            $table->integer('total_spent')->default(0); // Minor currency units
            $table->integer('average_order_value')->default(0); // Minor units
            $table->decimal('purchase_frequency', 5, 2)->default(0.00); // Orders per month
            $table->integer('days_since_first_order')->default(0);
            $table->integer('days_since_last_order')->default(0);
            
            // Predicted metrics
            $table->integer('predicted_ltv')->default(0); // Minor units
            $table->decimal('churn_probability', 5, 4)->default(0.0000); // 0-1 probability
            $table->integer('predicted_next_order_days')->nullable();
            $table->integer('predicted_next_order_value')->nullable(); // Minor units
            
            // Segmentation
            $table->string('value_segment')->nullable(); // High, Medium, Low value
            $table->string('loyalty_segment')->nullable(); // Champion, Loyal, At Risk, etc.
            $table->string('lifecycle_stage')->nullable(); // New, Growing, Mature, etc.
            $table->json('behavioral_tags')->nullable(); // Custom behavioral markers
            
            // Time periods for analysis
            $table->date('calculation_date'); // When these metrics were calculated
            $table->timestamp('last_updated');
            
            // Indexes for analytics queries
            $table->index(['customer_id', 'calculation_date']);
            $table->index(['business_id', 'value_segment']);
            $table->index(['churn_probability']);
            $table->index(['predicted_ltv']);
            $table->unique(['customer_id', 'calculation_date']);
        });
        
        /**
         * Marketing attribution tracking
         * 
         * Tracks how marketing campaigns contribute to customer acquisition
         * and revenue generation.
         */
        Schema::create('marketing_attribution', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 26)->unique(); // ULID
            
            // Attribution details
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('campaign_id')->nullable()
                  ->constrained('marketing_campaigns')->nullOnDelete();
            
            // Attribution model
            $table->enum('attribution_type', [
                'first_touch', 'last_touch', 'linear', 'time_decay', 'position_based'
            ])->default('last_touch');
            $table->decimal('attribution_weight', 5, 4)->default(1.0000); // 0-1 weight
            
            // Touch point information
            $table->string('touch_point'); // Campaign, organic, referral, direct, etc.
            $table->string('channel'); // Email, social, paid search, etc.
            $table->string('source')->nullable(); // Google, Facebook, etc.
            $table->string('medium')->nullable(); // CPC, organic, email, etc.
            $table->string('campaign_name')->nullable();
            $table->json('utm_parameters')->nullable(); // UTM tracking data
            
            // Value attribution
            $table->integer('attributed_revenue')->default(0); // Minor units
            $table->decimal('conversion_value', 10, 2)->default(0.00);
            $table->timestamp('touch_point_at'); // When the touch point occurred
            $table->timestamp('conversion_at')->nullable(); // When conversion happened
            
            // Customer journey context
            $table->integer('customer_journey_position')->default(1); // 1st, 2nd, etc. touch
            $table->integer('days_to_conversion')->nullable();
            $table->json('journey_metadata')->nullable(); // Additional journey data
            
            $table->timestamps();
            
            // Indexes for attribution analysis
            $table->index(['campaign_id', 'conversion_at']);
            $table->index(['customer_id', 'touch_point_at']);
            $table->index(['touch_point', 'channel']);
            $table->index(['attribution_type']);
        });
    }
    
    public function down(): void
    {
        Schema::dropIfExists('marketing_attribution');
        Schema::dropIfExists('customer_ltv_analytics');
        Schema::dropIfExists('review_requests');
        Schema::dropIfExists('review_incentive_campaigns');
        Schema::dropIfExists('loyalty_rewards');
        Schema::dropIfExists('loyalty_transactions');
        Schema::dropIfExists('loyalty_memberships');
        Schema::dropIfExists('loyalty_programs');
        Schema::dropIfExists('campaign_deliveries');
        Schema::dropIfExists('marketing_campaigns');
    }
};