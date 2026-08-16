<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sign-ins, sign-outs, and every attempt that failed.
 *
 * Kept apart from activity_events for a reason that only shows up the first
 * time somebody is attacked: the most important rows here have no account at
 * all. A password-spray run against ten thousand addresses that do not exist
 * produces ten thousand events belonging to nobody, and a table scoped to a
 * subscriber has nowhere to put them — which is how that attack becomes
 * invisible.
 *
 * It also has a different lifecycle. Business history is kept for years because
 * an auditor may ask; authentication history is kept for weeks because a
 * security team may ask, and holding IP addresses longer than that is a
 * liability rather than an asset.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auth_events', function (Blueprint $table) {
            $table->bigIncrements('id');

            // Both nullable: a failed attempt against an address nobody
            // registered belongs to no account and no user, and that is exactly
            // the row worth keeping.
            $table->unsignedBigInteger('account_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();

            // 'login', 'login.failed', 'logout', 'password.reset',
            // 'two_factor.passed', 'two_factor.failed', 'passkey.registered'.
            $table->string('event', 40);

            // How they proved who they were: 'password', 'passkey', 'recovery'.
            $table->string('method', 20)->nullable();

            // Stored lowercased and trimmed so a spray using mixed case still
            // groups. Not a foreign key — the whole point is that it often
            // matches nothing.
            $table->string('email')->nullable();

            $table->string('ip_address', 45)->nullable();

            // The agent string is long, repetitive and never queried whole; the
            // hash is what a "new device" check actually compares.
            $table->char('user_agent_hash', 32)->nullable();
            $table->string('user_agent', 512)->nullable();

            $table->json('context')->nullable();

            $table->timestamp('occurred_at', 3);
            $table->date('occurred_on');

            // "This person's recent sign-in history" — shown on their profile.
            $table->index(['user_id', 'occurred_at'], 'authev_user_time');

            // The two questions asked while something is happening: is one
            // address being hammered, and is one source hammering everybody.
            $table->index(['email', 'occurred_at'], 'authev_email_time');
            $table->index(['ip_address', 'occurred_at'], 'authev_ip_time');

            // The retention sweep, and the partition key.
            $table->index('occurred_on', 'authev_day');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_events');
    }
};
