<?php

declare(strict_types=1);

namespace App\Domain\Catalogue;

use App\Domain\Catalogue\Contracts\ModuleEntitlement;
use App\Domain\Catalogue\Models\Module;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Workspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Which modules this person, in this workspace, may actually use.
 *
 * ── Three questions, asked in one place ──────────────────────────────────────
 *
 * A module appears only if all three agree, and each belongs to a different
 * part of the business:
 *
 *   ENTITLED     the plan allows it              — billing's answer
 *   ENABLED      the subscriber switched it on   — their preference
 *   AUTHORISED   this user's role permits it     — the role's answer
 *
 * Keeping them apart matters because they fail differently and the customer
 * needs a different sentence for each. "Your plan does not include Payroll" is
 * an upgrade; "Payroll is switched off for this workspace" is a setting an
 * admin can change; "You do not have permission to open Payroll" is a
 * conversation with their manager. Collapse them and every one of those becomes
 * the same unhelpful empty sidebar.
 *
 * ── Core modules skip the first two ──────────────────────────────────────────
 *
 * The ledger everything posts into, the customer record everything hangs off,
 * the inbox and live chat every subscriber is promised. These are never metered
 * and never switchable, so entitlement and enablement are not asked about them
 * at all. They still respect authorisation — being undeniable to the account is
 * not the same as being visible to every junior in it.
 */
final class ModuleAccess
{
    private const CACHE_TTL = 3600;

    public function __construct(
        private readonly ModuleEntitlement $entitlement,
    ) {}

    /**
     * Module keys available to this user here, in catalogue order.
     *
     * @return list<string>
     */
    public function availableKeys(Workspace $workspace, User $user): array
    {
        return $this->available($workspace, $user)->pluck('key')->all();
    }

    public function allows(Workspace $workspace, User $user, string $moduleKey): bool
    {
        return in_array($moduleKey, $this->availableKeys($workspace, $user), true);
    }

    /**
     * @return Collection<int, Module>
     */
    public function available(Workspace $workspace, User $user): Collection
    {
        $enabled = $this->enabledKeys($workspace);
        $permitted = $this->entitlement->permittedKeys($workspace);

        return $this->catalogue()
            ->filter(function (Module $module) use ($enabled, $permitted, $user): bool {
                // AUTHORISED — asked of everything, core included.
                if ($module->capability !== null && ! $user->hasCapability($module->capability)) {
                    return false;
                }

                if ($module->is_core) {
                    return true;
                }

                // ENTITLED — null means billing has no opinion yet.
                if ($permitted !== null && ! in_array($module->key, $permitted, true)) {
                    return false;
                }

                // ENABLED
                return in_array($module->key, $enabled, true);
            })
            ->values();
    }

    /**
     * What is available with no workspace open.
     *
     * Signed in, nothing selected — the header's own picker is how you get out
     * of that state, and it needs a rail to sit beside. Core modules are the
     * honest answer: they are exactly the ones that do not depend on a
     * workspace having been chosen or a plan having been checked.
     *
     * @return Collection<int, Module>
     */
    public function availableCore(User $user): Collection
    {
        return $this->catalogue()
            ->filter(fn (Module $module) => $module->is_core
                && ($module->capability === null || $user->hasCapability($module->capability)))
            ->values();
    }

    /**
     * Everything the subscriber has switched on here.
     *
     * Cached per workspace and dropped by ModuleProvisioner whenever a row
     * changes, so the sidebar is one cache read rather than a join on every
     * request.
     *
     * @return list<string>
     */
    public function enabledKeys(Workspace $workspace): array
    {
        return Cache::remember(
            self::enabledCacheKey($workspace->id),
            self::CACHE_TTL,
            fn () => $workspace->modules()
                ->wherePivot('is_enabled', true)
                ->pluck('key')
                ->all(),
        );
    }

    public static function forgetWorkspace(int $workspaceId): void
    {
        Cache::forget(self::enabledCacheKey($workspaceId));
    }

    private static function enabledCacheKey(int $workspaceId): string
    {
        return "catalogue:enabled:{$workspaceId}";
    }

    /**
     * The whole catalogue, in the order the sidebar draws it.
     *
     * Deliberately not filtered to built modules. A subscriber who has turned
     * Payroll on should see Payroll in the rail with a "soon" marker against
     * it, not an absence they have to interpret — the alternative is somebody
     * enabling a module in settings and concluding the switch did nothing.
     * Each item carries its own `built` flag and resolves its own href, so an
     * unbuilt one leads to a page that explains itself.
     *
     * Cached globally: it changes on deploy, not per request.
     *
     * @return Collection<int, Module>
     */
    private function catalogue(): Collection
    {
        return Cache::remember(
            'catalogue:built',
            self::CACHE_TTL,
            fn () => Module::query()
                ->with(['pillar', 'categories'])
                ->join('module_pillars', 'module_pillars.id', '=', 'modules.pillar_id')
                ->orderBy('module_pillars.sort_order')
                ->orderBy('modules.sort_order')
                ->select('modules.*')
                ->get(),
        );
    }

    /**
     * Drop the catalogue caches after a reseed.
     *
     * Both keys, together: the built list and the version fingerprint the
     * sidebar's own cache is keyed on. Forgetting one without the other leaves
     * every menu keyed on a version that no longer describes the catalogue
     * behind it — which is a stale sidebar nobody can explain.
     */
    public static function forgetCatalogue(): void
    {
        Cache::forget('catalogue:built');
        Cache::forget('catalogue:version');
    }
}
