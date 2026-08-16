<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Logins, and the two tables that support signing in.
 *
 * ── The email index, and why it is composite ─────────────────────────────────
 *
 * The same human working for two subscribers gets two rows. They have two jobs,
 * two roles, two permission sets and two audit trails, and folding them into
 * one identity would mean every query in the system had to carry "…and which
 * account" forever.
 *
 * So (account_id, email) is unique rather than email alone. Sign-in then needs
 * to find a person from an email with no account in hand, which is what the
 * separate non-unique index on email is for: it narrows to the handful of rows
 * with that address, and the password decides which. That index is the single
 * hottest read in the system, so it is the one thing here worth being careful
 * about.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();

            $table->foreignId('account_id')->constrained()->cascadeOnDelete();

            // Null for the owner, who holds every capability implicitly.
            $table->unsignedBigInteger('role_id')->nullable();

            // Which set of books they are currently looking at. Held on the row
            // rather than only in the session, so it survives signing out and a
            // member of staff opens theirs rather than whichever came back
            // first.
            $table->unsignedBigInteger('current_business_id')->nullable();

            $table->string('name');
            $table->string('email');
            $table->timestamp('email_verified_at')->nullable();

            // Nullable: an account can be passkey-only, and a staff member
            // invited but not yet set up has no password at all.
            $table->string('password')->nullable();
            $table->rememberToken();

            $table->string('avatar_path')->nullable();
            $table->string('timezone', 64)->nullable();
            $table->string('locale', 12)->nullable();

            $table->boolean('is_owner')->default(false);
            $table->boolean('is_active')->default(true);

            // Written on sign-in and nowhere else. A separate column rather
            // than touching updated_at, so "who has actually used this account"
            // survives an unrelated profile edit.
            $table->timestamp('last_seen_at')->nullable();

            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['account_id', 'email']);

            // The sign-in lookup. Not unique — see the note above.
            $table->index('email');

            // "Everyone in this account", "everyone on this role" — the team
            // screen and the role editor.
            $table->index(['account_id', 'is_active']);
            $table->index(['account_id', 'role_id']);

            $table->foreign('role_id')->references('id')->on('roles')->nullOnDelete();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
