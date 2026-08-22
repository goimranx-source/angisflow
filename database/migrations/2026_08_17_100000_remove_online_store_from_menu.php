<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Online Store stops being its own menu entry.
 *
 * ── Two entries for one idea ─────────────────────────────────────────────────
 *
 * The Storefront & Web section carried both "Storefronts" — the list of the
 * shops a business runs — and "Online Store", which is one shop's own page.
 * Side by side in a menu they read as two separate features, and there was no
 * rule a reader could apply to know which to open: the list is where you go to
 * find a shop, and the page is what you get once you have found one.
 *
 * A detail view belongs behind the thing it details, not beside it. So the
 * list stays in the menu and the store page is reached by opening a store,
 * which is the only way it was ever meaningful to arrive there.
 *
 * ── The route is not removed ─────────────────────────────────────────────────
 *
 * Only the menu entry goes. /online-store still answers, and the storefront
 * list now links to it, so nothing that already pointed there breaks.
 *
 * Left deliberately unfinished: that page reads /store/summary and
 * /store/settings, neither of which takes a storefront id, so today it shows
 * "the store" rather than "this store". Making it genuinely per-shop is part
 * of building the storefront endpoints, and pretending otherwise here — by
 * moving it to /storefronts/{id} while it still ignores the id — would be a
 * worse lie than the menu duplication this removes.
 */
return new class extends Migration
{
    public function up(): void
    {
        $moduleId = DB::table('modules')->where('key', 'web.store')->value('id');

        if ($moduleId === null) {
            return;
        }

        // Children first: both tables point at this row, and dropping it out
        // from under them would either fail on the constraint or orphan them,
        // depending on how the database feels about it.
        DB::table('category_module_presets')->where('module_id', $moduleId)->delete();
        DB::table('workspace_modules')->where('module_id', $moduleId)->delete();
        DB::table('modules')->where('id', $moduleId)->delete();
    }

    /**
     * Put the entry back, but not the presets.
     *
     * Which categories had it switched on, and which workspaces had it
     * enabled, is not recoverable from here — and inventing a set on the way
     * back would quietly change what businesses can see. The module returns;
     * anyone who wants it on a category turns it on again.
     */
    public function down(): void
    {
        $pillarId = DB::table('module_pillars')->where('key', 'web')->value('id');

        if ($pillarId === null) {
            return;
        }

        DB::table('modules')->updateOrInsert(
            ['key' => 'web.store'],
            [
                'public_id' => (string) \Illuminate\Support\Str::ulid(),
                'pillar_id' => $pillarId,
                'label' => 'Online Store',
                'icon' => 'storefront',
                'summary' => 'One shop’s own page.',
                'capability' => 'web.store.view',
                'is_core' => 0,
                'is_built' => 1,
                'path' => '/online-store',
                'matches' => '/online-store',
                'sort_order' => 2,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }
};
