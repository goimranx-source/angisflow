<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\Catalogue\ModuleAccess;
use App\Domain\Catalogue\Models\Module;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Workspace;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The sidebar, built once and shared by everyone it looks the same for.
 *
 * ── Why this is cached on a capability signature ─────────────────────────────
 *
 * The menu is a pure function of three things: the module catalogue (code, so
 * it changes on deploy), the viewer's capabilities, and whether they have a
 * staff record. It does not depend on *which* user is looking — two sales reps
 * on the same role get byte-identical menus.
 *
 * So it is cached against a hash of exactly those inputs. An account with two
 * hundred staff on four roles computes four menus, not two hundred, and
 * computes them once rather than on every page view. At a million users this is
 * the difference between the sidebar being free and being forty array
 * operations on every request in the system.
 *
 * ── Why no active state is computed here ─────────────────────────────────────
 *
 * Which item is current changes on every navigation, and baking it in would
 * make the cache key include the URL — which is to say, no cache at all. So the
 * server sends the menu and the path prefixes each item answers to, and React
 * decides what is highlighted from the URL it already has. Nothing round-trips
 * to highlight a link.
 */
final class Navigation
{
    private const CACHE_TTL = 3600;

    /**
     * @return list<array<string, mixed>>
     */
    public static function forUser(User $user, ?Workspace $workspace = null, mixed $business = null): array
    {
        $capabilities = $user->capabilityList();
        sort($capabilities);

        $access = app(ModuleAccess::class);

        /*
         * The workspace's own enabled set is now part of what the menu varies
         * on, so it has to be part of the key. Hashed rather than listed: two
         * workspaces that happen to have switched on the same modules share a
         * cached menu, which is the common case across an account whose
         * workspaces are all the same kind of business.
         *
         * With no workspace — signed in but nothing opened yet — only the core
         * modules are resolvable, and 'none' is a legitimate cache bucket for
         * that rather than a hole.
         *
         * When a business is selected, we also include its categories in the
         * cache key so the menu shows modules from all the business's categories.
         */
        $enabled = $workspace !== null ? $access->enabledKeys($workspace) : [];
        sort($enabled);

        // Include business categories in cache key
        $businessCategoriesHash = 'none';
        if ($business !== null) {
            $categoryIds = collect($business->categories ?? [])
                ->map(function($cat) {
                    // Load parent if needed for presetSource resolution
                    if (!$cat->relationLoaded('parent') && $cat->parent_id) {
                        $cat->load('parent');
                    }
                    $presetSource = $cat->presetSource();
                    return $presetSource->getAttributes()['id'];
                })
                ->unique()
                ->sort()
                ->values()
                ->all();
            $businessCategoriesHash = $categoryIds ? hash('xxh128', implode(',', $categoryIds)) : 'none';
        }

        // Only the inputs the menu actually varies on. The user id is
        // deliberately not among them.
        $signature = hash('xxh128', implode('|', [
            self::catalogueVersion(),
            implode(',', $capabilities),
            $user->is_owner ? 'owner' : 'member',
            $workspace !== null ? hash('xxh128', implode(',', $enabled)) : 'none',
            $businessCategoriesHash,
        ]));

        return Cache::remember(
            "nav:{$signature}",
            self::CACHE_TTL,
            fn () => self::build($user, $workspace, $access, $business),
        );
    }

