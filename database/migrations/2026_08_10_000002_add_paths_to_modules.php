<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where each module lives, and what else counts as being there.
 *
 * ── Why these are stored rather than derived ─────────────────────────────────
 *
 * A key of `revenue.orders` looks like it should produce `/revenue/orders`,
 * and for a green-field catalogue it could. It does not here: the screens that
 * already exist answer to `/dashboard` and `/settings`, and the URLs a product
 * has shipped are not free to change to suit a naming scheme invented after
 * them. Deriving would mean either breaking live links or renaming keys to
 * match URLs — which puts the tail in charge of the dog, since keys are also
 * what presets, capabilities and `requires` are written against.
 *
 * `matches` exists because one screen owns several paths. Catalogue must light
 * up for /catalogue/stock as well as /catalogue, and no amount of looking at
 * one URL reveals the other.
 *
 * Null path is not missing data. It means the module is mapped out but not
 * built, and the sidebar sends it to its "coming soon" page — derived from the
 * key, which is safe precisely because nothing has shipped at that address.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('modules', function (Blueprint $table) {
            $table->string('path', 120)->nullable()->after('summary');
            $table->json('matches')->nullable()->after('path');
        });
    }

    public function down(): void
    {
        Schema::table('modules', function (Blueprint $table) {
            $table->dropColumn(['path', 'matches']);
        });
    }
};
