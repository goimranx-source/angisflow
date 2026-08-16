<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Working out who is likely to cost you money.
 *
 * ── The problem this actually solves ─────────────────────────────────────────
 *
 * In a cash-on-delivery market a fake order costs real money. Nobody pays
 * anything up front, so an order placed with a made-up phone number gets
 * picked, packed, and driven across a city before failing at a door that was
 * never going to open — and the courier charges for the delivery and for the
 * return. Do that a hundred times and it is a serious loss, made entirely of
 * transactions that each looked ordinary.
 *
 * Prepaid businesses have a milder version of the same problem: serial
 * returners, address-hoppers, chargeback farmers.
 *
 * ── Why the score is stored decomposed ───────────────────────────────────────
 *
 * A single number nobody can pull apart is a number nobody will act on. A shop
 * owner shown "risk 73" ignores it; shown "4 of their last 6 parcels came back
 * undelivered" they ring the customer before despatch. So every signal that
 * contributed is kept beside the total, with its own weight and its own
 * evidence — and a score is only ever as good as the sentence it can produce.
 *
 * ── Why nothing here blocks anything ─────────────────────────────────────────
 *
 * A false positive turns away a real customer, and the business never finds out
 * it happened. So this advises: it can require prepayment, ask for
 * confirmation, or flag for a call — all reversible, all visible. Only an
 * explicit human block stops an order, and that block is a row somebody signed
 * their name to.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── The computed picture ────────────────────────────────────────────
        Schema::create('customer_risk_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            // 0–100. Higher is worse.
            $table->unsignedTinyInteger('score')->default(0);
            // trusted | normal | watch | high
            $table->string('band', 10)->default('normal');

            // Every signal that fired, with its weight and the evidence. The
            // reason this is a JSON column rather than a table: it is written
            // and read as a whole, always, and never queried by its contents.
            $table->json('signals')->nullable();

            // The counts behind the signals, kept so a profile can be shown
            // without re-running every query that produced it.
            $table->unsignedInteger('orders_total')->default(0);
            $table->unsignedInteger('orders_delivered')->default(0);
            $table->unsignedInteger('orders_rto')->default(0);
            $table->unsignedInteger('orders_returned')->default(0);
            $table->unsignedInteger('failed_attempts')->default(0);
            $table->unsignedBigInteger('cod_exposure_minor')->default(0);

            $table->timestamp('computed_at')->nullable();
            $table->timestamps();

            $table->unique('customer_id');
            $table->index(['business_id', 'band', 'score']);
        });

        // ── What a person decided ───────────────────────────────────────────
        //
        // Kept apart from the computed profile on purpose. A score is an
        // opinion that changes every time it is recalculated; a decision is a
        // fact about what somebody chose, and it must survive recalculation.
        Schema::create('customer_risk_flags', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            // block         no orders at all
            // prepay_only   they may buy, but not on delivery
            // trusted       never mind what the score says
            // watch         a note, no effect
            $table->string('kind', 12);
            $table->string('reason');

            // A block with no end date is a decision nobody revisits. Optional,
            // because some are genuinely permanent, but offered.
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('lifted_at')->nullable();
            $table->string('lifted_reason')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('lifted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'customer_id', 'kind']);
            $table->index(['business_id', 'kind', 'lifted_at']);
        });

        // ── How much each signal is worth, per business ─────────────────────
        //
        // A 30% return rate is ordinary in one market and alarming in another,
        // and a fixed weighting shipped by us would be wrong for most
        // subscribers in one direction or the other. Defaults that can be
        // tuned, rather than constants that cannot.
        Schema::create('risk_signal_weights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            $table->string('signal', 40);
            $table->unsignedTinyInteger('weight');
            $table->boolean('is_enabled')->default(true);
            // The point at which the signal starts to count — "more than 2
            // failed deliveries", "over 40% returned".
            $table->decimal('threshold', 10, 4)->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'signal'], 'signal_weight_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_signal_weights');
        Schema::dropIfExists('customer_risk_flags');
        Schema::dropIfExists('customer_risk_profiles');
    }
};
