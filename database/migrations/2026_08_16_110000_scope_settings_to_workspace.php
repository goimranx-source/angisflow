<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Settings belong to a workspace, not to the whole account.
 *
 * ── Why the account was the wrong scope ──────────────────────────────────────
 *
 * A workspace is how somebody separates things that genuinely differ — a
 * Bangladesh operation and a German one, or a consultancy and the shop it runs
 * on the side. Holding settings on the account meant every one of those shared a
 * single currency, a single integration set, a single everything: changing the
 * reporting currency while looking at one workspace silently changed it for all
 * of them, which is the sort of thing discovered a quarter later in a report
 * nobody can reconcile.
 *
 * ── Nullable, and that is the point ──────────────────────────────────────────
 *
 * workspace_id null means "the account's answer" — a default every workspace
 * inherits until it says otherwise. So a subscriber with one workspace never
 * meets this concept at all, and one with four sets only the values that
 * actually differ instead of maintaining four copies of the same thing.
 *
 * Resolution is therefore two rows deep: the workspace's own value if it has
 * one, the account's if not. See Settings::all().
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('settings', 'workspace_id')) {
            Schema::table('settings', function (Blueprint $table) {
                $table->foreignId('workspace_id')
                    ->nullable()
                    ->after('account_id')
                    ->constrained('workspaces')
                    ->cascadeOnDelete();
            });
        }

        /*
         * The table carried no unique key at all, which is worth stating because
         * Settings::put() has always upserted on ['account_id', 'key'] — and an
         * upsert without a matching constraint does not update, it inserts. So a
         * setting written twice left two rows and the reader took whichever came
         * back first.
         *
         * The constraint added here is the one the code already believed in,
         * widened by workspace so a workspace's own value can sit beside the
         * account default rather than colliding with it.
         */
        $existing = collect(DB::select('PRAGMA index_list(settings)'))
            ->pluck('name')
            ->all();

        if (!in_array('settings_scope_key_unique', $existing, true)) {
            Schema::table('settings', function (Blueprint $table) {
                $table->unique(['account_id', 'workspace_id', 'key'], 'settings_scope_key_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropUnique('settings_scope_key_unique');
            $table->dropConstrainedForeignId('workspace_id');
        });
    }
};
