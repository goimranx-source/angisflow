<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The other side of the books: what the business owes.
 *
 * ── Why bills mirror invoices instead of sharing a table ─────────────────────
 *
 * They are structurally almost identical, and the temptation is one
 * `documents` table with a direction flag. It reads as economical and behaves
 * as a trap: every query afterwards has to remember the flag, and the one that
 * forgets it reports money you owe as money you are owed. The two also drift —
 * a bill needs the supplier's own reference and a tax figure you can reclaim; an
 * invoice needs terms, a customer PO and a document you send. Separate tables
 * keep each honest and make "AND direction = ?" impossible to forget, because
 * it does not exist.
 *
 * ── Expense claims are not bills ─────────────────────────────────────────────
 *
 * A bill is owed to a supplier. A claim is owed to a member of staff who spent
 * their own money, and it settles through payroll or petty cash rather than the
 * purchase ledger. Keeping them apart is what stops "accounts payable" quietly
 * including three months of somebody's taxi receipts.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Suppliers ───────────────────────────────────────────────────────
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('tax_number', 40)->nullable();
            $table->string('address')->nullable();
            $table->char('country', 2)->nullable();
            $table->char('currency', 3)->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->nullable();

            // Where this supplier's spend usually goes, so a bill from the
            // landlord suggests Rent without anybody choosing it every month.
            $table->foreignId('default_expense_account_id')->nullable()->constrained('ledger_accounts')->nullOnDelete();

            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['business_id', 'name']);
        });

        // ── Bills ───────────────────────────────────────────────────────────
        Schema::create('bills', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();

            $table->string('number', 40);
            // What the supplier calls it. Not unique — two suppliers both
            // number their invoices 001 and both are right.
            $table->string('supplier_reference', 120)->nullable();

            $table->date('bill_date');
            $table->date('due_date')->nullable();
            $table->string('status', 12)->default('draft'); // draft|approved|paid|void

            $table->char('currency', 3);
            $table->unsignedBigInteger('subtotal_minor')->default(0);
            $table->unsignedBigInteger('tax_minor')->default(0);
            $table->unsignedBigInteger('total_minor')->default(0);
            $table->unsignedBigInteger('paid_minor')->default(0);

            $table->text('notes')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'number']);
            $table->index(['business_id', 'status', 'due_date']);
            $table->index(['business_id', 'supplier_id', 'status']);
        });

        Schema::create('bill_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bill_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ledger_account_id')->nullable()->constrained('ledger_accounts')->nullOnDelete();
            $table->unsignedSmallInteger('line_no')->default(1);
            $table->string('description');
            $table->decimal('quantity', 14, 4)->default(1);
            $table->unsignedBigInteger('unit_price_minor')->default(0);
            $table->decimal('tax_rate', 7, 4)->default(0);
            $table->unsignedBigInteger('tax_minor')->default(0);
            $table->unsignedBigInteger('total_minor')->default(0);
            $table->char('currency', 3);
            $table->timestamps();

            $table->index(['bill_id', 'line_no']);
        });

        // ── Money going out, and what it settled ────────────────────────────
        //
        // Same shape as customer payments and for the same reasons: one
        // transfer often clears several bills, and a payment made before it is
        // matched is still money that left the account.
        Schema::create('bill_payments', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();

            $table->string('reference', 40);
            $table->date('paid_on');
            $table->string('method', 30)->default('bank');
            $table->string('external_ref', 120)->nullable();
            $table->foreignId('source_account_id')->constrained('ledger_accounts')->restrictOnDelete();

            $table->char('currency', 3);
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedBigInteger('allocated_minor')->default(0);
            $table->string('status', 12)->default('cleared');

            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'reference']);
            $table->index(['business_id', 'paid_on']);
        });

        Schema::create('bill_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bill_payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bill_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->date('allocated_on');
            $table->timestamps();

            $table->unique(['bill_payment_id', 'bill_id']);
            $table->index('bill_id');
        });

        // ── Expense claims ──────────────────────────────────────────────────
        Schema::create('expense_claims', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            // Points at a user for now; when employees exist this gains an
            // employee_id beside it rather than replacing it — the person who
            // claims and the login that submitted it are not always the same.
            $table->foreignId('claimant_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('number', 40);
            $table->date('claim_date');
            $table->string('title');

            // draft → submitted → approved → reimbursed, or rejected.
            // Nothing reaches the ledger before approval: an unapproved claim
            // is a request, not a liability.
            $table->string('status', 12)->default('draft');

            $table->char('currency', 3);
            $table->unsignedBigInteger('total_minor')->default(0);
            $table->unsignedBigInteger('reimbursed_minor')->default(0);

            $table->text('notes')->nullable();
            $table->string('rejected_reason')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'number']);
            $table->index(['business_id', 'status', 'claim_date']);
            $table->index(['business_id', 'claimant_id']);
        });

        Schema::create('expense_claim_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_claim_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ledger_account_id')->nullable()->constrained('ledger_accounts')->nullOnDelete();
            $table->unsignedSmallInteger('line_no')->default(1);
            $table->date('spent_on');
            $table->string('description');
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedBigInteger('tax_minor')->default(0);
            $table->char('currency', 3);
            $table->foreignId('receipt_media_id')->nullable()->constrained('media_items')->nullOnDelete();
            $table->timestamps();

            $table->index(['expense_claim_id', 'line_no']);
        });

        // ── Budgets ─────────────────────────────────────────────────────────
        //
        // A budget is per account per period, and the period is a month.
        // Anything coarser cannot answer "are we overspending yet" until the
        // year is nearly gone; anything finer is a forecast nobody maintains.
        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fiscal_year_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 80);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->char('currency', 3);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['business_id', 'starts_on', 'ends_on']);
        });

        Schema::create('budget_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ledger_account_id')->constrained('ledger_accounts')->cascadeOnDelete();
            // First of the month it applies to. A date rather than a
            // year/month pair so period arithmetic is date arithmetic.
            $table->date('period');
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->timestamps();

            $table->unique(['budget_id', 'ledger_account_id', 'period']);
            $table->index(['ledger_account_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_lines');
        Schema::dropIfExists('budgets');
        Schema::dropIfExists('expense_claim_lines');
        Schema::dropIfExists('expense_claims');
        Schema::dropIfExists('bill_payment_allocations');
        Schema::dropIfExists('bill_payments');
        Schema::dropIfExists('bill_lines');
        Schema::dropIfExists('bills');
        Schema::dropIfExists('suppliers');
    }
};
