<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The currency a workspace reports in.
 *
 * ── Why this is not the business's own currency ──────────────────────────────
 *
 * A business's base_currency is what it actually trades in — the till in the
 * Berlin shop rings up euros and the one in Dhaka rings up taka, and each set of
 * books has to record what genuinely happened at the counter. That is the right
 * answer for the ledger and the wrong one for the person looking at all of them
 * at once: a workspace holding both would show €4,200 beside ৳310,000 and leave
 * the reader to do the arithmetic, or worse, to add them.
 *
 * So the workspace carries the currency it reports in, and every figure shown
 * above the level of a single set of books is converted into it. The business
 * keeps trading in what it trades in; the roll-up is comparable.
 *
 * Nullable on purpose. Null means "whatever the account reports in", which is
 * the correct behaviour for the overwhelming majority — one country, one
 * currency, nothing to think about — and means this column only has to be
 * consulted by the workspaces that actually differ.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->char('base_currency', 3)->nullable()->after('icon');
        });

        // Backfilled from the account so existing workspaces keep reporting in
        // exactly what they reported in yesterday. Leaving them null would be
        // equivalent, but writing it makes the value visible in the settings
        // screen rather than showing an empty field somebody has to guess at.
        DB::statement('
            UPDATE workspaces
            SET base_currency = (
                SELECT accounts.base_currency
                FROM accounts
                WHERE accounts.id = workspaces.account_id
            )
        ');
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn('base_currency');
        });
    }
};
