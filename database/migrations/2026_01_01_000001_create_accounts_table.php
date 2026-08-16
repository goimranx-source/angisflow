<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Subscribers.
 *
 * The root of the tenancy tree: every other table in the application carries
 * account_id and leads its indexes with it.
 *
 * ── Conventions used by every table from here on ─────────────────────────────
 *
 *   id           BIGINT auto-increment. Sequential so InnoDB appends rather
 *                than splitting pages; never exposed.
 *   public_id    CHAR(26) ULID, unique. What URLs and the API carry. Fixed
 *                width and CHAR rather than VARCHAR because it is always
 *                exactly 26 bytes and a fixed-width unique index is smaller
 *                and faster to probe.
 *   *_minor      Money, as a whole number of the smallest unit. Never DECIMAL,
 *                never FLOAT. See App\Domain\Shared\ValueObjects\Money.
 *   settings     JSON for things that vary per subscriber and are read as a
 *                blob. A column per setting is an ALTER TABLE on a hot table
 *                every time somebody wants a new toggle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();

            $table->string('name');
            $table->string('slug', 64)->unique();

            // No foreign key, and that is deliberate: accounts and users point
            // at each other, so one of the two constraints has to be the one
            // that is not declared. This is the cheaper side to leave off —
            // it is written once at signup and read almost never, whereas
            // users.account_id is on the hot path of every request and keeps
            // its constraint. Indexed so the lookup is still cheap.
            $table->unsignedBigInteger('owner_user_id')->nullable()->index();

            $table->char('base_currency', 3)->default('BDT');
            $table->char('country', 2)->nullable();
            $table->string('timezone', 64)->default('UTC');
            $table->string('locale', 12)->default('en');

            // Read on every single request to decide whether to let anyone in,
            // so it is a short string on the row rather than a join to the
            // subscription table.
            $table->string('status', 20)->default('trialing');
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->string('suspended_reason')->nullable();

            $table->json('settings')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // For the admin panel that comes next: "every account that is past
            // due", "every trial ending this week". Leading with status keeps
            // those scans off the table itself.
            $table->index(['status', 'created_at']);
            $table->index(['status', 'trial_ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
