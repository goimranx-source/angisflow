<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The ledger everything else in the tool eventually posts into.
 *
 * ── Why this is a rebuild and not a port ─────────────────────────────────────
 *
 * The first Prism called itself double-entry and was not. Its transactions
 * table carried one debit_account_id and one credit_account_id, which is
 * single-entry wearing a second column: a sale with goods, delivery, tax and
 * cost of sales is four or five postings, and there is nowhere to put them. It
 * had a transaction_lines table, but the lines held only an account and a
 * total, with no side — so they were an invoice's presentation, not the ledger.
 * Anything genuinely multi-legged had to be faked as several transactions that
 * nothing tied together or checked.
 *
 * Here an entry has as many lines as the event needs, each line is a debit or a
 * credit, and an entry cannot be posted unless they agree. That check is the
 * entire point of double-entry: it is the arithmetic that makes a mistake
 * visible instead of merely wrong.
 *
 * ── Three other things the old schema got wrong ──────────────────────────────
 *
 * DECIMAL(15,2) for money. Exact in the database, and PHP has no decimal type,
 * so every read came back a string and became a float somewhere. Minor units in
 * a BIGINT instead — see App\Domain\Shared\ValueObjects\Money.
 *
 * amount_base, a derived column the service was trusted to fill in before every
 * save. Kept here too, because reporting across currencies cannot re-convert a
 * decade of history at today's rate — but written by the posting service in one
 * place, alongside the rate it used, so the figure can always be explained.
 *
 * softDeletes on the ledger. A posted entry is a statement about what happened;
 * you do not delete it, you reverse it and leave both visible. That is what an
 * audit trail is, and it is the difference between books somebody will trust
 * and books somebody has to take on faith.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Fiscal years ────────────────────────────────────────────────────
        //
        // A year is what "close the books" closes. Postings into a closed one
        // are refused, which is the only thing that stops last year's reported
        // profit changing after it has been reported.
        Schema::create('fiscal_years', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->boolean('is_closed')->default(false);
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'starts_on', 'ends_on']);
        });

        // ── Chart of accounts ───────────────────────────────────────────────
        //
        // Named ledger_accounts, not accounts: `accounts` in this codebase is
        // already a subscriber. Two different things called the same word in
        // one schema is how somebody eventually joins the wrong one.
        Schema::create('ledger_accounts', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('ledger_accounts')->nullOnDelete();

            $table->string('code', 20);
            $table->string('name');
            $table->string('type', 20);      // asset|liability|equity|revenue|expense
            $table->string('subtype', 40)->nullable();
            $table->string('description')->nullable();

            // Header accounts total their children and take no postings of
            // their own. Without this, somebody posts to "Inventory" as well as
            // to "Inventory — Raw Materials" and the subtotal double-counts.
            $table->boolean('is_postable')->default(true);

            // Cash and bank. The cash book is "what could I actually spend
            // today", which is a different question from "what do I own".
            $table->boolean('is_spendable')->default(false);

            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            // Per business, not per account: two sets of books in one group
            // each get their own 1000 Cash, and they are different accounts.
            $table->unique(['business_id', 'code']);
            $table->index(['business_id', 'type', 'sort_order']);
        });

        // ── Journal entries ─────────────────────────────────────────────────
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fiscal_year_id')->nullable()->constrained()->nullOnDelete();

            $table->string('reference', 40)->nullable();
            $table->date('entry_date');
            $table->string('description');
            $table->text('memo')->nullable();

            // draft may still be edited; posted is final and may only be
            // reversed; reversed points at the entry that undid it.
            $table->string('status', 12)->default('draft');

            $table->string('source', 20)->default('manual'); // manual|import|api|webhook|system
            $table->string('source_platform', 40)->nullable();
            $table->string('source_ref', 120)->nullable();

            // What produced this entry — an order, a payslip, a stock movement.
            // Nullable and free-form so the ledger does not need a foreign key
            // to every module that will ever post into it.
            $table->string('subject_type', 60)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            $table->char('currency', 3);
            $table->char('base_currency', 3);

            // Both directions, so either entry leads to the other without a
            // scan. Set on the pair when a reversal is posted.
            $table->foreignId('reverses_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('reversed_by_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();

            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'reference']);
            $table->index(['business_id', 'entry_date']);
            $table->index(['business_id', 'status', 'entry_date']);
            $table->index(['subject_type', 'subject_id']);
            $table->index(['source_platform', 'source_ref']);
        });

        // ── Journal lines ───────────────────────────────────────────────────
        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ledger_account_id')->constrained('ledger_accounts')->restrictOnDelete();

            // Denormalised from the entry so a trial balance is one table scan
            // rather than a join per line. The ledger is the most-read thing in
            // the tool and these never change once posted.
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->date('entry_date');

            $table->unsignedSmallInteger('line_no')->default(1);
            $table->string('description')->nullable();

            // Exactly one of these is non-zero on any line. Two columns rather
            // than one signed amount because that is how a trial balance is
            // read and printed, and because "sum(debit) = sum(credit)" is then
            // a check anybody can run by eye or in SQL.
            $table->unsignedBigInteger('debit_minor')->default(0);
            $table->unsignedBigInteger('credit_minor')->default(0);
            $table->char('currency', 3);

            // The same amounts in the book's own currency. A report covering a
            // decade cannot re-convert at today's rate; it has to use what the
            // money was worth on the day, so the rate is kept with the line
            // that used it.
            $table->unsignedBigInteger('base_debit_minor')->default(0);
            $table->unsignedBigInteger('base_credit_minor')->default(0);
            $table->char('base_currency', 3);
            $table->decimal('exchange_rate', 18, 8)->default(1);

            $table->timestamps();

            $table->index(['business_id', 'ledger_account_id', 'entry_date']);
            $table->index(['journal_entry_id', 'line_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journal_entries');
        Schema::dropIfExists('ledger_accounts');
        Schema::dropIfExists('fiscal_years');
    }
};
