<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The platform's own settings — ours, not a subscriber's.
 *
 * ── Why a separate table from `settings` ─────────────────────────────────────
 *
 * `settings` carries account_id and is scoped by it, which is exactly right for
 * a subscriber's own brand. These rows belong to nobody: the logo in the header
 * is *this tool's* mark, the same for every subscriber who signs in, and the
 * admin panel writes it once for everyone.
 *
 * Putting them in the same table would mean either a nullable account_id — and
 * a tenancy scope with a hole in it, which is the one thing that design cannot
 * have — or a magic account row that every scoped query has to remember to
 * exclude. A separate table has neither problem: there is no account_id to
 * forget, and no query can confuse the two.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->id();

            // 'brand.logo', 'brand.name'. Same group-before-the-dot convention
            // as the per-subscriber table, so the two read alike.
            $table->string('key', 64)->unique();
            $table->text('value')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
    }
};
