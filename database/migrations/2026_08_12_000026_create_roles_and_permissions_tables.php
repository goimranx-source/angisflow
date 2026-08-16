<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task 31: Roles and Permissions
 *
 * Extends the existing capability-based role system with more granular permissions
 * and comprehensive access control. This migration adds additional tables to support
 * fine-grained permissions, audit logging, and advanced role management while
 * maintaining compatibility with the existing capabilities system.
 *
 * ── Existing System Integration ─────────────────────────────────────────────
 *
 * The system already has:
 * - `roles` table with JSON capabilities array (efficient caching)
 * - `App\Support\Capabilities` class managing capability definitions
 * - Role-based workspace and scope management
 *
 * This extension adds:
 * - Granular permissions that map to existing capabilities
 * - Permission audit trails and compliance features  
 * - Advanced role assignment workflows and policies
 * - Multi-business role assignments and inheritance
 *
 * ── Design Philosophy ──────────────────────────────────────────────────────
 *
 * 1. **Capability Compatibility**: New permissions integrate with existing capabilities
 * 2. **Audit Everything**: All permission changes are logged for compliance
 * 3. **Business Context**: Permissions can be scoped to specific businesses
 * 4. **Policy Driven**: Advanced rules for conditional access control
 * 5. **Performance First**: Maintain the existing caching benefits
 */
return new class extends Migration
{
    public function up(): void
    {
        // Note: 'roles' and 'permissions' tables already exist from earlier migrations
        // This migration adds complementary access control features
        
        // ── Extended User Role Assignments ──────────────────────────────────────
        // Enhanced role assignments with business context, time limits, and audit trail
        
        Schema::create('user_role_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->nullable()->constrained()->cascadeOnDelete();
            
            // Assignment Scope
            $table->string('assignment_scope')->default('business'); // account, business, location
            $table->string('scope_id')->nullable(); // Specific business/location ID for scoped assignments
            
            // Assignment Details
            $table->boolean('is_active')->default(true);
            $table->date('effective_from')->default(now());
            $table->date('effective_until')->nullable(); // For temporary role assignments
            $table->json('scope_restrictions')->nullable(); // Additional limitations within role
            
            // Assignment Metadata  
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('assignment_reason')->nullable(); // Why this role was assigned
            
            // Emergency Access
            $table->boolean('is_emergency_access')->default(false); // For break-glass scenarios
            $table->timestamp('emergency_expires_at')->nullable();
            $table->text('emergency_justification')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            $table->unique(['user_id', 'role_id', 'business_id'], 'user_role_business_unique');
            $table->index(['account_id', 'user_id', 'is_active']);
            $table->index(['role_id', 'is_active']);
            $table->index(['business_id', 'is_active']);
            $table->index(['effective_until']);
            $table->index(['emergency_expires_at']);
        });
        
        // ── Permission Policies ─────────────────────────────────────────────
        // Advanced access control policies for conditional permissions
        
        Schema::create('permission_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('public_id')->unique();
            
            // Policy Identity
            $table->string('policy_name'); // "Sales Team Access Policy"
            $table->string('policy_type'); // role_based, attribute_based, time_based, location_based
            $table->text('description')->nullable();
            
            // Policy Rules
            $table->json('rules'); // Policy rule definitions
            $table->json('conditions'); // When this policy applies
            $table->string('effect')->default('allow'); // allow, deny
            $table->integer('priority')->default(100); // Higher numbers take precedence
            
            // Policy Status
            $table->boolean('is_active')->default(true);
            $table->date('effective_from')->default(now());
            $table->date('effective_until')->nullable();
            
            // Policy Metadata
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['account_id', 'business_id', 'is_active']);
            $table->index(['policy_type', 'is_active']);
            $table->index(['priority']);
        });
        
        // ── Permission Audit Log ────────────────────────────────────────────
        // Comprehensive audit trail for all access control changes
        
        Schema::create('permission_audit_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('public_id')->unique();
            
            // Audit Event Details
            $table->string('event_type'); // permission_granted, permission_revoked, role_assigned, role_removed
            $table->string('entity_type'); // user, role, permission, policy
            $table->string('entity_id'); // ID of the affected entity
            
            // Context Information
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // User affected
            $table->foreignId('role_id')->nullable()->constrained()->nullOnDelete(); // Role involved
            $table->foreignId('permission_id')->nullable()->constrained()->nullOnDelete(); // Permission involved
            $table->foreignId('business_id')->nullable()->constrained()->cascadeOnDelete(); // Business context
            
            // Change Details
            $table->json('old_values')->nullable(); // Previous state
            $table->json('new_values')->nullable(); // New state
            $table->text('change_reason')->nullable(); // Why the change was made
            
            // Actor Information
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('performed_via')->default('web'); // web, api, system, import
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            
            // Risk and Compliance
            $table->string('risk_level')->default('low'); // low, medium, high, critical
            $table->boolean('requires_review')->default(false);
            $table->boolean('is_privileged_operation')->default(false); // High-risk operation
            
            $table->timestamps();
            
            $table->index(['account_id', 'event_type', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['performed_by', 'created_at']);
            $table->index(['business_id', 'created_at']);
            $table->index(['requires_review']);
        });
        
        // ── Access Control Sessions ─────────────────────────────────────────
        // Session-based permission caching and elevated access tracking
        
        Schema::create('access_control_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('public_id')->unique();
            
            // Session Context
            $table->string('session_id')->unique(); // Links to Laravel sessions
            $table->json('active_roles'); // Roles active in this session
            $table->json('cached_permissions'); // Cached permission set for performance
            $table->string('current_business_context')->nullable(); // Active business context
            
            // Session Metadata
            $table->timestamp('permissions_loaded_at')->nullable(); // When permissions were last cached
            $table->timestamp('last_permission_check')->nullable(); // Last permission verification
            $table->integer('permission_checks_count')->default(0); // Performance tracking
            
            // Security Information
            $table->string('ip_address');
            $table->text('user_agent')->nullable();
            $table->boolean('is_elevated_session')->default(false); // Admin/privileged session
            $table->timestamp('elevation_expires_at')->nullable();
            
            $table->timestamps();
            
            $table->index(['user_id', 'business_id']);
            $table->index(['session_id']);
            $table->index(['elevation_expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_control_sessions');
        Schema::dropIfExists('permission_audit_log');
        Schema::dropIfExists('permission_policies');
        Schema::dropIfExists('user_role_assignments');
    }
};