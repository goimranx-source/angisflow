<?php

namespace App\Console\Commands;

use App\Domain\Catalogue\Models\Module;
use App\Domain\Catalogue\Models\BusinessCategory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class UpdateCategoryModulePresets extends Command
{
    protected $signature = 'category:update-modules';
    protected $description = 'Update category module presets based on business requirements research';

    public function handle()
    {
        $this->line('╔════════════════════════════════════════════════════════════════╗');
        $this->line('║  UPDATING CATEGORY-MODULE MAPPINGS                            ║');
        $this->line('╚════════════════════════════════════════════════════════════════╝');
        $this->newLine();

        $mappings = $this->getMappings();
        $totalCategories = count($mappings);
        $categoryIndex = 0;

        foreach ($mappings as $categoryKey => $modules) {
            $categoryIndex++;
            $this->updateCategory($categoryKey, $modules, $categoryIndex, $totalCategories);
        }

        $this->info('✅ ALL CATEGORY MAPPINGS UPDATED SUCCESSFULLY');
    }

    private function updateCategory($categoryKey, $modules, $index, $total)
    {
        $category = BusinessCategory::where('key', $categoryKey)->first();

        if (!$category) {
            $this->error("❌ Category not found: $categoryKey");
            return;
        }

        $this->line("\n[$index/$total] Updating {$category->name} (key: {$categoryKey})");

        $updated = 0;
        $created = 0;

        foreach ($modules as $moduleKey => $enabled) {
            $module = Module::where('key', $moduleKey)->first();
            if (!$module) {
                continue;
            }

            $result = DB::table('category_module_presets')->updateOrInsert(
                [
                    'business_category_id' => $category->id,
                    'module_id' => $module->id,
                ],
                [
                    'enabled_by_default' => $enabled,
                    'updated_at' => now(),
                ]
            );

            if ($result) {
                $created++;
            } else {
                $updated++;
            }
        }

        $enabledCount = DB::table('category_module_presets')
            ->where('business_category_id', $category->id)
            ->where('enabled_by_default', 1)
            ->count();

        $this->line("   ✅ Updated: $updated | Created: $created | Enabled modules: $enabledCount");
    }

    private function getMappings()
    {
        return [
            'retail' => $this->getRetailAndEcommerceModules(),
            'hospitality' => $this->getHospitalityModules(),
            'professional' => $this->getProfessionalModules(),
            'field' => $this->getFieldServiceModules(),
            'wellness' => $this->getWellnessModules(),
            'education' => $this->getEducationModules(),
            'industrial' => $this->getManufacturingModules(),
            'rental' => $this->getRentalModules(),
            'other' => $this->getUniversalModules(),
        ];
    }

    private function getRetailAndEcommerceModules()
    {
        // Merge retail + e-commerce capabilities since they share the same category
        $retail = [
            'revenue.orders' => 1, 'revenue.pos' => 1, 'revenue.returns' => 1, 'revenue.customers' => 1,
            'revenue.leads' => 1, 'revenue.courier' => 1, 'catalogue.products' => 1, 'catalogue.stock' => 1,
            'catalogue.warehouses' => 1, 'catalogue.purchasing' => 1, 'catalogue.pricing' => 1,
            'finance.invoicing' => 1, 'finance.payments' => 1, 'finance.accounts' => 1, 'finance.transactions' => 1,
            'finance.journal' => 1, 'finance.ledgers' => 1, 'finance.reports' => 1, 'growth.campaigns' => 1,
            'growth.reviews' => 1, 'growth.loyalty' => 1, 'growth.offers' => 1, 'growth.forms' => 1,
            'intelligence.dashboards' => 1, 'intelligence.reports' => 1, 'cx.channels' => 1, 'cx.live_chat' => 1,
            'cx.templates' => 1, 'documents.store' => 1, 'documents.audit' => 1, 'platform.integrations' => 1,
            'platform.settings' => 1, 'platform.users' => 1, 'people.employees' => 1, 'people.attendance' => 1,
            'people.shifts' => 1, 'work.dashboard' => 1,
        ];
        $ecom = [
            'web.store' => 1, 'web.storefronts' => 1, 'web.payment_links' => 1,
        ];
        return $this->fillDefaults(array_merge($retail, $ecom));
    }

    private function getUniversalModules()
    {
        // For "Other" category, enable only universal modules
        $universal = [
            'finance.accounts' => 1, 'finance.transactions' => 1, 'finance.journal' => 1, 'finance.reports' => 1,
            'platform.settings' => 1, 'platform.users' => 1, 'documents.store' => 1, 'documents.audit' => 1,
            'work.dashboard' => 1, 'people.employees' => 1, 'intelligence.dashboards' => 1,
            'platform.integrations' => 1,
        ];
        return $this->fillDefaults($universal);
    }


    private function getHospitalityModules()
    {
        $core = [
            'revenue.orders' => 1, 'revenue.pos' => 1, 'revenue.customers' => 1, 'revenue.courier' => 1,
            'catalogue.products' => 1, 'catalogue.stock' => 1, 'catalogue.pricing' => 1, 'delivery.bookings' => 1,
            'delivery.scheduling' => 1, 'finance.invoicing' => 1, 'finance.payments' => 1, 'finance.accounts' => 1,
            'finance.transactions' => 1, 'finance.journal' => 1, 'finance.reports' => 1, 'growth.campaigns' => 1,
            'growth.reviews' => 1, 'growth.loyalty' => 1, 'cx.channels' => 1, 'cx.live_chat' => 1, 'cx.templates' => 1,
            'people.employees' => 1, 'people.attendance' => 1, 'people.shifts' => 1, 'intelligence.dashboards' => 1,
            'documents.store' => 1, 'platform.integrations' => 1, 'platform.settings' => 1, 'platform.users' => 1,
            'work.dashboard' => 1, 'documents.audit' => 1,
        ];
        return $this->fillDefaults($core);
    }

    private function getProfessionalModules()
    {
        $core = [
            'revenue.quotes' => 1, 'revenue.pipeline' => 1, 'delivery.projects' => 1, 'delivery.timesheets' => 1,
            'revenue.customers' => 1, 'revenue.leads' => 1, 'revenue.invoicing' => 1, 'revenue.payments' => 1,
            'finance.accounts' => 1, 'finance.transactions' => 1, 'finance.journal' => 1, 'finance.reports' => 1,
            'growth.campaigns' => 1, 'growth.forms' => 1, 'documents.store' => 1, 'cx.channels' => 1,
            'people.employees' => 1, 'intelligence.dashboards' => 1, 'platform.integrations' => 1,
            'platform.settings' => 1, 'platform.users' => 1, 'work.dashboard' => 1, 'documents.audit' => 1,
        ];
        return $this->fillDefaults($core);
    }

    private function getFieldServiceModules()
    {
        $core = [
            'delivery.field' => 1, 'delivery.jobs' => 1, 'delivery.scheduling' => 1, 'delivery.slas' => 1,
            'operations.fleet' => 1, 'revenue.quotes' => 1, 'revenue.orders' => 1, 'revenue.customers' => 1,
            'revenue.leads' => 1, 'revenue.invoicing' => 1, 'revenue.payments' => 1, 'revenue.pipeline' => 1,
            'finance.accounts' => 1, 'finance.transactions' => 1, 'finance.journal' => 1, 'finance.reports' => 1,
            'catalogue.stock' => 1, 'catalogue.warehouses' => 1, 'people.employees' => 1, 'people.attendance' => 1,
            'people.shifts' => 1, 'documents.store' => 1, 'cx.channels' => 1, 'intelligence.dashboards' => 1,
            'platform.integrations' => 1, 'platform.settings' => 1, 'platform.users' => 1, 'work.dashboard' => 1,
            'documents.audit' => 1,
        ];
        return $this->fillDefaults($core);
    }

    private function getWellnessModules()
    {
        $core = [
            'delivery.bookings' => 1, 'delivery.scheduling' => 1, 'revenue.subscriptions' => 1, 'revenue.pos' => 1,
            'revenue.customers' => 1, 'revenue.payments' => 1, 'finance.invoicing' => 1, 'finance.accounts' => 1,
            'finance.transactions' => 1, 'finance.journal' => 1, 'finance.reports' => 1, 'growth.campaigns' => 1,
            'growth.loyalty' => 1, 'growth.reviews' => 1, 'people.employees' => 1, 'people.shifts' => 1,
            'people.performance' => 1, 'cx.live_chat' => 1, 'cx.templates' => 1, 'intelligence.dashboards' => 1,
            'documents.store' => 1, 'platform.integrations' => 1, 'platform.settings' => 1, 'platform.users' => 1,
            'work.dashboard' => 1, 'documents.audit' => 1,
        ];
        return $this->fillDefaults($core);
    }

    private function getEducationModules()
    {
        $core = [
            'delivery.bookings' => 1, 'delivery.scheduling' => 1, 'revenue.subscriptions' => 1, 'revenue.invoicing' => 1,
            'revenue.leads' => 1, 'revenue.customers' => 1, 'finance.accounts' => 1, 'finance.transactions' => 1,
            'finance.journal' => 1, 'finance.reports' => 1, 'growth.campaigns' => 1, 'growth.forms' => 1,
            'growth.reviews' => 1, 'people.employees' => 1, 'people.shifts' => 1, 'people.performance' => 1,
            'cx.channels' => 1, 'cx.live_chat' => 1, 'documents.store' => 1, 'intelligence.dashboards' => 1,
            'platform.integrations' => 1, 'platform.settings' => 1, 'platform.users' => 1, 'work.dashboard' => 1,
            'documents.audit' => 1,
        ];
        return $this->fillDefaults($core);
    }

    private function getManufacturingModules()
    {
        $core = [
            'operations.production' => 1, 'operations.bom' => 1, 'operations.quality' => 1, 'catalogue.products' => 1,
            'catalogue.stock' => 1, 'catalogue.warehouses' => 1, 'catalogue.batches' => 1, 'catalogue.purchasing' => 1,
            'revenue.orders' => 1, 'revenue.customers' => 1, 'revenue.quotes' => 1, 'revenue.invoicing' => 1,
            'revenue.payments' => 1, 'finance.accounts' => 1, 'finance.transactions' => 1, 'finance.journal' => 1,
            'finance.reports' => 1, 'people.employees' => 1, 'people.shifts' => 1, 'people.attendance' => 1,
            'delivery.scheduling' => 1, 'intelligence.dashboards' => 1, 'documents.store' => 1,
            'platform.integrations' => 1, 'platform.settings' => 1, 'platform.users' => 1, 'work.dashboard' => 1,
            'documents.audit' => 1,
        ];
        return $this->fillDefaults($core);
    }

    private function getRentalModules()
    {
        $core = [
            'delivery.bookings' => 1, 'delivery.scheduling' => 1, 'operations.maintenance' => 1, 'delivery.slas' => 1,
            'revenue.orders' => 1, 'revenue.quotes' => 1, 'revenue.contracts' => 1, 'revenue.customers' => 1,
            'revenue.leads' => 1, 'revenue.invoicing' => 1, 'revenue.payments' => 1, 'finance.accounts' => 1,
            'finance.transactions' => 1, 'finance.journal' => 1, 'finance.reports' => 1, 'catalogue.products' => 1,
            'catalogue.stock' => 1, 'catalogue.warehouses' => 1, 'revenue.courier' => 1, 'cx.channels' => 1,
            'people.employees' => 1, 'people.shifts' => 1, 'intelligence.dashboards' => 1, 'documents.store' => 1,
            'platform.integrations' => 1, 'platform.settings' => 1, 'platform.users' => 1, 'work.dashboard' => 1,
            'documents.audit' => 1,
        ];
        return $this->fillDefaults($core);
    }

    private function fillDefaults(array $modules)
    {
        // Get all modules and set to 0 if not in the enabled list
        $allModules = Module::pluck('key')->all();
        $result = [];

        foreach ($allModules as $key) {
            $result[$key] = $modules[$key] ?? 0;
        }

        return $result;
    }
}
