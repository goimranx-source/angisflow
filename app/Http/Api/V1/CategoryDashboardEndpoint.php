<?php

declare(strict_types=1);

namespace App\Http\Api\V1;

use App\Domain\Catalogue\Models\Module;
use App\Domain\Tenancy\TenantContext;
use App\Http\Api\Endpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * The getting-started screen, in the shape of the subscriber's own trade.
 *
 * A salon and a wholesaler are handed the same eighty-six modules and asked to
 * find themselves in the list. This screen answers "what is this for me" — the
 * handful of things worth doing first, what has been set up already, and which
 * of the modules their category turns on are actually ready to open.
 */
class CategoryDashboardEndpoint extends Endpoint
{
    public function __construct(
        private readonly TenantContext $tenant,
    ) {}

    public function show(): JsonResponse
    {
        $business = $this->tenant->business();
        if (!$business) {
            return response()->json(['message' => 'No business selected'], 400);
        }

        $category = $business->categories->first();

        if (!$category) {
            return response()->json(['message' => 'Business has no category'], 400);
        }

        /*
         * Which category's presets apply.
         *
         * Subscribers pick a subcategory — "Studio & freelance", not
         * "Professional services" — and subcategories carry no presets of their
         * own. Reading presets straight off the chosen category therefore finds
         * nothing at all, and the screen falls back to a generic welcome for
         * every business on the system. presetSource() walks up to the parent
         * that actually holds the decisions, exactly as the sidebar does.
         */
        $category->loadMissing('parent');
        $source = $category->presetSource();
        $sourceId = $source->getAttributes()['id'];
        $sourceKey = $source->key;

        $enabledModuleIds = DB::table('category_module_presets')
            ->where('business_category_id', $sourceId)
            ->where('enabled_by_default', 1)
            ->pluck('module_id')
            ->all();

        $catalogue = Module::whereIn('id', $enabledModuleIds)
            ->orderBy('key')
            ->get(['id', 'key', 'label', 'icon', 'summary', 'path', 'is_built']);

        // Keyed by module key so the sections below can ask the catalogue for a
        // module's real address rather than carrying a hand-written copy of it.
        $modules = $catalogue
            ->mapWithKeys(fn ($m) => [$m->key => [
                'path' => $m->path,
                'built' => (bool) $m->is_built,
            ]])
            ->all();

        $enabledModules = $catalogue
            ->filter(fn ($m) => $m->is_built)
            ->map(fn ($m) => [
                'key' => $m->key,
                'label' => $m->label,
                'icon' => $m->icon,
                'summary' => $m->summary,
                'path' => $m->path,
                'is_built' => true,
            ])
            ->values()
            ->all();

        $comingSoonModules = $catalogue
            ->reject(fn ($m) => $m->is_built)
            ->map(fn ($m) => [
                'key' => $m->key,
                'label' => $m->label,
                'icon' => $m->icon,
                'summary' => $m->summary,
                'path' => '/soon/' . $m->key,
                'is_built' => false,
            ])
            ->values()
            ->all();

        return response()->json([
            'data' => [
                'category' => [
                    // The subscriber's own words for their trade stay on the
                    // page; only the preset lookups use the parent.
                    'key' => $category->key,
                    'name' => $category->name,
                    'description' => $this->getCategoryDescription($sourceKey),
                ],
                'modules' => [
                    'enabled' => $enabledModules,
                    'coming_soon' => $comingSoonModules,
                ],
                'quick_actions' => $this->getQuickActions($sourceKey, $modules),
                'setup_progress' => $this->getSetupProgress($business->id, $sourceKey, $modules),
            ],
        ]);
    }

    private function getCategoryDescription(string $categoryKey): string
    {
        $descriptions = [
            'retail' => 'Stock, tills and orders — the shop floor and the web store on one set of books.',
            'hospitality' => 'Covers, rooms and rotas, with the kitchen and the front desk reading the same numbers.',
            'professional' => 'Projects, the hours spent on them, and the invoices that follow.',
            'field' => 'Jobs, the people sent to them, and what each one earned.',
            'wellness' => 'Appointments, the people who keep them, and what they are worth over time.',
            'education' => 'Enrolments, timetables and the fees behind them.',
            'industrial' => 'What goes in, what comes out, and what it cost to make.',
            'rental' => 'What is out, when it is back, and what it earns while it is gone.',
            'other' => 'The core of the product: customers, money in, money out.',
        ];

        return $descriptions[$categoryKey] ?? 'The core of the product: customers, money in, money out.';
    }