    /**
     * Built from the catalogue in the database, not the PHP array.
     *
     * The array could describe one product for everybody. It cannot describe a
     * menu that differs per workspace according to what its owner switched on,
     * which is why the catalogue moved into tables — and why this reads
     * ModuleAccess rather than Modules::GROUPS. The old constant survives for
     * the roadmap, which still legitimately wants the whole product rather
     * than one subscriber's slice of it.
     *
     * When a business is selected, modules are filtered to those belonging to
     * the business's categories. If a business has multiple categories, modules
     * from all categories are shown.
     *
     * @return list<array<string, mixed>>
     */
    private static function build(User $user, ?Workspace $workspace, ModuleAccess $access, mixed $business = null): array
    {
        $hasCategories = $business !== null && $business->categories->isNotEmpty();
        $categoryIds = [];
        $businessCategories = [];

        // When a business has categories, get ALL modules (not limited by workspace enablement)
        // because category filtering is the primary filter now
        if ($hasCategories) {
            // Get database IDs from business categories, resolving to preset source
            // (subcategories use their parent's modules via presetSource())
            $categoryIds = collect($business->categories)
                ->map(function($cat) {
                    // Load parent if needed for presetSource resolution
                    if (!$cat->relationLoaded('parent') && $cat->parent_id) {
                        $cat->load('parent');
                    }
                    $presetSource = $cat->presetSource();
                    return $presetSource->getAttributes()['id'];
                })
                ->unique()
                ->all();

            // Cache category info for sidebar grouping later
            $businessCategories = collect($business->categories)
                ->map(fn ($cat) => ['id' => $cat->id, 'name' => $cat->name])
                ->keyBy('id')
                ->all();

            // Get ALL modules, filtered only by user capabilities (not workspace enablement).
            //
            // `defaultCategories`, not `categories`: the presets table records a
            // decision for every module against every category, so the unscoped
            // relation returns all nine for all eighty-six and the filter below
            // matches everything. See Module::defaultCategories().
            $modules = Cache::remember(
                'catalogue:all_modules',
                3600,
                fn () => Module::query()
                    ->with(['pillar', 'defaultCategories'])
                    ->join('module_pillars', 'module_pillars.id', '=', 'modules.pillar_id')
                    ->orderBy('module_pillars.sort_order')
                    ->orderBy('modules.sort_order')
                    ->select('modules.*')
                    ->get()
            )->filter(function ($module) use ($user) {
                // Only filter by capability, not by workspace enablement
                return $module->capability === null || $user->hasCapability($module->capability);
            });

            // Filter modules by business categories
            $modules = $modules->filter(function ($module) use ($categoryIds) {
                // Core modules are always shown
                if ($module->is_core) {
                    return true;
                }

                // Check whether this module is on by default for any of the
                // business's categories.
                $moduleCategories = $module->defaultCategories
                    ->map(fn($cat) => $cat->getAttributes()['id'])
                    ->all();
                return !empty(array_intersect($categoryIds, $moduleCategories));
            });
        } else {
            // No business or no categories: use normal workspace-based filtering
            $modules = $workspace !== null
                ? $access->available($workspace, $user)
                : $access->availableCore($user);
        }

        $sections = [];

        foreach ($modules->groupBy(fn ($module) => $module->pillar->key) as $pillarKey => $group) {
            $pillar = $group->first()->pillar;

            $items = $group->map(function ($module) use ($businessCategories, $categoryIds) {
                $item = [
                    'key' => $module->key,
                    'label' => $module->label,
                    'icon' => $module->icon,
                    'summary' => $module->summary ?? '',
                    'built' => $module->is_built,
                    'href' => $module->href(),
                    // Sent rather than resolved here — see the note at the top
                    // about why no active state is computed server-side.
                    'match' => $module->matchPrefixes(),
                ];

                // For multi-category businesses, record which categories enable this
                // module so the frontend can organize the sidebar.
                if (!empty($businessCategories)) {
                    $enabledByCategories = $module->is_core
                        ? array_values($businessCategories) // core is from all
                        : $module->defaultCategories
                            ->filter(fn ($cat) => in_array($cat->id, $categoryIds, true))
                            ->map(fn ($cat) => ['id' => $cat->id, 'name' => $cat->name])
                            ->values()
                            ->all();

                    // The frontend groups by this: "Hospitality", "Professional",
                    // "Both" (when a module is on for more than one category).
                    $item['enabled_by'] = array_map(fn ($cat) => [
                        'id' => $cat['id'] ?? $cat->id,
                        'name' => $cat['name'] ?? $cat->name,
                    ], $enabledByCategories);
                }

                return $item;
            })->values()->all();

            $sections[] = [
                'key' => $pillarKey,
                // The one pillar with no heading: its items are the top level.
                'label' => $pillarKey === 'work' ? null : $pillar->label,
                'icon' => $pillar->icon ?: 'dot',
                'flat' => $pillarKey === 'work',
                'items' => $items,
            ];
        }

        return $sections;
    }

