<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invoices, payments, and the link between them.
 *
 * ── Why payments are not a column on the invoice ─────────────────────────────
 *
 * The tempting shape is `invoices.paid_minor`, incremented as money arrives.
 * It survives exactly until the first real customer, who sends one transfer
 * covering three invoices, or pays half of one now and half next month, or
 * overpays by a rounding difference and leaves the balance sitting as credit.
 * A single column answers none of those and quietly loses the information
 * needed to answer them later.
 *
 * So a payment is its own event — money that arrived, on a date, into a
 * particular account — and payment_allocations say how much of it was applied
 * to which invoice. One payment may settle many invoices; one invoice may take
 * many payments; and money received but not yet allocated is visible as such
 * rather than being invisible or forced somewhere wrong.
 *
 * ── Why totals are stored, not computed ──────────────────────────────────────
 *
 * An invoice total is the sum of its lines until the day somebody edits a tax
 * rate or a line is deleted, at which point every historical invoice silently
 * restates itself. An issued invoice is a document that was sent to somebody:
 * what it said is a fact about the past. The totals are frozen when it is
 * issued, and the ledger posting is made from those same frozen figures.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Customers ───────────────────────────────────────────────────────
        //
        // The minimum a set of books needs to say who owes it money. The full
        // customer record — segments, dedup, merge, channel identities, risk
        // scoring — is its own job later; this is the part invoicing cannot
        // work without, built properly rather than stubbed.
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('company')->nullable();
            $table->string('tax_number', 40)->nullable();

            $table->string('billing_address')->nullable();
            $table->string('billing_city', 80)->nullable();
            $table->string('billing_postcode', 20)->nullable();
            $table->char('billing_country', 2)->nullable();

            // What this customer is billed in. Usually the book's currency, and
            // occasionally not — an exporter billing abroad.
            $table->char('currency', 3)->nullable();

            // Days from issue to due. Null means the business default.
            $table->unsignedSmallInteger('payment_terms_days')->nullable();

            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['business_id', 'name']);
            $table->index(['business_id', 'email']);
            $table->index(['business_id', 'phone']);
        });

        // ── Invoices ────────────────────────────────────────────────────────
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();

            $table->string('number', 40);
            $table->date('issue_date');
            $table->date('due_date')->nullable();

            // draft   — being written, changes nothing, posts nothing
            // issued  — sent; totals frozen, posted to the ledger
            // paid    — settled in full
            // void    — cancelled; the ledger posting is reversed, not removed
            $table->string('status', 12)->default('draft');

            $table->char('currency', 3);

            // Frozen at issue. See the note above about restating history.
            $table->unsignedBigInteger('subtotal_minor')->default(0);
            $table->unsignedBigInteger('discount_minor')->default(0);
            $table->unsignedBigInteger('tax_minor')->default(0);
            $table->unsignedBigInteger('shipping_minor')->default(0);
            $table->unsignedBigInteger('total_minor')->default(0);

            // Kept in step by the allocation service inside its transaction, so
            // "who owes me money" is an indexed read rather than a sum over
            // every allocation ever made.
            $table->unsignedBigInteger('paid_minor')->default(0);

            $table->string('reference', 120)->nullable();  // the customer's own PO
            $table->text('notes')->nullable();
            $table->text('terms')->nullable();

            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamp('issued_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'number']);
            $table->index(['business_id', 'status', 'due_date']);
            $table->index(['business_id', 'customer_id', 'status']);
        });

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ledger_account_id')->nullable()->constrained('ledger_accounts')->nullOnDelete();

            $table->unsignedSmallInteger('line_no')->default(1);
            $table->string('description');

            // Quantity is not money, and it is genuinely fractional — 2.5 hours,
            // 0.75 kg. Held at four places, which is enough for time, weight and
            // volume without pretending to be exact currency.
            $table->decimal('quantity', 14, 4)->default(1);
            $table->string('unit', 20)->nullable();

            $table->unsignedBigInteger('unit_price_minor')->default(0);
            $table->unsignedBigInteger('discount_minor')->default(0);
            $table->decimal('tax_rate', 7, 4)->default(0);   // percent, e.g. 15.0000
            $table->unsignedBigInteger('tax_minor')->default(0);
            $table->unsignedBigInteger('total_minor')->default(0);
            $table->char('currency', 3);

            $table->timestamps();

            $table->index(['invoice_id', 'line_no']);
        });

        // ── Payments ────────────────────────────────────────────────────────
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();

            $table->string('reference', 40);
            $table->date('received_on');

            // in  — money from a customer; out — a refund back to them.
            $table->string('direction', 4)->default('in');

            $table->string('method', 30)->default('cash'); // cash|bank|card|mobile|cheque|other
            $table->string('external_ref', 120)->nullable();

            // Where the money landed. A payment that does not say which account
            // it went into cannot be posted, and cannot be reconciled against a
            // statement later.
            $table->foreignId('deposit_account_id')->constrained('ledger_accounts')->restrictOnDelete();

            $table->char('currency', 3);
            $table->unsignedBigInteger('amount_minor');

            // What has been applied to invoices. The remainder is credit the
            // customer holds — visible, rather than lost.
            $table->unsignedBigInteger('allocated_minor')->default(0);

            $table->string('status', 12)->default('cleared'); // pending|cleared|bounced
            $table->text('notes')->nullable();

            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'reference']);
            $table->index(['business_id', 'received_on']);
            $table->index(['business_id', 'customer_id']);
        });

        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->date('allocated_on');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // One payment may hit an invoice once. Applying more is an edit to
            // the existing allocation, not a second row, so the two cannot
            // drift apart or be counted twice.
            $table->unique(['payment_id', 'invoice_id']);
            $table->index('invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('customers');
    }
};
