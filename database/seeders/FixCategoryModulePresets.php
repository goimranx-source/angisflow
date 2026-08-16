<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Fix category-module assignments to be more intelligent and selective.
 * 
 * Current problem:
 * - All categories get all ~86 modules
 * - Too many modules marked as "core"
 * - No meaningful differentiation between business types
 * 
 * Solution:
 * - Keep truly universal modules as core (12 modules)
 * - Make cx.channels, cx.templates, cx.automations, intelligence modules universal but NOT core
 * - Assign category-specific modules intelligently
 */
class FixCategoryModulePresets extends Seeder
{
    /**
     * Modules that should be in EVERY category (but not marked as core).
     * These are business-essential tools that everyone needs.
     */
    private const UNIVERSAL_MODULES = [
        // Customer Experience - everyone communicates
        'cx.channels',
        'cx.templates',
        'cx.automations',
        'cx.helpdesk',
        'cx.knowledge',
        'cx.feedback',
        
        // Intelligence - everyone needs insights
        'intelligence.ask',
        'intelligence.dashboards',
        'intelligence.reports',
        'intelligence.alerts',
        'intelligence.forecasting',
        
        // Growth - everyone wants to grow
        'growth.campaigns',
        'growth.reviews',
        'growth.forms',
        
        // Documents - everyone has documents
        'documents.store',
        'documents.esign',
        'documents.templates',
        
        // Platform integrations
        'platform.integrations',
        'platform.automations',
    ];

    /**
     * Module assignments by parent category.
     * Only non-universal, non-core modules need to be listed.
     */
    private const CATEGORY_MODULES = [
        // Retail & E-commerce (ID: 1)
        'retail' => [
            'revenue.leads',
            'revenue.pipeline',
            'revenue.quotes',
            'revenue.orders',
            'revenue.pos',
            'revenue.returns',
            'revenue.courier',
            'catalogue.products',
            'catalogue.pricing',
            'catalogue.stock',
            'catalogue.warehouses',
            'catalogue.purchasing',
            'catalogue.batches',
            'growth.offers',
            'growth.loyalty',
            'growth.referrals',
            'web.storefronts',
            'web.store',
            'web.portal',
            'web.payment_links',
            'finance.invoicing',
            'finance.payments',
            'finance.ledgers',
            'people.employees',
            'people.attendance',
            'people.shifts',
            'people.commissions',
        ],
        
        // Food & Hospitality (ID: 9)
        'hospitality' => [
            'revenue.leads',
            'revenue.orders',
            'revenue.pos',
            'delivery.bookings',
            'delivery.scheduling',
            'catalogue.products',
            'catalogue.stock',
            'catalogue.purchasing',
            'growth.offers',
            'growth.loyalty',
            'growth.referrals',
            'web.storefronts',
            'web.store',
            'web.booking_pages',
            'finance.invoicing',
            'finance.payments',
            'people.employees',
            'people.attendance',
            'people.shifts',
        ],
        
        // Professional Services (ID: 16)
        'professional' => [
            'revenue.leads',
            'revenue.pipeline',
            'revenue.quotes',
            'revenue.contracts',
            'revenue.subscriptions',
            'delivery.projects',
            'delivery.timesheets',
            'catalogue.services',
            'finance.invoicing',
            'finance.payments',
            'finance.ledgers',
            'finance.expenses',
            'finance.budgets',
            'people.employees',
            'people.attendance',
            'people.performance',
            'people.training',
            'web.portal',
            'web.payment_links',
        ],
        
        // Field & Home Services (ID: 23)
        'field' => [
            'revenue.leads',
            'revenue.pipeline',
            'revenue.quotes',
            'delivery.projects',
            'delivery.field',
            'delivery.jobs',
            'delivery.scheduling',
            'catalogue.services',
            'catalogue.stock',
            'catalogue.warehouses',
            'operations.maintenance',
            'operations.fleet',
            'finance.invoicing',
            'finance.payments',
            'finance.expenses',
            'people.employees',
            'people.attendance',
            'people.shifts',
            'web.booking_pages',
        ],
        
        // Health & Wellness (ID: 30)
        'wellness' => [
            'revenue.leads',
            'delivery.bookings',
            'delivery.scheduling',
            'catalogue.products',
            'catalogue.services',
            'catalogue.stock',
            'growth.loyalty',
            'finance.invoicing',
            'finance.payments',
            'people.employees',
            'people.attendance',
            'people.shifts',
            'web.booking_pages',
            'web.portal',
        ],
        
        // Education & Training (ID: 37)
        'education' => [
            'revenue.leads',
            'revenue.subscriptions',
            'delivery.projects',
            'catalogue.services',
            'growth.loyalty',
            'finance.invoicing',
            'finance.payments',
            'finance.ledgers',
            'people.employees',
            'people.attendance',
            'people.training',
            'web.portal',
            'web.payment_links',
        ],
        
        // Manufacturing & Wholesale (ID: 43)
        'industrial' => [
            'revenue.leads',
            'revenue.pipeline',
            'revenue.quotes',
            'revenue.orders',
            'catalogue.products',
            'catalogue.stock',
            'catalogue.warehouses',
            'catalogue.purchasing',
            'catalogue.batches',
            'operations.bom',
            'operations.production',
            'operations.quality',
            'operations.maintenance',
            'finance.invoicing',
            'finance.payments',
            'finance.ledgers',
            'finance.assets',
            'people.employees',
            'people.attendance',
            'people.shifts',
        ],
        
        // Rental & Assets (ID: 48)
        'rental' => [
            'revenue.leads',
            'revenue.pipeline',
            'revenue.quotes',
            'revenue.contracts',
            'delivery.bookings',
            'delivery.scheduling',
            'catalogue.products',
            'catalogue.stock',
            'operations.maintenance',
            'operations.fleet',
            'finance.invoicing',
            'finance.payments',
            'finance.assets',
            'people.employees',
            'people.attendance',
            'web.portal',
        ],
        
        // Something else (ID: 53) - gets everything
        'other' => [
            'revenue.leads',
            'revenue.pipeline',
            'revenue.quotes',
            'revenue.orders',
            'revenue.pos',
            'revenue.returns',
            'revenue.courier',
            'revenue.contracts',
            'revenue.subscriptions',
            'delivery.projects',
            'delivery.timesheets',
            'delivery.bookings',
            'delivery.field',
            'delivery.jobs',
            'delivery.scheduling',
            'delivery.slas',
            'catalogue.products',
            'catalogue.services',
            'catalogue.pricing',
            'catalogue.stock',
            'catalogue.warehouses',
            'catalogue.purchasing',
            'catalogue.batches',
            'operations.bom',
            'operations.production',
            'operations.quality',
            'operations.maintenance',
            'operations.fleet',
            'finance.invoicing',
            'finance.payments',
            'finance.ledgers',
            'finance.expenses',
            'finance.assets',
            'finance.budgets',
            'finance.tax',
            'people.employees',
            'people.attendance',
            'people.shifts',
            'people.payroll',
            'people.commissions',
            'people.recruitment',
            'people.performance',
            'people.training',
            'growth.offers',
            'growth.loyalty',
            'growth.referrals',
            'web.storefronts',
            'web.store',
            'web.booking_pages',
            'web.portal',
            'web.payment_links',
            'platform.partners',
            'platform.billing',
            'platform.localization',
        ],
    ];

