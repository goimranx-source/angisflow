<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The commercial record behind an account's status.
 *
 * Deliberately off the hot path. The application asks accounts.status on every
 * request; it only ever reads this table when somebody opens billing or when
 * the payment provider says something changed. Keeping the two apart means the
 * request that matters never joins to a table full of provider state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();

            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained();

            $table->string('status', 20)->default('trialing');
            $table->unsignedInteger('quantity')->default(1);

            $table->timestamp('started_at')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('ended_at')->nullable();

            // Whoever takes the money — bKash, SSLCommerz, Stripe. Named rather
            // than assumed, because a business selling in this market will not
            // be on one processor forever.
            $table->string('provider', 32)->nullable();
            $table->string('provider_subscription_id')->nullable();

            $table->json('meta')->nullable();

            $table->timestamps();

            // "This account's subscriptions, newest first" — the only query the
            // application itself makes here.
            $table->index(['account_id', 'id']);

            // For the renewal sweep: every live subscription whose period ends
            // today. Leading with status keeps it off the ninety-nine percent
            // that are not due.
            $table->index(['status', 'current_period_end']);

            // A provider webhook arrives knowing only its own id.
            $table->index(['provider', 'provider_subscription_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
