<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Getting the cash back from the couriers.
 *
 * ── The mistake almost every system makes ────────────────────────────────────
 *
 * Cash on delivery gets booked as a cash sale the moment the parcel is marked
 * delivered. It is not. At that moment the money is in a rider's pocket, then a
 * hub's safe, then a courier's bank account, and it reaches ours two weeks
 * later minus a fee — if it reaches us at all.
 *
 * What actually happens on delivery is that a debt changes hands: the customer
 * stops owing us and the courier starts. That is a transfer between two
 * receivables, not a receipt:
 *
 *     DR  1250 Courier Receivable      they are holding our money
 *     CR  1200 Accounts Receivable     the customer has paid
 *
 * Only when they remit does cash arrive:
 *
 *     DR  1100 Bank                    what landed
 *     DR  6700 Courier Charge          what they kept
 *     CR  1250 Courier Receivable      the debt is cleared
 *
 * Systems that skip the middle step report cash they do not have, cannot age
 * what a courier owes, and discover a courier has gone quiet about forty
 * thousand taka only when somebody eventually counts.
 *
 * ── Why a settlement has lines ───────────────────────────────────────────────
 *
 * Because the total is the least interesting part. A courier remits for two
 * hundred parcels, and the questions worth answering are per parcel: which ones
 * are in this payment, which delivered ones are still missing from every
 * payment, and which are in here that we never marked delivered. A settlement
 * with only a total can answer none of them, so the discrepancies are found by
 * hand or not at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courier_settlements', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('courier_connection_id')->constrained()->restrictOnDelete();

            $table->string('number', 40);
            // Their reference for the payment, so a bank line can be tied to it.
            $table->string('external_ref', 120)->nullable();
            $table->date('settled_on');

            // draft      being built and matched, posts nothing
            // confirmed  agreed and posted to the ledger
            // disputed   we do not agree with their figures
            $table->string('status', 12)->default('draft');

            $table->char('currency', 3);

            // What they say. Kept separate from what we work out, because when
            // the two differ that difference is the entire point of the
            // exercise — collapsing them into one column hides it.
            $table->unsignedBigInteger('declared_gross_minor')->default(0);
            $table->unsignedBigInteger('declared_fee_minor')->default(0);
            $table->unsignedBigInteger('declared_net_minor')->default(0);

            // What the matched lines actually come to.
            $table->unsignedBigInteger('matched_gross_minor')->default(0);
            $table->unsignedBigInteger('matched_fee_minor')->default(0);
            $table->unsignedBigInteger('matched_net_minor')->default(0);

            // Signed: positive means they paid more than our lines explain.
            $table->bigInteger('variance_minor')->default(0);

            $table->foreignId('deposit_account_id')->nullable()->constrained('ledger_accounts')->nullOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();

            $table->text('notes')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'number']);
            $table->index(['business_id', 'status', 'settled_on']);
            $table->index(['courier_connection_id', 'settled_on']);
        });

        Schema::create('courier_settlement_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('courier_settlement_id')->constrained()->cascadeOnDelete();
            // Nullable on purpose: a courier paying for a parcel we have no
            // record of is a real and important case, and a line that cannot be
            // stored is a discrepancy nobody sees.
            $table->foreignId('shipment_id')->nullable()->constrained()->nullOnDelete();

            // As it appeared on their statement, so an unmatched line still
            // says what it was about.
            $table->string('tracking_number', 80)->nullable();

            $table->unsignedBigInteger('collected_minor')->default(0);
            $table->unsignedBigInteger('fee_minor')->default(0);
            $table->unsignedBigInteger('net_minor')->default(0);

            // matched     agrees with what we expected
            // short       they collected less than the parcel was for
            // over        they collected more
            // unknown     no shipment of ours has that tracking number
            // duplicate   already settled on an earlier statement
            $table->string('match_status', 12)->default('matched');
            $table->string('note')->nullable();

            $table->timestamps();

            $table->index(['courier_settlement_id', 'match_status']);
            $table->index('shipment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courier_settlement_lines');
        Schema::dropIfExists('courier_settlements');
    }
};
