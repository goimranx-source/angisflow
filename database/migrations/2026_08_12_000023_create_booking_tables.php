<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Task 28: Bookings and Scheduling
     * 
     * Creates tables for appointment booking and resource scheduling system.
     * 
     * DESIGN DECISIONS:
     * 
     * 1. FLEXIBLE RESOURCE MODEL
     *    Resources can be employees, equipment, rooms, or any bookable entity.
     *    Polymorphic relationship allows booking different resource types.
     * 
     * 2. SERVICE-BASED BOOKINGS
     *    Each booking is for a specific service with defined duration and pricing.
     *    Services can require specific resource types (e.g., only senior staff).
     * 
     * 3. AVAILABILITY WINDOWS
     *    Business defines when resources are available for booking.
     *    Supports different availability patterns per resource/day.
     * 
     * 4. BOOKING LIFECYCLE
     *    pending → confirmed → in_progress → completed → cancelled
     *    Each status change is tracked for audit trail.
     * 
     * 5. FLEXIBLE RECURRENCE
     *    Supports weekly/monthly/yearly recurring appointments.
     *    Each recurrence instance is a separate booking for easy management.
     * 
     * 6. MULTI-RESOURCE BOOKINGS
     *    Single appointment can book multiple resources (team meetings, etc.)
     *    Each resource booking tracked separately for conflict detection.
     *
     * ACCOUNTING INTEGRATION:
     * - Bookings create pending revenue
     * - Completed bookings generate invoices
     * - Cancellations handle refunds/penalties
     */
    public function up(): void
    {
        // ── Bookable Services ──────────────────────────────────────────────────
        
        Schema::create('booking_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('public_id')->unique();
            
            // Service Details
            $table->string('name'); // "Hair Cut", "Massage Therapy", "Meeting Room"
            $table->text('description')->nullable();
            $table->string('category')->default('general'); // health, beauty, professional, etc.
            $table->integer('duration_minutes'); // Standard duration
            $table->integer('buffer_minutes')->default(0); // Cleanup time between bookings
            $table->boolean('is_active')->default(true);
            
            // Pricing
            $table->string('pricing_type')->default('fixed'); // fixed, hourly, per_person
            $table->integer('base_price_minor')->default(0); // Base price in minor currency units
            $table->string('currency', 3);
            
            // Booking Rules
            $table->integer('max_participants')->default(1);
            $table->integer('advance_booking_hours')->default(1); // Min notice required
            $table->integer('max_advance_days')->default(30); // How far ahead can book
            $table->boolean('allow_cancellation')->default(true);
            $table->integer('cancellation_hours')->default(24); // Free cancellation period
            
            // Resource Requirements
            $table->json('required_resource_types')->nullable(); // ["employee", "room"]
            $table->json('preferred_employee_positions')->nullable(); // ["senior", "manager"]
            
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['business_id', 'is_active']);
            $table->index(['business_id', 'category']);
        });
        
        // ── Bookable Resources ─────────────────────────────────────────────────
        
        Schema::create('booking_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('public_id')->unique();
            
            // Resource Identity
            $table->string('name'); // "Dr. Smith", "Conference Room A", "Tesla Model 3"
            $table->string('resource_type'); // employee, room, equipment, vehicle
            $table->string('resource_id')->nullable(); // FK to specific resource (employee_id, etc.)
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            
            // Scheduling Properties
            $table->json('working_hours')->nullable(); // {"monday": ["09:00-17:00"], ...}
            $table->integer('booking_increment_minutes')->default(30); // 15, 30, 60 min slots
            $table->boolean('allow_back_to_back')->default(true);
            $table->integer('max_daily_hours')->default(8);
            
            // Capabilities
            $table->json('service_ids')->nullable(); // Which services this resource can provide
            $table->json('tags')->nullable(); // ["senior", "specialist", "equipment"]
            
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['business_id', 'resource_type', 'is_active']);
            $table->index(['business_id', 'resource_id']);
        });
        
        // ── Resource Availability ──────────────────────────────────────────────
        
        Schema::create('resource_availability', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('resource_id')->constrained('booking_resources')->cascadeOnDelete();
            
            // Availability Period
            $table->date('date');
            $table->time('start_time');
            $table->time('end_time');
            $table->string('availability_type')->default('available'); // available, unavailable, break
            $table->text('notes')->nullable();
            
            // Override Properties
            $table->boolean('is_override')->default(false); // Overrides default working hours
            $table->string('override_reason')->nullable(); // "holiday", "training", "maintenance"
            
            $table->timestamps();
            
            $table->unique(['resource_id', 'date', 'start_time', 'availability_type'], 'resource_availability_unique');
            $table->index(['resource_id', 'date']);
            $table->index(['business_id', 'date', 'availability_type']);
        });
        
        // ── Main Bookings ──────────────────────────────────────────────────────
        
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('public_id')->unique();
            
            // Booking Reference
            $table->string('booking_number')->unique(); // BK-2024-001234
            $table->string('status')->default('pending'); // pending, confirmed, in_progress, completed, cancelled, no_show
            
            // Customer Information
            $table->foreignId('customer_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('customer_name'); // Captured even if no customer record
            $table->string('customer_email')->nullable();
            $table->string('customer_phone')->nullable();
            $table->text('customer_notes')->nullable();
            
            // Service & Timing
            $table->foreignId('service_id')->constrained('booking_services')->cascadeOnDelete();
            $table->datetime('scheduled_start');
            $table->datetime('scheduled_end');
            $table->datetime('actual_start')->nullable();
            $table->datetime('actual_end')->nullable();
            $table->integer('participant_count')->default(1);
            
            // Pricing
            $table->integer('quoted_price_minor'); // Price quoted at booking time
            $table->string('currency', 3);
            $table->integer('final_price_minor')->nullable(); // Actual price charged
            $table->text('price_notes')->nullable(); // Discounts, add-ons, etc.
            
            // Recurrence
            $table->string('recurrence_pattern')->nullable(); // weekly, monthly, custom
            $table->json('recurrence_config')->nullable(); // {"every": 1, "days": ["monday", "wednesday"]}
            $table->date('recurrence_until')->nullable();
            $table->foreignId('parent_booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            
            // Status Changes
            $table->datetime('confirmed_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->datetime('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable();
            
            // Special Instructions
            $table->text('special_instructions')->nullable();
            $table->json('custom_fields')->nullable(); // Flexible form data
            
            // System Fields
            $table->string('booking_source')->default('staff'); // staff, online, phone, walk_in
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['business_id', 'status', 'scheduled_start']);
            $table->index(['business_id', 'customer_id']);
            $table->index(['business_id', 'service_id']);
            $table->index(['business_id', 'scheduled_start', 'scheduled_end']);
            $table->index(['parent_booking_id']);
            $table->index(['booking_number']);
        });
        
        // ── Resource Bookings (Many-to-Many) ───────────────────────────────────
        
        Schema::create('booking_resource_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('resource_id')->constrained('booking_resources', 'id')->cascadeOnDelete();
            
            // Resource-specific details
            $table->string('role')->default('primary'); // primary, assistant, observer
            $table->datetime('assigned_start')->nullable(); // May differ from booking start
            $table->datetime('assigned_end')->nullable();
            $table->text('resource_notes')->nullable();
            
            $table->timestamps();
            
            $table->unique(['booking_id', 'resource_id']);
            $table->index(['resource_id', 'assigned_start', 'assigned_end']);
            $table->index(['business_id', 'booking_id']);
        });
        
        // ── Booking Status History ─────────────────────────────────────────────
        
        Schema::create('booking_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->text('reason')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->datetime('changed_at');
            $table->json('metadata')->nullable(); // Additional context data
            
            $table->timestamps();
            
            $table->index(['booking_id', 'changed_at']);
            $table->index(['business_id', 'to_status', 'changed_at']);
        });
        
        // ── Booking Templates (for recurring patterns) ─────────────────────────
        
        Schema::create('booking_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('public_id')->unique();
            
            $table->string('name'); // "Weekly Team Meeting", "Monthly Maintenance"
            $table->text('description')->nullable();
            $table->foreignId('service_id')->constrained('booking_services')->cascadeOnDelete();
            
            // Template Properties
            $table->json('default_resources'); // Resource IDs to book by default
            $table->integer('default_duration_minutes');
            $table->string('recurrence_pattern'); // weekly, monthly, yearly
            $table->json('recurrence_config');
            $table->json('template_data'); // Default booking properties
            
            // Scheduling
            $table->boolean('auto_create')->default(false); // Automatically create future bookings
            $table->integer('create_ahead_days')->default(30);
            
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['business_id', 'auto_create']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_templates');
        Schema::dropIfExists('booking_status_history');
        Schema::dropIfExists('booking_resource_assignments');
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('resource_availability');
        Schema::dropIfExists('booking_resources');
        Schema::dropIfExists('booking_services');
    }
};