    /**
     * Which modules this user can actually reach, as a flat set of keys.
     *
     * ── Why anything asking "can this business do X" comes through here ──────
     *
     * The menu already answers that question, and it answers it for the hard
     * case: a business carrying several categories gets the union of what each
     * one turns on, with subcategories resolved to the parent that holds the
     * presets. Anything that re-derives capability from the category key instead
     * — a match() on 'retail' somewhere in a dashboard — is a second opinion,
     * and the two drift the first time a business is given a second category.
     *
     * So: the sidebar and every screen that varies by trade read the same list.
     * If the menu will not offer a module, no screen advertises it either.
     *
     * `built` is deliberately not filtered here. Callers want different things
     * — a panel needs a module that is genuinely open, while a "coming soon"
     * strip wants the ones that are not — so both are returned and the caller
     * says which it means.
     *
     * @return array{all: list<string>, built: list<string>}
     */
    public static function capabilitiesFor(User $user, ?Workspace $workspace = null, mixed $business = null): array
    {
        $items = collect(self::forUser($user, $workspace, $business))
            ->pluck('items')
            ->flatten(1);

        return [
            'all' => $items->pluck('key')->unique()->values()->all(),
            'built' => $items->filter(fn ($item) => (bool) ($item['built'] ?? false))
                ->pluck('key')
                ->unique()
                ->values()
                ->all(),
            // Keyed by the business's own category id — see
            // capabilitiesByCategoryFor() for why this cannot be derived
            // from build()'s 'enabled_by' the way the union above is.
            'by_category' => self::capabilitiesByCategoryFor($user, $business),
        ];
    }

    /**
     * The built modules a business can reach, split out by which of *its
     * own* categories turns each one on.
     *
     * ── Why this cannot reuse build()'s 'enabled_by' ─────────────────────────
     *
     * build() resolves a subcategory to its parent before matching against
     * the preset table (BusinessCategory::presetSource() — a subcategory
     * with no preset rows of its own borrows its parent's), and reports the
     * *parent's* id in 'enabled_by' because that is what the sidebar's own
     * grouping headers want: "Retail", not the one subcategory a business
     * happens to carry. A dashboard chip is not a sidebar heading, though —
     * it is labelled with the business's own category ("Online store"), and
     * whatever it filters by has to key off the same id the chip is
     * wearing. Keying off the parent instead would silently drop every
     * non-core module a subcategory business has — orders, invoicing,
     * stock — the moment somebody clicked the one chip they were shown,
     * which is worse than the tab strip not existing at all.
     *
     * @return array<int, list<string>>
     */
    public static function capabilitiesByCategoryFor(User $user, mixed $business): array
    {
        if ($business === null || $business->categories->isEmpty()) {
            return [];
        }

        // Built only, and capability-gated — the same two filters build()
        // applies, minus the category filter it does in-line, since that is
        // the one part this method does per-category instead.
        $modules = Cache::remember(
            'catalogue:all_modules',
            3600,
            fn () => Module::query()
                ->with(['pillar', 'defaultCategories'])
                ->join('module_pillars', 'module_pillars.id', '=', 'modules.pillar_id')
                ->orderBy('module_pillars.sort_order')
                ->orderBy('modules.sort_order')
                ->select('modules.*')
                ->get(),
        )->filter(fn ($module) => $module->is_built
            && ($module->capability === null || $user->hasCapability($module->capability)));

        $byCategory = [];

        foreach ($business->categories as $category) {
            if (! $category->relationLoaded('parent') && $category->parent_id) {
                $category->load('parent');
            }

            $sourceId = $category->presetSource()->getAttributes()['id'];
            $businessCategoryId = $category->getAttributes()['id'];

            $byCategory[$businessCategoryId] = $modules
                ->filter(function ($module) use ($sourceId) {
                    // Core modules are on for every category — the same rule
                    // build() applies before it ever looks at defaultCategories.
                    if ($module->is_core) {
                        return true;
                    }

                    $moduleCategoryIds = $module->defaultCategories
                        ->map(fn ($c) => $c->getAttributes()['id'])
                        ->all();

                    return in_array($sourceId, $moduleCategoryIds, true);
                })
                ->pluck('key')
                ->unique()
                ->values()
                ->all();
        }

        return $byCategory;
    }