    public function run(): void
    {
        echo "Fixing category-module presets...\n\n";
        
        DB::transaction(function () {
            // Clear existing presets
            echo "Clearing existing presets...\n";
            DB::table('category_module_presets')->delete();
            
            // Get category and module mappings
            $categories = DB::table('business_categories')->whereNull('parent_id')->pluck('id', 'key');
            $modules = DB::table('modules')->pluck('id', 'key');
            
            $universalModuleIds = collect(self::UNIVERSAL_MODULES)
                ->map(fn($key) => $modules[$key] ?? null)
                ->filter()
                ->values();
            
            $inserted = 0;
            
            // Insert universal modules for ALL categories
            echo "Adding universal modules to all categories...\n";
            foreach ($categories as $categoryKey => $categoryId) {
                foreach ($universalModuleIds as $moduleId) {
                    DB::table('category_module_presets')->insert([
                        'business_category_id' => $categoryId,
                        'module_id' => $moduleId,
                        'enabled_by_default' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $inserted++;
                }
            }
            
            // Insert category-specific modules
            echo "Adding category-specific modules...\n";
            foreach (self::CATEGORY_MODULES as $categoryKey => $moduleKeys) {
                $categoryId = $categories[$categoryKey] ?? null;
                if (!$categoryId) {
                    echo "  Warning: Category '$categoryKey' not found\n";
                    continue;
                }
                
                foreach ($moduleKeys as $moduleKey) {
                    $moduleId = $modules[$moduleKey] ?? null;
                    if (!$moduleId) {
                        echo "  Warning: Module '$moduleKey' not found\n";
                        continue;
                    }
                    
                    DB::table('category_module_presets')->insert([
                        'business_category_id' => $categoryId,
                        'module_id' => $moduleId,
                        'enabled_by_default' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $inserted++;
                }
                
                echo "  ✓ {$categoryKey}: " . count($moduleKeys) . " modules\n";
            }
            
            echo "\nTotal presets inserted: $inserted\n";
        });
        
        // Clear caches
        echo "\nClearing caches...\n";
        \App\Domain\Catalogue\ModuleAccess::forgetCatalogue();
        \Artisan::call('cache:clear');
        
        echo "\n✅ Done! Categories now have intelligent module assignments.\n";
    }
}