    /**
     * The three or four things worth doing first in this trade.
     *
     * Each is named by module key rather than by a hand-written path. Writing
     * '/delivery/projects' here produces a link to nothing the moment the
     * projects module ships under a different address — or, as was the case,
     * before it ships at all. The catalogue already knows every module's real
     * address and whether it has one, so the catalogue decides: a module that is
     * not built is dropped rather than offered as a link to a 404.
     *
     * @param  array<string, array{path: ?string, built: bool}>  $modules
     * @return array<int, array<string, string>>
     */
    private function getQuickActions(string $categoryKey, array $modules): array
    {
        $specs = match ($categoryKey) {
            'retail' => [
                ['catalogue.products', 'Add a product', 'Build out your catalogue', 'package'],
                ['revenue.orders', 'View orders', 'Check what has sold', 'shopping-cart'],
                ['catalogue.stock', 'Manage stock', 'Keep levels accurate', 'warehouse'],
            ],
            'hospitality' => [
                ['delivery.bookings', 'New reservation', 'Take a booking', 'calendar'],
                ['catalogue.products', 'Manage menu', 'Update dishes and prices', 'clipboard-text'],
                ['people.shifts', 'Staff rota', 'Set who works when', 'calendar-plus'],
            ],
            'professional' => [
                ['delivery.projects', 'New project', 'Start tracking work', 'briefcase'],
                ['revenue.quotes', 'Create a quote', 'Send a proposal', 'file-text'],
                ['delivery.timesheets', 'Log time', 'Record billable hours', 'clock'],
            ],
            'field' => [
                ['delivery.field', 'Dispatch a job', 'Send work to a technician', 'map-pin'],
                ['delivery.jobs', 'View jobs', 'Check what is outstanding', 'list'],
                ['revenue.customers', 'Add a customer', 'Build your customer list', 'user-plus'],
            ],
            'wellness' => [
                ['delivery.bookings', 'Book an appointment', 'Fill the diary', 'calendar'],
                ['revenue.customers', 'Add a client', 'Build your client list', 'user-plus'],
            ],
            'education' => [
                ['revenue.customers', 'Enrol a student', 'Add someone to a class', 'user-plus'],
                ['delivery.bookings', 'Schedule a class', 'Set the timetable', 'calendar'],
            ],
            'industrial' => [
                ['catalogue.products', 'Add a product', 'Build out your catalogue', 'package'],
                ['catalogue.stock', 'Manage stock', 'Keep levels accurate', 'warehouse'],
            ],
            'rental' => [
                ['delivery.bookings', 'New rental', 'Book an asset out', 'calendar'],
                ['catalogue.products', 'Add an asset', 'List what you rent', 'package'],
            ],
            default => [
                ['revenue.customers', 'Add a customer', 'Build your customer list', 'user-plus'],
            ],
        };

        $actions = [];

        foreach ($specs as [$key, $label, $description, $icon]) {
            $module = $modules[$key] ?? null;

            if ($module === null || !$module['built'] || $module['path'] === null) {
                continue;
            }

            $actions[] = [
                'label' => $label,
                'description' => $description,
                'icon' => $icon,
                'href' => $module['path'],
                'action_type' => $actions === [] ? 'primary' : 'secondary',
            ];
        }

        // The dashboard is always reachable, so it is the honest fallback for a
        // trade whose own modules have not shipped yet. Better one live link
        // than three dead ones.
        if ($actions === []) {
            $actions[] = [
                'label' => 'View dashboard',
                'description' => 'See where the business stands',
                'icon' => 'chart-bar',
                'href' => '/dashboard',
                'action_type' => 'primary',
            ];
        }

        return $actions;
    }

    /**
     * Setting up, in the order that makes sense for this trade.
     *
     * Completion is read from the data rather than stored: a step is done when
     * the thing it asks for exists. That way it stays true when the subscriber
     * does the work from elsewhere in the product, and it cannot drift out of
     * step with a flag nobody remembered to set.
     *
     * @param  array<string, array{path: ?string, built: bool}>  $modules
     * @return array{completed: int, total: int, steps: array<int, array<string, mixed>>}
     */
    private function getSetupProgress(int $businessId, string $categoryKey, array $modules): array
    {
        $specs = match ($categoryKey) {
            'retail' => [
                ['products', 'catalogue.products', 'Add your first product', 'Build out the catalogue'],
                ['customers', 'revenue.customers', 'Add your first customer', 'Start your customer list'],
                ['orders', 'revenue.orders', 'Record your first sale', 'Put a sale through the books'],
            ],
            'hospitality' => [
                ['products', 'catalogue.products', 'Create your menu', 'Add dishes and prices'],
                ['employees', 'people.employees', 'Add your staff', 'Set up the team'],
                ['customers', 'revenue.customers', 'Add your first guest', 'Start your guest list'],
            ],
            'professional' => [
                ['employees', 'people.employees', 'Add your team', 'Who does the work'],
                ['customers', 'revenue.customers', 'Add your first client', 'Start your client list'],
                ['products', 'catalogue.products', 'Set your rates', 'What you charge for'],
            ],
            default => [
                ['customers', 'revenue.customers', 'Add your first customer', 'Start your customer list'],
                ['products', 'catalogue.products', 'Add what you sell', 'Products or services'],
            ],
        };

        $steps = [];
        $completed = 0;

        foreach ($specs as [$id, $moduleKey, $title, $description]) {
            $module = $modules[$moduleKey] ?? null;

            if ($module === null || !$module['built'] || $module['path'] === null) {
                continue;
            }

            $done = $this->hasRecords($id, $businessId);
            $completed += $done ? 1 : 0;

            $steps[] = [
                'id' => $id,
                'title' => $title,
                'description' => $description,
                'completed' => $done,
                'action_href' => $module['path'],
            ];
        }

        return [
            'completed' => $completed,
            'total' => count($steps),
            'steps' => $steps,
        ];
    }

    /** Whether the business has any of the thing a setup step asks for. */
    private function hasRecords(string $id, int $businessId): bool
    {
        $table = match ($id) {
            'products' => 'products',
            'customers' => 'customers',
            'orders' => 'orders',
            'employees' => 'employees',
            default => null,
        };

        if ($table === null || !DB::getSchemaBuilder()->hasTable($table)) {
            return false;
        }

        return DB::table($table)->where('business_id', $businessId)->exists();
    }
}
