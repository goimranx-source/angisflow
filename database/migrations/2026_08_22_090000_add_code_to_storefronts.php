<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A short tag each shop is known by.
 *
 * ── Why a stored field rather than initials ──────────────────────────────────
 *
 * Initials taken from the name were a reasonable default and a poor answer.
 * They are not the owner's choice: a shop everybody calls "VB" reads as "VBO"
 * because its record happens to say "Vorosa Bajar Online", and there is nothing
 * to be done about it. Two shops with similar names get a numbered variant
 * nobody recognises. And the tag silently changes the day somebody renames a
 * shop — after it has been read off a hundred order rows and said out loud on
 * the phone.
 *
 * A tag is an identifier people learn, so it has to be stable and it has to be
 * theirs. Derivation stays as the fallback for shops that have not set one, so
 * nothing is ever blank.
 *
 * Nullable rather than backfilled: an empty column means "not chosen", which is
 * exactly what is true of every existing shop, and lets the fallback answer for
 * them until somebody decides otherwise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('storefronts', function (Blueprint $table): void {
            $table->string('code', 8)->nullable()->after('slug');

            /*
             * Unique per business, not globally.
             *
             * The tag exists to tell one of *this* business's shops from
             * another; two unrelated subscribers both using "VB" is not a
             * collision anybody can observe. A global constraint would make one
             * business's choice deny it to everyone else.
             */
            $table->unique(['business_id', 'code'], 'storefronts_business_code_unique');
        });
    }

    public function down(): void
    {
        Schema::table('storefronts', function (Blueprint $table): void {
            $table->dropUnique('storefronts_business_code_unique');
            $table->dropColumn('code');
        });
    }
};
