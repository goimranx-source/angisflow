<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Employees, org structure, and attendance.
 *
 * **Why these tables:**
 *
 * A delivery rider who never signs in still has to be paid. Requiring every
 * employee to have a login means inventing credentials nobody uses, and a
 * person who leaves would either keep working access or lose their history.
 * Separating `employees` from `users` means someone on payroll need not have
 * an account, and someone who leaves retains their employment record without
 * keeping a live login.
 *
 * Owners who work in the business are employees too. There is no separate
 * owner-pay arrangement — a partner drawing a wage is on this payroll at that
 * wage, and that wage is an ordinary cost of trading.
 *
 * **Organization structure:**
 *
 * Three layers: department (what kind of work), position (job title + pay
 * band), and job level (a grade on the ladder). Kept separate because:
 * - A Packer and a Senior Packer share a department but not a salary.
 * - "Officer" outranks "Executive" at one company and the reverse at another.
 *   Job titles don't rank universally.
 * - Job levels provide a language-neutral seniority ranking (Hay, Mercer IPE).
 * - Positions carry the role/permissions, not the person directly. Hire a
 *   second Sales Representative and they get the same access without manual
 *   setup; a promotion changes access because it changed the job.
 *
 * **Attendance:**
 *
 * Daily tracking for hourly/daily-rate workers. A monthly salary can be
 * pro-rated from a calendar, but daily or hourly pay requires actual worked
 * hours. Absences, late arrivals, early departures tracked separately from
 * presence so patterns emerge (e.g., always late on Mondays).
 *
 * **Alternative rejected:**
 *
 * - Storing total hours instead of clock-in/out: loses the ability to detect
 *   patterns (late arrivals, frequent absences) and audit disputes.
 * - Check-in without location: for field staff, knowing *where* they clocked
 *   in matters for route planning and disputes.
 * - Commission as stored amounts: an order edited or refunded after payout
 *   keeps paying on a sale that no longer exists. Commission is always
 *   calculated from current order state using the stored *rate*.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Job levels ──────────────────────────────────────────────────
        //
        // A grade on the ladder. Rank 1 is the top, so new grades can be added
        // underneath without renumbering. Bands group ranks into the four kinds
        // of work every organization has: decide, manage, qualified, do.
        Schema::create('job_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);                    // "Senior Manager", "Executive"
            $table->enum('band', ['executive', 'management', 'professional', 'operations']);
            $table->unsignedTinyInteger('rank');            // 1=top (Board), 10=bottom (Operations)
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['business_id', 'is_active']);
            $table->index(['business_id', 'band', 'rank']);
        });

        // ── Departments ─────────────────────────────────────────────────
        //
        // What kind of work someone does — Packing, Support, Sourcing.
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);                    // "Operations", "Sales"
            $table->string('code', 20)->nullable();         // Short code for reports
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['business_id', 'is_active']);
            $table->unique(['business_id', 'code']);
        });

        // ── Positions ───────────────────────────────────────────────────
        //
        // A job title, carrying its pay band. The band seeds a new hire's
        // figure and shows when someone has drifted outside what the role is
        // worth — the question asked at review time.
        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('job_level_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('role_id')->nullable()->constrained()->nullOnDelete(); // What this job may do
            $table->string('title', 100);                   // "Senior Packer", "Sales Representative"
            $table->decimal('salary_min', 15, 2)->nullable(); // Pay band minimum
            $table->decimal('salary_max', 15, 2)->nullable(); // Pay band maximum
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['business_id', 'is_active']);
            $table->index(['business_id', 'department_id']);
        });

        // ── Employees ───────────────────────────────────────────────────
        //
        // A person on the payroll. May or may not have a login.
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('linked_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('location_id')->nullable(); // FK later when locations table exists
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('position_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('manager_id')->nullable()->constrained('employees')->nullOnDelete();

            $table->string('code', 30)->nullable();         // Employee number for timesheets/reports
            $table->string('name', 150);
            $table->string('phone', 30)->nullable();
            $table->string('email', 150)->nullable();
            $table->text('address')->nullable();
            $table->string('national_id', 50)->nullable();  // NIC, passport, tax number

            $table->date('date_of_birth')->nullable();
            $table->date('joined_on')->nullable();
            $table->date('left_on')->nullable();

            $table->decimal('base_salary', 15, 2)->default(0);
            $table->enum('pay_type', ['monthly', 'daily', 'hourly'])->default('monthly');
            $table->enum('payment_method', ['bank', 'cash', 'mobile'])->default('bank');
            $table->string('bank_account', 100)->nullable();

            $table->enum('status', ['active', 'on_leave', 'suspended', 'left'])->default('active');

            // Commission structure
            $table->enum('commission_type', ['none', 'percent_order', 'fixed_order'])->default('none');
            $table->decimal('commission_rate', 8, 4)->nullable(); // Percentage or fixed amount

            $table->text('notes')->nullable();
            $table->timestamp('portal_seen_at')->nullable(); // Last time they checked their portal
            $table->timestamps();
            $table->softDeletes();

            $table->index(['business_id', 'status']);
            $table->index(['business_id', 'linked_user_id']);
            $table->index(['business_id', 'manager_id']);
            $table->index(['business_id', 'department_id']);
            $table->index(['business_id', 'location_id']);
            $table->unique(['business_id', 'code']);
        });

        // ── Attendance records ──────────────────────────────────────────
        //
        // Daily clock-in/out tracking. Essential for daily/hourly-rate workers.
        // Monthly salaries can be pro-rated from a calendar; daily/hourly pay
        // requires actual worked hours.
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('location_id')->nullable(); // FK later when locations table exists

            $table->date('date');
            $table->time('clock_in')->nullable();
            $table->time('clock_out')->nullable();
            $table->decimal('hours_worked', 5, 2)->default(0); // Calculated or manual
            $table->decimal('hours_overtime', 5, 2)->default(0);

            // Absence tracking
            $table->enum('status', ['present', 'absent', 'on_leave', 'holiday', 'half_day'])->default('present');
            $table->enum('leave_type', ['sick', 'annual', 'unpaid', 'other'])->nullable();

            // For field staff - where did they clock in?
            $table->string('clock_in_lat', 20)->nullable();
            $table->string('clock_in_lng', 20)->nullable();
            $table->string('clock_out_lat', 20)->nullable();
            $table->string('clock_out_lng', 20)->nullable();

            $table->text('notes')->nullable();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->timestamps();

            $table->index(['business_id', 'employee_id', 'date']);
            $table->index(['business_id', 'date']);
            $table->index(['employee_id', 'date']);
            $table->unique(['employee_id', 'date']); // One record per employee per day
        });

        // ── Leave balances ──────────────────────────────────────────────
        //
        // Annual leave entitlements and usage. Separate from attendance so
        // balances persist across years and can be queried independently.
        Schema::create('leave_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();

            $table->unsignedSmallInteger('year');           // Calendar year
            $table->decimal('entitled_days', 5, 2)->default(0);
            $table->decimal('taken_days', 5, 2)->default(0);
            $table->decimal('carried_forward', 5, 2)->default(0); // From previous year

            $table->timestamps();

            $table->index(['business_id', 'employee_id', 'year']);
            $table->unique(['employee_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_balances');
        Schema::dropIfExists('attendances');
        Schema::dropIfExists('employees');
        Schema::dropIfExists('positions');
        Schema::dropIfExists('departments');
        Schema::dropIfExists('job_levels');
    }
};
