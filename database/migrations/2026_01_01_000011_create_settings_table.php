<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every subscriber's own preferences.
 *
 * ── Why key/value and not a column each ──────────────────────────────────────
 *
 * Settings are read as a set — the whole appearance group is wanted at once, or
 * none of it — and they are written rarely. A column each means an ALTER TABLE
 * on a table every request touches, every time somebody wants a new toggle;
 * with a million subscribers that is a migration measured in hours for a
 * checkbox.
 *
 * The usual objection to key/value is that it has no schema, so anything can be
 * written into it. That is answered in code rather than in the database:
 * App\Domain\Settings\SettingsRegistry declares every key that exists, its type,
 * its default and how it is validated, and nothing else can be saved. The schema
 * is real — it just lives where it can be read.
 *
 * ── Why the read cost is zero ────────────────────────────────────────────────
 *
 * The brand name and logo are needed on every single page load. Fetching a
 * subscriber's rows each time would be a query per request purely to find out
 * what the tool is called. The whole set is cached per account and dropped when
 * anything in it is written, so the common case touches no table at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('account_id')->constrained()->cascadeOnDelete();

            // 'appearance.brand_name', 'currency.base'. The group before the
            // dot is what the settings screen shows as a tab, so a key names
            // where it belongs rather than needing a separate column to say.
            $table->string('key', 64);

            // Everything arrives and leaves as text; the registry says what it
            // really is and casts it. TEXT rather than a short string because a
            // tagline or a list of hidden modules will not fit in one.
            $table->text('value')->nullable();

            $table->timestamps();

            // One row per key per subscriber, and the lookup index in one.
            $table->unique(['account_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
