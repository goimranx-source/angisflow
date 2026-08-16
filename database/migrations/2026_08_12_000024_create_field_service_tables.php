<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Task 29: Field Service and Fleet Management
     * 
     * Creates tables for field service operations and fleet management.
     * 
     * DESIGN DECISIONS:
     * 
     * 1. FLEET-CENTRIC DESIGN
     *    Vehicles are central resources that can be assigned to technicians
     *    and work orders. Supports both company-owned and leased vehicles.
     * 
     * 2. FLEXIBLE WORK ORDER SYSTEM
     *    Work orders can be reactive (customer calls) or proactive (scheduled maintenance)
     *    Supports different service types: installation, repair, maintenance, inspection
     * 
     * 3. ASSET-SERVICE RELATIONSHIP  
     *    Customer assets (equipment, systems) are tracked separately
     *    Work orders reference specific assets and maintain service history
     * 
     * 4. ROUTE OPTIMIZATION SUPPORT
     *    Geographic data and scheduling support for route optimization
     *    Priority levels and time windows for service appointments
     * 
     * 5. MOBILE WORKFORCE TRACKING
     *    Check-in/out capabilities, GPS tracking, time logging
     *    Photo and signature capture for service completion
     * 
     * 6. PARTS AND INVENTORY INTEGRATION
     *    Field technician inventory tracking and usage recording
     *    Integration with main inventory system for restocking
     *
     * ACCOUNTING INTEGRATION:
     * - Work orders generate service revenue (4100)
     * - Parts usage affects inventory (1400) and COGS (5000)
     * - Vehicle expenses tracked (6100-6700 range)
     * - Service contracts create recurring revenue
     */
    public function up(): void
    {
        // ── Fleet Vehicles ─────────────────────────────────────────────────────
        
        Schema::create('fleet_vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('public_id')->unique();
            
            // Vehicle Identity
            $table->string('vehicle_number')->unique(); // FL-001, VAN-002, etc.
            $table->string('license_plate')->unique();
            $table->string('vin')->nullable()->unique(); // Vehicle identification number
            $table->string('make'); // Ford, Toyota, etc.
            $table->string('model'); // Transit, Prius, etc.
            $table->integer('year');
            $table->string('color')->nullable();
            $table->string('vehicle_type'); // van, truck, car, motorcycle, etc.
            
            // Ownership and Status
            $table->string('ownership_type')->default('owned'); // owned, leased, rental
            $table->string('status')->default('active'); // active, maintenance, retired, sold
            $table->date('purchase_date')->nullable();
            $table->integer('purchase_price_minor')->default(0);
            $table->string('currency', 3);
            $table->date('lease_start_date')->nullable();
            $table->date('lease_end_date')->nullable();
            
            // Technical Specifications
            $table->string('engine_type')->nullable(); // diesel, petrol, electric, hybrid
            $table->decimal('engine_capacity', 8, 2)->nullable(); // Liters
            $table->integer('max_payload_kg')->nullable();
            $table->integer('seating_capacity')->default(2);
            $table->decimal('fuel_capacity_liters', 8, 2)->nullable();
            
            // Operational Data
            $table->integer('current_odometer_km')->default(0);
            $table->date('last_service_date')->nullable();
            $table->integer('service_interval_km')->default(10000);
            $table->date('insurance_expiry')->nullable();
            $table->date('registration_expiry')->nullable();
            $table->text('notes')->nullable();
            
            // GPS and Tracking
            $table->decimal('last_latitude', 10, 7)->nullable();
            $table->decimal('last_longitude', 10, 7)->nullable();
            $table->timestamp('last_location_update')->nullable();
            $table->boolean('gps_enabled')->default(false);
            
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['business_id', 'status']);
            $table->index(['business_id', 'vehicle_type']);
            $table->index(['vehicle_number']);
        });
        
        // ── Vehicle Assignments ────────────────────────────────────────────────
        
        Schema::create('vehicle_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained('fleet_vehicles')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            
            $table->date('assigned_date');
            $table->date('unassigned_date')->nullable();
            $table->string('assignment_type')->default('primary'); // primary, temporary, backup
            $table->text('assignment_notes')->nullable();
            $table->boolean('is_active')->default(true);
            
            $table->timestamps();
            
            $table->index(['vehicle_id', 'is_active']);
            $table->index(['employee_id', 'is_active']);
            $table->index(['business_id', 'assigned_date']);
        });
        
        // ── Customer Assets ────────────────────────────────────────────────────
        
        Schema::create('customer_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('public_id')->unique();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            
            // Asset Identity
            $table->string('asset_number')->unique(); // AST-001234
            $table->string('name'); // "Main Server Room AC Unit"
            $table->string('asset_type'); // hvac, elevator, generator, security_system, etc.
            $table->string('make')->nullable();
            $table->string('model')->nullable();
            $table->string('serial_number')->nullable();
            $table->text('description')->nullable();
            
            // Location and Installation
            $table->text('installation_address');
            $table->string('installation_contact')->nullable();
            $table->string('installation_phone')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->date('installation_date')->nullable();
            $table->date('warranty_start_date')->nullable();
            $table->date('warranty_end_date')->nullable();
            
            // Service Information
            $table->string('service_level')->default('standard'); // basic, standard, premium
            $table->integer('service_interval_days')->default(365); // Annual service by default
            $table->date('last_service_date')->nullable();
            $table->date('next_service_due')->nullable();
            $table->string('status')->default('active'); // active, inactive, decommissioned
            $table->text('special_instructions')->nullable();
            
            // Commercial Details
            $table->integer('asset_value_minor')->default(0);
            $table->string('currency', 3);
            $table->string('criticality')->default('medium'); // low, medium, high, critical
            
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['business_id', 'customer_id']);
            $table->index(['business_id', 'asset_type']);
            $table->index(['business_id', 'status']);
            $table->index(['next_service_due']);
        });
        
        // ── Work Orders ─────────────────────────────────────────────────────────
        
        Schema::create('work_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('public_id')->unique();
            
            // Work Order Identity
            $table->string('work_order_number')->unique(); // WO-2024-001234
            $table->string('title'); // "AC Unit Repair - Main Office"
            $table->text('description');
            $table->string('work_order_type'); // repair, maintenance, installation, inspection
            $table->string('priority')->default('medium'); // low, medium, high, urgent
            $table->string('status')->default('created'); // created, assigned, in_progress, completed, cancelled
            
            // Customer and Asset Information
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_asset_id')->nullable()->constrained()->nullOnDelete();
            $table->text('service_address');
            $table->string('contact_name')->nullable();
            $table->string('contact_phone')->nullable();
            $table->string('contact_email')->nullable();
            
            // Scheduling
            $table->datetime('requested_date')->nullable(); // When customer wants service
            $table->datetime('scheduled_start')->nullable(); // Planned start time
            $table->datetime('scheduled_end')->nullable(); // Planned end time
            $table->datetime('actual_start')->nullable(); // When work actually started
            $table->datetime('actual_end')->nullable(); // When work actually completed
            $table->integer('estimated_duration_minutes')->default(60);
            
            // Assignment
            $table->foreignId('assigned_technician_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('assigned_vehicle_id')->nullable()->constrained('fleet_vehicles')->nullOnDelete();
            $table->datetime('assigned_at')->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            
            // Commercial
            $table->integer('estimated_cost_minor')->default(0);
            $table->integer('actual_cost_minor')->nullable();
            $table->string('currency', 3);
            $table->boolean('is_billable')->default(true);
            $table->boolean('is_warranty')->default(false);
            
            // Completion Data
            $table->text('work_performed')->nullable();
            $table->text('parts_used')->nullable(); // JSON list of parts
            $table->text('technician_notes')->nullable();
            $table->string('completion_signature')->nullable(); // Customer signature image path
            $table->json('completion_photos')->nullable(); // Array of photo paths
            $table->integer('customer_satisfaction')->nullable(); // 1-5 rating
            
            // System Fields
            $table->string('source')->default('internal'); // internal, customer_portal, phone, email
            $table->text('special_instructions')->nullable(); // Special handling instructions
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['business_id', 'status', 'scheduled_start']);
            $table->index(['business_id', 'customer_id']);
            $table->index(['business_id', 'assigned_technician_id']);
            $table->index(['business_id', 'work_order_type']);
            $table->index(['business_id', 'priority']);
            $table->index(['work_order_number']);
        });
        
        // ── Service Contracts ──────────────────────────────────────────────────
        
        Schema::create('service_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('public_id')->unique();
            
            // Contract Identity
            $table->string('contract_number')->unique(); // SC-2024-001
            $table->string('name'); // "Annual HVAC Maintenance - Office Building"
            $table->text('description')->nullable();
            $table->string('contract_type'); // maintenance, support, warranty_extension
            
            // Parties
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->json('covered_assets'); // Array of customer_asset_id values
            
            // Terms
            $table->date('start_date');
            $table->date('end_date');
            $table->string('billing_frequency'); // monthly, quarterly, annually
            $table->integer('contract_value_minor'); // Total contract value
            $table->string('currency', 3);
            $table->text('terms_and_conditions')->nullable();
            
            // Service Level Agreement
            $table->integer('response_time_hours')->default(24); // How quickly to respond
            $table->integer('resolution_time_hours')->default(72); // How quickly to resolve
            $table->json('service_schedule')->nullable(); // When service can be performed
            $table->integer('included_visits')->default(1); // Visits per period
            $table->boolean('parts_included')->default(false);
            $table->boolean('emergency_support')->default(false);
            
            // Status and Management
            $table->string('status')->default('draft'); // draft, active, expired, cancelled
            $table->date('next_service_date')->nullable();
            $table->integer('visits_used')->default(0);
            $table->text('special_instructions')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['business_id', 'status']);
            $table->index(['business_id', 'customer_id']);
            $table->index(['start_date', 'end_date']);
            $table->index(['next_service_date']);
        });
        
        // ── Technician Check-ins ───────────────────────────────────────────────
        
        Schema::create('technician_checkins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('technician_id')->constrained('employees')->cascadeOnDelete();
            
            // Check-in Data
            $table->string('checkin_type'); // arrival, departure, break_start, break_end
            $table->datetime('checkin_time');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->text('notes')->nullable();
            
            // Photos and Documentation
            $table->json('photos')->nullable(); // Array of photo paths
            $table->string('odometer_reading')->nullable();
            $table->text('status_update')->nullable();
            
            $table->timestamps();
            
            $table->index(['work_order_id', 'checkin_time']);
            $table->index(['technician_id', 'checkin_time']);
            $table->index(['business_id', 'checkin_time']);
        });
        
        // ── Field Inventory Tracking ───────────────────────────────────────────
        
        Schema::create('field_inventory', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('technician_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained('fleet_vehicles')->nullOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete(); // From main inventory
            
            // Inventory Tracking
            $table->decimal('quantity_on_hand', 18, 4)->default(0);
            $table->decimal('reserved_quantity', 18, 4)->default(0); // Reserved for work orders
            $table->integer('reorder_level')->default(5); // When to restock
            $table->date('last_restocked')->nullable();
            $table->text('location_notes')->nullable(); // Where stored in vehicle
            
            $table->timestamps();
            
            $table->unique(['technician_id', 'product_id']);
            $table->index(['vehicle_id', 'product_id']);
            $table->index(['business_id', 'technician_id']);
        });
        
        // ── Work Order Parts Usage ─────────────────────────────────────────────
        
        Schema::create('work_order_parts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('used_by')->constrained('employees')->cascadeOnDelete();
            
            // Usage Details
            $table->decimal('quantity_used', 18, 4);
            $table->integer('unit_cost_minor'); // Cost per unit at time of use
            $table->string('currency', 3);
            $table->datetime('used_at');
            $table->text('usage_notes')->nullable();
            
            // Source Tracking
            $table->string('source_location'); // warehouse, field_inventory, emergency_purchase
            $table->string('part_condition')->default('new'); // new, refurbished, emergency_replacement
            
            $table->timestamps();
            
            $table->index(['work_order_id', 'used_at']);
            $table->index(['business_id', 'product_id']);
            $table->index(['used_by', 'used_at']);
        });
        
        // ── Route Optimization Data ────────────────────────────────────────────
        
        Schema::create('daily_routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('public_id')->unique();
            
            // Route Identity
            $table->date('route_date');
            $table->foreignId('technician_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained('fleet_vehicles')->nullOnDelete();
            $table->string('route_name')->nullable(); // "North District Route"
            
            // Route Planning
            $table->json('work_order_sequence'); // Array of work_order_id in planned order
            $table->decimal('estimated_distance_km', 8, 2)->nullable();
            $table->integer('estimated_duration_minutes')->nullable();
            $table->datetime('planned_start_time');
            $table->datetime('planned_end_time');
            
            // Actual Performance
            $table->datetime('actual_start_time')->nullable();
            $table->datetime('actual_end_time')->nullable();
            $table->decimal('actual_distance_km', 8, 2)->nullable();
            $table->integer('fuel_used_liters')->nullable();
            $table->text('route_notes')->nullable();
            
            // Status
            $table->string('status')->default('planned'); // planned, in_progress, completed, cancelled
            $table->boolean('is_optimized')->default(false); // Whether route was optimized
            
            $table->timestamps();
            
            $table->unique(['route_date', 'technician_id']);
            $table->index(['business_id', 'route_date']);
            $table->index(['technician_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_routes');
        Schema::dropIfExists('work_order_parts');
        Schema::dropIfExists('field_inventory');
        Schema::dropIfExists('technician_checkins');
        Schema::dropIfExists('service_contracts');
        Schema::dropIfExists('work_orders');
        Schema::dropIfExists('customer_assets');
        Schema::dropIfExists('vehicle_assignments');
        Schema::dropIfExists('fleet_vehicles');
    }
};