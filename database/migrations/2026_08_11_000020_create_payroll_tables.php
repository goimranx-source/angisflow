<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll: batch wage processing, advances, expense claims settlement.
 *
 * **Why payroll runs as batches:**
 *
 * A thousand employees cannot be paid one form at a time, and no serious system
 * asks anyone to. A run covers a period and a scope, builds a payslip for every
 * person in it, and produces one document a manager can take to whoever holds
 * the money.
 *
 * **Payroll accounting (two postings, and the split matters):**
 *
 *   On approval   DR Staff Salaries (6450)   CR Accrued Salaries (2100)
 *                 The wage bill belongs to the month it was earned in, whether
 *                 or not anyone has been paid yet. A month closed with wages
 *                 unpaid would show a profit the business does not have.
 *
 *   On payment    DR Accrued Salaries (2100)  CR Cash / Bank
 *                 The debt clears. The expense does not move — it was never in
 *                 doubt, only unpaid.
 *
 * Posting only when money goes out would drop a month's wages into whichever
 * month the cash happened to leave, which is precisely the distortion accrual
 * accounting exists to prevent.
 *
 * **Payslips copied, not calculated:**
 *
 * Employee name, code, position and location are copied onto the payslip rather
 * than read through the relation every time. A payslip states what was true that
 * month; a later raise, transfer or promotion must not rewrite what someone was
 * already paid. The same principle applies to transactions everywhere in the
 * system — a document's description stays what it was, even if the product it
 * named has since been renamed.
 *
 * **Advances and deductions:**
 *
 * Money taken in advance (staff loan, emergency payout) is an asset — the
 * business is owed it back. It comes back automatically on the next payslip,
 * capped at the pay so nobody walks away with a negative wage. The employee code
 * goes in every advance transaction description because the advance account is
 * shared: without it there would be no way to say whose balance is whose when
 * reading the ledger.
 *
 * **Note on expense claims:**
 *
 * The expense_claims table already exists (from migration 2026_08_11_000003).
 * This migration adds staff_advances for tracking salary advances, which are
 * recovered via payroll deductions.
 *
 * **Alternative rejected:**
 *
 * Paying monthly staff from a formula rather than attendance would work for
 * many, but not for daily/hourly workers, not for part-month employment
 * (joiners and leavers), and not for unpaid leave. Deriving pay from attendance
 * and employment dates handles all of those, which is why every actual payroll
 * system does.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Payroll runs ────────────────────────────────────────────────
        //
        // One month's wage bill, for one scope, as a single document.
        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            $table->string('reference', 50)->nullable();       // PAY-202607, PAY-202607-HQ
            $table->date('period_start');
            $table->date('period_end');

            // Scope: what subset of employees this run covers
            $table->unsignedBigInteger('location_id')->nullable();    // Specific hub/outlet, or null = everyone
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();

            // Status is the whole story
            $table->enum('status', ['draft', 'approved', 'paid', 'cancelled'])->default('draft');

            // Totals (refreshed from payslips)
            $table->decimal('gross_total', 15, 2)->default(0);
            $table->decimal('deduction_total', 15, 2)->default(0);
            $table->decimal('net_total', 15, 2)->default(0);
            $table->unsignedSmallInteger('employee_count')->default(0);

            // Ledger entries that tell the accounting story
            $table->unsignedBigInteger('accrual_entry_id')->nullable();   // DR Salary Expense, CR Accrued
            $table->unsignedBigInteger('payment_entry_id')->nullable();   // DR Accrued, CR Cash/Bank

            // Approval and payment tracking
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->date('paid_on')->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'status']);
            $table->index(['business_id', 'period_start', 'period_end']);
            $table->index(['business_id', 'location_id']);
            $table->index(['business_id', 'department_id']);
            $table->unique(['business_id', 'reference']);
        });

        // ── Payslips ────────────────────────────────────────────────────
        //
        // What one person was paid for one period. The snapshot, not a pointer.
        Schema::create('payslips', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();

            // Snapshot fields — what was true that month, copied not calculated
            $table->string('employee_name', 150);
            $table->string('employee_code', 30)->nullable();
            $table->string('position_title', 100)->nullable();
            $table->string('location_name', 100)->nullable();

            // Pay breakdown
            $table->decimal('base_pay', 15, 2)->default(0);
            $table->decimal('earnings_total', 15, 2)->default(0);    // Sum of earning lines
            $table->decimal('deductions_total', 15, 2)->default(0);  // Sum of deduction lines
            $table->decimal('gross_pay', 15, 2)->default(0);         // Total before deductions
            $table->decimal('net_pay', 15, 2)->default(0);           // What they actually get

            $table->enum('payment_method', ['bank', 'cash', 'mobile'])->default('bank');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['payroll_run_id', 'employee_name']);
            $table->index(['business_id', 'employee_id']);
        });

        // ── Payslip lines ───────────────────────────────────────────────
        //
        // One component of pay. Kept as lines because "why is this less than
        // last month" has to have an answer on the paper.
        Schema::create('payslip_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payslip_id')->constrained()->cascadeOnDelete();

            $table->enum('kind', ['earning', 'deduction']);
            $table->string('code', 30);                          // base, overtime, commission, advance, etc.
            $table->string('label', 150);                        // What shows on the slip

            // Working: "5 days × $250" where a rate was used, otherwise null
            $table->decimal('quantity', 10, 3)->nullable();
            $table->decimal('rate', 15, 4)->nullable();
            $table->decimal('amount', 15, 2);

            // If this line settles a balance elsewhere (advance recovered, expense claim paid)
            $table->string('settles_ledger_account_code', 10)->nullable();

            $table->timestamps();

            $table->index(['payslip_id', 'kind']);
        });

        // ── Staff advances ──────────────────────────────────────────────
        //
        // Money given to an employee against future pay. An asset, not an
        // expense — the business is owed it back. Recovered automatically on
        // the next payslip.
        Schema::create('staff_advances', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();

            $table->string('reference', 50)->nullable();
            $table->date('advance_date');
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3)->default('USD');
            $table->text('reason')->nullable();

            // Ledger tracking
            $table->unsignedBigInteger('advance_entry_id')->nullable();   // DR Advances (1350), CR Cash
            $table->decimal('recovered_amount', 15, 2)->default(0);       // How much has come back
            $table->decimal('balance', 15, 2);                             // Outstanding

            $table->enum('status', ['active', 'recovered', 'written_off'])->default('active');

            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->timestamps();

            $table->index(['business_id', 'employee_id', 'status']);
            $table->index(['business_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_advances');
        Schema::dropIfExists('payslip_lines');
        Schema::dropIfExists('payslips');
        Schema::dropIfExists('payroll_runs');
    }
};
