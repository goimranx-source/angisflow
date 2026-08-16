<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The front of the funnel: who might buy, and what it is worth.
 *
 * ── Pipeline stages are data, per business ───────────────────────────────────
 *
 * A hardcoded Lead → Qualified → Proposal → Won is somebody else's sales
 * process. A wholesaler has "sample sent"; a clinic has "consultation booked";
 * an agency has "scoping call". Ship a fixed list and every subscriber either
 * bends their process to it or stops using the pipeline — usually the second,
 * quietly, and the forecast is then built on stages nobody updates.
 *
 * Same lesson as the module catalogue and the courier statuses: the vocabulary
 * a subscriber works in belongs in a table they control. What stays in code is
 * the small set of *outcomes* — open, won, lost — because reporting has to mean
 * the same thing everywhere and "won" cannot be a matter of opinion.
 *
 * ── A lead is not deleted when it converts ───────────────────────────────────
 *
 * It is marked converted and linked to what it became. Deleting it destroys the
 * only record of where a customer came from, which is the question every
 * marketing decision depends on — and it makes conversion rate uncomputable,
 * because the denominator has been thrown away.
 *
 * ── Why quotes are not invoices ──────────────────────────────────────────────
 *
 * A quote is an offer that may expire, be revised three times, and never be
 * accepted. An invoice is a demand for money that posts to the ledger. Reusing
 * one table for both means either draft invoices polluting the receivables
 * figure, or a quote that cannot be revised without rewriting history. A quote
 * that is accepted *creates* an order, and the order raises the invoice.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Stages ──────────────────────────────────────────────────────────
        Schema::create('pipeline_stages', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            $table->string('name', 60);
            $table->string('slug', 60);
            // open | won | lost — what this stage means for reporting. The
            // subscriber names the stage; the outcome is ours, because a
            // forecast cannot be built on a word whose meaning varies.
            $table->string('outcome', 8)->default('open');

            // The default likelihood of a deal in this stage closing. A number
            // per stage rather than per deal, because most people never set one
            // per deal and a forecast built on nulls is no forecast.
            $table->unsignedTinyInteger('probability')->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('colour', 20)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['business_id', 'slug']);
            $table->index(['business_id', 'sort_order']);
        });

        // ── Leads ───────────────────────────────────────────────────────────
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('company')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->char('country', 2)->nullable();

            // Where they came from, and the campaign that brought them. The
            // pair is what makes "which channel actually produces revenue"
            // answerable rather than a matter of belief.
            $table->string('source', 40)->nullable();
            $table->string('campaign', 80)->nullable();

            // new | working | converted | disqualified
            $table->string('status', 12)->default('new');
            $table->string('disqualified_reason')->nullable();

            // Never deleted on conversion — see the note at the top.
            $table->foreignId('converted_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->timestamp('converted_at')->nullable();

            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamp('last_contacted_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['business_id', 'status']);
            $table->index(['business_id', 'source']);
            $table->index(['business_id', 'owner_id', 'status']);
            $table->index(['business_id', 'email']);
            $table->index(['business_id', 'phone']);
        });

        // ── Deals ───────────────────────────────────────────────────────────
        Schema::create('deals', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            $table->foreignId('pipeline_stage_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();

            $table->string('title');
            $table->char('currency', 3);
            $table->unsignedBigInteger('value_minor')->default(0);

            // Copied from the stage when the deal moves, then editable. A
            // salesperson who knows this particular deal is a long shot must be
            // able to say so without changing the stage's default for everyone.
            $table->unsignedTinyInteger('probability')->default(0);

            $table->date('expected_close_on')->nullable();
            $table->date('closed_on')->nullable();
            $table->string('outcome', 8)->default('open');   // open | won | lost
            $table->string('lost_reason')->nullable();

            // What it became. The link that lets "we won 40 deals" be checked
            // against "we invoiced 40 orders".
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();

            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            // When it last moved. The number behind "these deals have gone
            // quiet", which is the most useful thing a pipeline can tell you.
            $table->timestamp('stage_changed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['business_id', 'outcome', 'expected_close_on']);
            $table->index(['business_id', 'pipeline_stage_id']);
            $table->index(['business_id', 'owner_id', 'outcome']);
        });

        // Every move between stages, so cycle time and drop-off are real
        // measurements rather than guesses from the current state.
        Schema::create('deal_stage_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_stage_id')->nullable()->constrained('pipeline_stages')->nullOnDelete();
            $table->foreignId('to_stage_id')->nullable()->constrained('pipeline_stages')->nullOnDelete();
            $table->unsignedInteger('days_in_previous')->nullable();
            $table->foreignId('moved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('moved_at');
            $table->timestamps();

            $table->index(['deal_id', 'moved_at']);
        });

        // ── Quotes ──────────────────────────────────────────────────────────
        Schema::create('quotes', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('deal_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();

            $table->string('number', 40);
            // Revisions are new rows pointing at the original, not edits. What
            // was sent on Tuesday has to stay readable after Thursday's
            // revision, because the customer is looking at Tuesday's.
            $table->foreignId('supersedes_id')->nullable()->constrained('quotes')->nullOnDelete();
            $table->unsignedSmallInteger('version')->default(1);

            $table->date('issued_on');
            $table->date('valid_until')->nullable();
            // draft | sent | accepted | declined | expired | superseded
            $table->string('status', 12)->default('draft');

            $table->char('currency', 3);
            $table->unsignedBigInteger('subtotal_minor')->default(0);
            $table->unsignedBigInteger('discount_minor')->default(0);
            $table->unsignedBigInteger('tax_minor')->default(0);
            $table->unsignedBigInteger('total_minor')->default(0);

            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->text('terms')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'number']);
            $table->index(['business_id', 'status', 'valid_until']);
            $table->index('deal_id');
        });

        Schema::create('quote_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedSmallInteger('line_no')->default(1);
            $table->string('description');
            $table->decimal('quantity', 18, 4)->default(1);
            $table->unsignedBigInteger('unit_price_minor')->default(0);
            $table->unsignedBigInteger('discount_minor')->default(0);
            $table->decimal('tax_rate', 7, 4)->default(0);
            $table->unsignedBigInteger('tax_minor')->default(0);
            $table->unsignedBigInteger('total_minor')->default(0);
            $table->char('currency', 3);
            $table->timestamps();

            $table->index(['quote_id', 'line_no']);
        });

        // ── Activities ──────────────────────────────────────────────────────
        //
        // Against anything: a lead, a deal, a customer, a quote. Polymorphic
        // because the alternative is four near-identical tables, and the
        // question "what have we done about this" is the same question
        // regardless of what "this" is.
        Schema::create('crm_activities', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            $table->string('subject_type', 60);
            $table->unsignedBigInteger('subject_id');

            // call | email | meeting | note | task | message
            $table->string('kind', 12)->default('note');
            $table->string('summary');
            $table->text('body')->nullable();

            // A task has a due date and can be done; a note cannot. One table
            // with both, because a follow-up is a note that has not happened
            // yet and splitting them means copying it across when it does.
            $table->timestamp('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('occurred_at')->nullable();

            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
            $table->index(['business_id', 'owner_id', 'due_at']);
            $table->index(['business_id', 'kind', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_activities');
        Schema::dropIfExists('quote_lines');
        Schema::dropIfExists('quotes');
        Schema::dropIfExists('deal_stage_events');
        Schema::dropIfExists('deals');
        Schema::dropIfExists('leads');
        Schema::dropIfExists('pipeline_stages');
    }
};
