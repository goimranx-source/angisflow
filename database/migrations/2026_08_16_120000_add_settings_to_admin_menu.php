<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Settings belongs in the menu, not behind the avatar.
 *
 * ── Why it was in the wrong place ────────────────────────────────────────────
 *
 * It sat in the account menu, top right — the drawer holding "sign out" and the
 * profile. That is where a *person's* things live: their password, their
 * two-factor, their name. Settings is not a fact about the person, it is a fact
 * about the workspace they have open, and now demonstrably so: the currency and
 * the integrations resolve per workspace, so opening a different workspace
 * changes what that screen says.
 *
 * Anything that changes when the workspace changes belongs in the workspace's
 * own navigation. Left where it was, somebody would open it from the avatar
 * menu — which does not change when they switch — and reasonably assume they
 * were editing account-wide values.
 *
 * ── is_core, so no category has to opt in ────────────────────────────────────
 *
 * Marked core rather than given a row in category_module_presets for each of
 * the nine categories. Core modules skip the category filter entirely (see
 * Navigation::build), which is the correct treatment for something every
 * business has regardless of trade — a salon needs its currency set exactly as
 * much as a foundry does.
 */
return new class extends Migration
{
    public function up(): void
    {
        $pillarId = DB::table('module_pillars')->where('key', 'admin')->value('id');

        if ($pillarId === null) {
            return;
        }

        DB::table('modules')->updateOrInsert(
            ['key' => 'platform.settings'],
            [
                'public_id' => (string) Str::ulid(),
                'pillar_id' => $pillarId,
                'label' => 'Settings',
                'icon' => 'gear-six',
                'summary' => 'Currency, integrations, and how this workspace behaves.',
                'capability' => 'settings.view',
                'is_core' => 1,
                'is_built' => 1,
                'path' => '/settings',
                // So the row stays lit while any tab of it is open, rather than
                // only on the bare /settings address.
                'matches' => '/settings',
                // Last in the section: it is the thing you visit occasionally,
                // not the thing you work in.
                'sort_order' => 9,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('modules')->where('key', 'platform.settings')->delete();
    }
};
