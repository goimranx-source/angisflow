<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task 32: Public API and Integrations
 *
 * Creates a comprehensive public API system that allows external developers and
 * partners to integrate with the ERP system. This migration establishes the
 * infrastructure for API key management, rate limiting, webhook management,
 * and integration monitoring.
 *
 * ── Design Philosophy ──────────────────────────────────────────────────────
 *
 * 1. **Security First**: All API access requires authentication and is scoped by capabilities
 * 2. **Rate Limited**: Comprehensive rate limiting to prevent abuse and ensure fair usage
 * 3. **Auditable**: Complete logging of all API access for security and billing purposes
 * 4. **Flexible**: Support for multiple authentication methods and integration patterns
 * 5. **Developer Friendly**: Rich metadata and webhook systems for easy integration
 *
 * ── API Architecture ──────────────────────────────────────────────────────
 *
 * The public API differs from the internal API in several key ways:
 * - Authentication via API keys rather than sessions
 * - Rate limiting based on plan limits and API key tiers
 * - Capability-based access control with granular scoping
 * - Comprehensive request/response logging for billing and security
 * - Webhook system for real-time integration updates
 * - SDK generation support and developer documentation
 *
 * ── Integration Patterns ──────────────────────────────────────────────────
 *
 * 1. **API Keys**: Long-lived tokens for server-to-server integration
 * 2. **OAuth 2.0**: For applications acting on behalf of users (future)
 * 3. **Webhooks**: Real-time notifications for events and data changes
 * 4. **Rate Limiting**: Plan-based and API key-based throttling
 * 5. **Usage Tracking**: Comprehensive metrics for billing and analytics
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── API Keys ─────────────────────────────────────────────────────────────
        // Authentication tokens for external API access
        
        Schema::create('api_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('public_id')->unique();
            
            // Key Identity
            $table->string('name'); // "Production Integration", "Development API Key"
            $table->string('key_id')->unique(); // Public identifier: "pk_live_xyz123"
            $table->string('key_hash'); // Hashed secret key for validation
            $table->string('key_prefix', 20); // First 20 chars of key for display
            
            // Key Configuration
            $table->string('environment')->default('production'); // production, sandbox, development
            $table->json('scopes'); // Array of capabilities/resources this key can access
            $table->json('rate_limits')->nullable(); // Custom rate limits for this key
            $table->json('allowed_ips')->nullable(); // IP whitelist for additional security
            $table->string('webhook_url')->nullable(); // Optional webhook endpoint for this key
            $table->string('webhook_secret')->nullable(); // Secret for webhook signature validation
            
            // Key Status
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_used_from_ip')->nullable();
            $table->timestamp('expires_at')->nullable();
            
            // Key Metadata
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->text('description')->nullable();
            $table->json('metadata')->nullable(); // Custom fields for integration tracking
            
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['account_id', 'is_active']);
            $table->index(['business_id', 'is_active']);
            $table->index(['key_id']); // For fast key lookup
            $table->index(['environment', 'is_active']);
            $table->index(['last_used_at']);
            $table->index(['expires_at']);
        });
        
        // ── API Rate Limits ─────────────────────────────────────────────────────
        // Rate limiting configuration and tracking
        
        Schema::create('api_rate_limits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('public_id')->unique();
            
            // Rate Limit Identity
            $table->string('limit_type'); // api_key, account, ip, endpoint
            $table->string('limit_key'); // The actual key being limited (api key id, account id, etc.)
            $table->string('endpoint_pattern')->nullable(); // Specific endpoint pattern for endpoint limits
            
            // Rate Limit Configuration
            $table->integer('requests_per_minute')->default(60);
            $table->integer('requests_per_hour')->default(1000);
            $table->integer('requests_per_day')->default(10000);
            $table->integer('burst_allowance')->default(10); // Additional requests allowed in burst
            
            // Current Usage Tracking
            $table->integer('current_minute_count')->default(0);
            $table->integer('current_hour_count')->default(0);
            $table->integer('current_day_count')->default(0);
            $table->timestamp('minute_reset_at')->nullable();
            $table->timestamp('hour_reset_at')->nullable();
            $table->timestamp('day_reset_at')->nullable();
            
            // Rate Limit Status
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_exceeded_at')->nullable();
            $table->integer('total_exceeded_count')->default(0);
            
            $table->timestamps();
            
            $table->unique(['limit_type', 'limit_key'], 'rate_limit_unique');
            $table->index(['account_id', 'is_active']);
            $table->index(['limit_type', 'is_active']);
            $table->index(['minute_reset_at']);
            $table->index(['hour_reset_at']);
            $table->index(['day_reset_at']);
        });
        
        // ── API Request Log ─────────────────────────────────────────────────────
        // Comprehensive logging of all API requests for security, billing, and analytics
        
        Schema::create('api_request_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('api_key_id')->nullable()->constrained('api_keys')->cascadeOnDelete();
            $table->string('public_id')->unique();
            
            // Request Identity
            $table->string('request_id')->unique(); // UUID for request tracking
            $table->string('method', 10); // GET, POST, PUT, PATCH, DELETE
            $table->text('endpoint'); // Full endpoint path
            $table->string('version', 20)->default('v1'); // API version used
            
            // Request Details
            $table->string('ip_address', 45); // IPv4 or IPv6
            $table->string('user_agent')->nullable();
            $table->json('request_headers')->nullable(); // Selected headers (no auth tokens)
            $table->longText('request_body')->nullable(); // Request payload (sanitized)
            $table->text('query_params')->nullable(); // URL query parameters
            
            // Response Details
            $table->smallInteger('response_status'); // HTTP status code
            $table->json('response_headers')->nullable(); // Response headers
            $table->longText('response_body')->nullable(); // Response payload (configurable retention)
            $table->integer('response_size')->nullable(); // Response size in bytes
            
            // Performance Metrics
            $table->integer('duration_ms'); // Request duration in milliseconds
            $table->integer('db_queries_count')->default(0); // Number of database queries
            $table->integer('db_query_time_ms')->default(0); // Total DB query time
            $table->integer('cache_hits')->default(0); // Cache hit count
            $table->integer('cache_misses')->default(0); // Cache miss count
            
            // Rate Limiting
            $table->boolean('rate_limited')->default(false);
            $table->string('rate_limit_key')->nullable(); // Which rate limit was applied
            $table->integer('remaining_requests')->nullable(); // Requests remaining in current window
            
            // Error Tracking
            $table->boolean('has_error')->default(false);
            $table->string('error_type')->nullable(); // validation, authentication, server_error, etc.
            $table->text('error_message')->nullable();
            $table->longText('error_trace')->nullable(); // Stack trace for server errors
            
            // Security Tracking
            $table->boolean('suspicious')->default(false); // Flagged by security rules
            $table->json('security_flags')->nullable(); // Array of security concerns
            
            $table->timestamps();
            
            $table->index(['account_id', 'created_at']);
            $table->index(['api_key_id', 'created_at']);
            $table->index(['method', 'endpoint', 'created_at']);
            $table->index(['response_status', 'created_at']);
            $table->index(['ip_address', 'created_at']);
            $table->index(['rate_limited', 'created_at']);
            $table->index(['has_error', 'created_at']);
            $table->index(['suspicious', 'created_at']);
            $table->index(['duration_ms', 'created_at']);
        });
        
        // ── API Webhooks ────────────────────────────────────────────────────────
        // Webhook endpoints and delivery tracking for real-time integrations
        
        Schema::create('api_webhooks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('api_key_id')->nullable()->constrained('api_keys')->cascadeOnDelete();
            $table->string('public_id')->unique();
            
            // Webhook Identity
            $table->string('name'); // "Order Updates", "Customer Notifications"
            $table->string('url'); // The endpoint URL to POST to
            $table->string('secret'); // Shared secret for HMAC signature validation
            
            // Webhook Configuration
            $table->json('events'); // Array of events this webhook subscribes to
            $table->json('filters')->nullable(); // Optional filters for webhook triggering
            $table->string('content_type')->default('application/json'); // Content type to send
            $table->json('custom_headers')->nullable(); // Additional headers to include
            $table->integer('timeout_seconds')->default(30); // Request timeout
            $table->integer('retry_attempts')->default(3); // Number of retry attempts
            $table->json('retry_intervals')->nullable(); // Custom retry intervals [60, 300, 900]
            
            // Webhook Status
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_triggered_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->integer('total_deliveries')->default(0);
            $table->integer('successful_deliveries')->default(0);
            $table->integer('failed_deliveries')->default(0);
            $table->decimal('success_rate', 5, 4)->nullable(); // Success rate as decimal (0.9500)
            
            // Webhook Health
            $table->integer('consecutive_failures')->default(0);
            $table->boolean('is_healthy')->default(true); // Auto-disabled after too many failures
            $table->timestamp('disabled_at')->nullable();
            $table->string('disabled_reason')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['account_id', 'is_active']);
            $table->index(['business_id', 'is_active']);
            $table->index(['api_key_id', 'is_active']);
            $table->index(['is_healthy', 'is_active']);
            $table->index(['last_triggered_at']);
        });
        
        // ── API Webhook Deliveries ─────────────────────────────────────────────
        // Individual webhook delivery attempts and their outcomes
        
        Schema::create('api_webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('webhook_id')->constrained('api_webhooks')->cascadeOnDelete();
            $table->string('public_id')->unique();
            
            // Delivery Identity
            $table->string('delivery_id')->unique(); // UUID for delivery tracking
            $table->string('event_type'); // The event that triggered this delivery
            $table->string('event_id'); // ID of the event/resource that triggered this
            
            // Delivery Attempt Details
            $table->integer('attempt_number')->default(1); // 1, 2, 3, etc.
            $table->string('status'); // pending, success, failed, cancelled
            $table->timestamp('attempted_at');
            $table->timestamp('completed_at')->nullable();
            
            // Request Details
            $table->text('request_url'); // Full URL including any added parameters
            $table->json('request_headers'); // Headers sent with the request
            $table->longText('request_body'); // JSON payload sent
            $table->integer('request_size'); // Request size in bytes
            
            // Response Details
            $table->integer('response_status')->nullable(); // HTTP status code
            $table->json('response_headers')->nullable(); // Response headers received
            $table->longText('response_body')->nullable(); // Response body received
            $table->integer('response_size')->nullable(); // Response size in bytes
            $table->integer('response_time_ms')->nullable(); // Response time in milliseconds
            
            // Error Information
            $table->string('error_type')->nullable(); // network, timeout, http_error, etc.
            $table->text('error_message')->nullable();
            $table->timestamp('retry_after')->nullable(); // When to retry this delivery
            
            $table->timestamps();
            
            $table->index(['account_id', 'created_at']);
            $table->index(['webhook_id', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->index(['event_type', 'created_at']);
            $table->index(['attempt_number']);
            $table->index(['retry_after']);
            $table->index(['response_status']);
        });
        
        // ── API Integrations ───────────────────────────────────────────────────
        // High-level integration management and monitoring
        
        Schema::create('api_integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('public_id')->unique();
            
            // Integration Identity
            $table->string('name'); // "Shopify Integration", "Custom POS System"
            $table->string('slug')->index(); // "shopify", "custom-pos"
            $table->string('type'); // "e-commerce", "pos", "accounting", "crm", "custom"
            $table->string('provider')->nullable(); // "shopify", "woocommerce", "custom"
            $table->string('version')->nullable(); // Integration version
            
            // Integration Configuration
            $table->json('configuration'); // Integration-specific settings
            $table->json('field_mappings')->nullable(); // Data field mapping configuration
            $table->json('sync_settings')->nullable(); // Synchronization preferences
            $table->boolean('bidirectional')->default(false); // Whether data flows both ways
            
            // Integration Status
            $table->string('status')->default('active'); // active, paused, error, disabled
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_sync_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->text('last_error_message')->nullable();
            
            // Integration Health Metrics
            $table->integer('total_syncs')->default(0);
            $table->integer('successful_syncs')->default(0);
            $table->integer('failed_syncs')->default(0);
            $table->decimal('success_rate', 5, 4)->nullable();
            $table->integer('records_synced_today')->default(0);
            $table->integer('records_synced_total')->default(0);
            
            // Integration Metadata
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->text('description')->nullable();
            $table->json('metadata')->nullable(); // Custom fields and notes
            $table->timestamp('installed_at')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['account_id', 'is_active']);
            $table->index(['business_id', 'is_active']);
            $table->index(['type', 'is_active']);
            $table->index(['provider', 'is_active']);
            $table->index(['status']);
            $table->index(['last_sync_at']);
        });
        
        // ── API Usage Statistics ───────────────────────────────────────────────
        // Aggregated usage statistics for billing and analytics
        
        Schema::create('api_usage_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('api_key_id')->nullable()->constrained('api_keys')->cascadeOnDelete();
            
            // Time Period
            $table->string('period_type'); // hour, day, month
            $table->date('period_date'); // The date for this period
            $table->integer('period_hour')->nullable(); // The hour (0-23) for hourly stats
            
            // Usage Metrics
            $table->integer('total_requests')->default(0);
            $table->integer('successful_requests')->default(0);
            $table->integer('failed_requests')->default(0);
            $table->integer('rate_limited_requests')->default(0);
            
            // Performance Metrics
            $table->integer('avg_response_time_ms')->nullable();
            $table->integer('max_response_time_ms')->nullable();
            $table->integer('min_response_time_ms')->nullable();
            $table->bigInteger('total_response_size_bytes')->default(0);
            $table->bigInteger('total_request_size_bytes')->default(0);
            
            // Resource Usage
            $table->integer('total_db_queries')->default(0);
            $table->integer('total_db_time_ms')->default(0);
            $table->integer('total_cache_hits')->default(0);
            $table->integer('total_cache_misses')->default(0);
            
            // Error Breakdown
            $table->integer('auth_errors')->default(0);
            $table->integer('validation_errors')->default(0);
            $table->integer('server_errors')->default(0);
            $table->integer('not_found_errors')->default(0);
            
            $table->timestamps();
            
            $table->unique(['account_id', 'api_key_id', 'period_type', 'period_date', 'period_hour'], 'usage_stats_unique');
            $table->index(['account_id', 'period_type', 'period_date']);
            $table->index(['api_key_id', 'period_type', 'period_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_usage_stats');
        Schema::dropIfExists('api_integrations');
        Schema::dropIfExists('api_webhook_deliveries');
        Schema::dropIfExists('api_webhooks');
        Schema::dropIfExists('api_request_log');
        Schema::dropIfExists('api_rate_limits');
        Schema::dropIfExists('api_keys');
    }
};