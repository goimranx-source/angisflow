<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task 27: Projects and Timesheets
 *
 * Project management and time tracking system for professional services and
 * agencies. Tracks billable and non-billable time, project budgets, and
 * generates invoices from approved time entries.
 *
 * ## Why this design
 *
 * ### Projects as billable containers
 *
 * A project is a trackable unit of work - could be client work, internal
 * initiatives, or overhead activities. Each project has:
 * - Budget (time and/or money)
 * - Billing rate (fixed, hourly, or per employee rates)
 * - Status tracking (planning, active, completed, on-hold)
 * - Client association for billing
 *
 * **Why not just "jobs":** Projects can span multiple invoices, have internal
 * phases, and include both billable and non-billable activities. A project is
 * the container; time entries are the atomic units.
 *
 * ### Time entries, not timesheets
 *
 * Each time entry is atomic - one person, one project, one time period, one
 * rate. This is more granular than "timesheets" but enables better tracking:
 * - Different rates per project/employee combination
 * - Mixed billable/non-billable work in same day
 * - Accurate project costing
 * - Flexible invoicing (bill by project, not by timesheet)
 *
 * ### Approval workflow
 *
 * Time entries flow through states: Draft → Submitted → Approved → Invoiced.
 * Only approved time can be billed to clients.
 *
 * **Why:** Prevents billing errors, allows review of time allocation, enables
 * correction before client sees the invoice.
 *
 * ### Flexible rate structure
 *
 * Projects can have:
 * - Fixed rate (bill same rate regardless of who works)
 * - Employee-specific rates (senior developers bill higher)
 * - No billing (internal projects)
 *
 * **Why:** Different business models need different rate structures. A design
 * agency bills everyone at $150/hr; a consultancy has junior/senior rates.
 *
 * ### Budget tracking in time and money
 *
 * Projects track both time budget (estimated hours) and financial budget
 * (maximum billable amount). This enables:
 * - Early warning when approaching budget limits
 * - Profitability analysis (actual cost vs budget vs billing)
 * - Resource planning
 *
 * ### Integration points
 *
 * - **Employees:** Time entries link to existing employee records
 * - **Invoicing:** Approved time becomes invoice line items
 * - **Ledger:** Project costs post to appropriate expense accounts
 * - **Customers:** Projects belong to customers for billing
 *
 * ## What is deferred
 *
 * - **Resource scheduling:** Assigning employees to projects in advance
 * - **Task breakdowns:** Projects as containers for smaller tasks
 * - **Gantt charts:** Visual project timelines and dependencies
 * - **Expense tracking:** Non-time costs allocated to projects
 * - **Multi-currency:** Projects in different currencies
 *
 * These can be added as separate modules or enhancements.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Projects ────────────────────────────────────────────────────
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            
            // Project identification
            $table->string('code', 30)->comment('Project code like PRJ001, CLIENT-2024-WEB');
            $table->string('name');
            $table->text('description')->nullable();
            
            // Client relationship (from existing customers table)
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            
            // Project lead (employee who manages this project)
            $table->foreignId('project_manager_id')->nullable()->constrained('employees')->nullOnDelete();
            
            // Status tracking
            $table->enum('status', ['planning', 'active', 'on_hold', 'completed', 'cancelled'])
                ->default('planning');
            
            // Timeline
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            
            // Budget tracking
            $table->decimal('budget_hours', 8, 2)->nullable()
                ->comment('Estimated hours for completion');
            $table->bigInteger('budget_amount_minor')->nullable()
                ->comment('Maximum billable amount in minor units');
            $table->string('currency', 3);
            
            // Billing configuration
            $table->enum('billing_type', ['hourly', 'fixed_rate', 'non_billable'])
                ->default('hourly');
            $table->bigInteger('default_rate_minor')->nullable()
                ->comment('Default hourly rate in minor units');
            
            // Project settings
            $table->boolean('requires_approval')->default(true)
                ->comment('Whether time entries need approval before billing');
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            
            $table->ulid('public_id')->unique();
            $table->timestamps();
            $table->softDeletes();
            
            $table->unique(['business_id', 'code']);
            $table->index(['business_id', 'status']);
            $table->index(['business_id', 'customer_id']);
        });
        
        // ── Project Employee Rates ─────────────────────────────────────────
        // Override default project rate for specific employees
        Schema::create('project_employee_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            
            $table->bigInteger('rate_minor')->comment('Hourly rate in minor units');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            
            $table->ulid('public_id')->unique();
            $table->timestamps();
            
            $table->unique(['project_id', 'employee_id', 'effective_from']);
            $table->index(['business_id', 'employee_id']);
        });
        
        // ── Time Entries ────────────────────────────────────────────────────
        Schema::create('time_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            
            // Time details
            $table->date('entry_date');
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->decimal('hours', 5, 2)->comment('Total hours worked');
            
            // Work description
            $table->text('description');
            $table->boolean('is_billable')->default(true);
            
            // Billing details (captured at time of entry)
            $table->bigInteger('rate_minor')->nullable()
                ->comment('Rate used for this entry (snapshot)');
            $table->bigInteger('billable_amount_minor')->nullable()
                ->comment('Amount to bill for this entry');
            $table->string('currency', 3);
            
            // Workflow status
            $table->enum('status', ['draft', 'submitted', 'approved', 'rejected', 'invoiced'])
                ->default('draft');
            
            // Approval tracking
            $table->foreignId('submitted_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            
            $table->foreignId('approved_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('approval_notes')->nullable();
            
            // Invoice tracking
            $table->foreignId('invoice_line_id')->nullable()
                ->constrained('invoice_lines')->nullOnDelete();
            $table->timestamp('invoiced_at')->nullable();
            
            $table->ulid('public_id')->unique();
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['business_id', 'employee_id', 'entry_date']);
            $table->index(['business_id', 'project_id', 'entry_date']);
            $table->index(['business_id', 'status']);
            $table->index(['project_id', 'is_billable', 'status']);
        });
        
        // ── Project Budgets (historical tracking) ──────────────────────────
        // Track budget changes over time for audit trail
        Schema::create('project_budget_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            
            $table->decimal('budget_hours', 8, 2)->nullable();
            $table->bigInteger('budget_amount_minor')->nullable();
            $table->string('currency', 3);
            
            $table->text('reason')->comment('Why the budget changed');
            $table->date('effective_from');
            
            $table->foreignId('revised_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            
            $table->ulid('public_id')->unique();
            $table->timestamps();
            
            $table->index(['project_id', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_budget_revisions');
        Schema::dropIfExists('time_entries');
        Schema::dropIfExists('project_employee_rates');
        Schema::dropIfExists('projects');
    }
};