    /**
     * The businesses the header switcher offers.
     *
     * Cached per account and dropped whenever a business is written — see the
     * Business model's booted() hook. One query per account per hour rather
     * than one per request.
     *
     * @return list<array<string, mixed>>
     */
    public static function businessesFor(TenantContext $tenant): array
    {
        $account = $tenant->account();

        if ($account === null) {
            return [];
        }

        return Cache::remember(
            "nav:businesses:{$account->id}",
            self::CACHE_TTL,
            fn () => $account->businesses()
                ->where('is_active', true)
                ->orderBy('name')
                // Named columns, never *. A SELECT * here would drag settings
                // JSON and every address field into a payload that needs five
                // of them.
                ->with(['workspace:id,public_id', 'logoMedia:id,uuid,disk,path,mime_type', 'categories'])
                ->get(['id', 'public_id', 'workspace_id', 'name', 'short_code', 'base_currency', 'logo_media_id', 'business_category_id', 'country', 'timezone', 'address', 'phone', 'email'])
                ->map(fn ($business) => [
                    'id' => $business->public_id,
                    // Which workspace it sits in, so the sidebar's business
                    // select can narrow to the chosen workspace without asking
                    // the server again on every change of the one above it.
                    'workspace' => $business->workspace?->public_id,
                    'name' => $business->name,
                    'short_code' => $business->short_code,
                    'currency' => $business->base_currency,
                    'logo_url' => $business->logoMedia?->url(),
                    'business_category_id' => $business->business_category_id,
                    'categories' => $business->categories->map(fn($cat) => [
                        'id' => $cat->getAttributes()['id'], // Get actual database ID, not public_id
                        'public_id' => $cat->public_id,
                        'name' => $cat->name,
                        'key' => $cat->key,
                        'icon' => $cat->icon,
                    ])->toArray(),
                    'country' => $business->country,
                    'timezone' => $business->timezone,
                    'address' => $business->address,
                    'phone' => $business->phone,
                    'email' => $business->email,
                ])
                ->all(),
        );
    }

    /**
     * Every workspace this account owns.
     *
     * @return list<array<string, mixed>>
     */
    public static function workspacesFor(TenantContext $tenant): array
    {
        $account = $tenant->account();

        if ($account === null) {
            return [];
        }

        return Cache::remember(
            "nav:workspaces:{$account->id}",
            self::CACHE_TTL,
            fn () => $account->workspaces()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'public_id', 'name', 'slug', 'icon'])
                ->map(fn ($workspace) => [
                    'id' => $workspace->public_id,
                    'name' => $workspace->name,
                    'slug' => $workspace->slug,
                    'icon' => $workspace->icon,
                ])
                ->all(),
        );
    }

    public static function forgetBusinesses(int $accountId): void
    {
        Cache::forget("nav:businesses:{$accountId}");
        Cache::forget("nav:workspaces:{$accountId}");
    }

    /**
     * Changes whenever anything the menu is derived from changes, so a deploy
     * that ships a module does not serve yesterday's menu for an hour.
     *
     * BUILT is in here and has to be: shipping a screen is exactly one line in
     * that list, and it changes every href and every "Soon" badge in the
     * section it lives in. Hashing only the module keys — which is what this
     * did first — meant the release went out and the menu kept pointing at the
     * coming-soon page until the cache happened to expire.
     */
    /**
     * A fingerprint that changes when the catalogue does.
     *
     * This runs on every request, before the menu cache is consulted — so it
     * cannot be a query. It is a cached digest of the two things that move
     * when the catalogue is reseeded: how many modules there are, and when one
     * was last touched. Deploying a catalogue change drops the key (see
     * ModuleAccess::forgetCatalogue) and every menu rebuilds behind it.
     */
    private static function catalogueVersion(): string
    {
        return Cache::remember('catalogue:version', self::CACHE_TTL, static function (): string {
            $state = DB::table('modules')
                ->selectRaw('COUNT(*) as total, MAX(updated_at) as touched')
                ->first();

            return hash('xxh128', ($state->total ?? 0).'|'.($state->touched ?? ''));
        });
    }

    /*
     * hasStaffRecord() lived here, hiding "My workspace" from anyone without a
     * staff record — which, until People ships, is everybody. It is gone
     * rather than kept as a stub: that module is now simply unbuilt like any
     * other, so it points at its own coming-soon page and explains itself.
     * When People lands and staff records exist, the rule belongs in the
     * module's capability, not in a special case here.
     */
}
