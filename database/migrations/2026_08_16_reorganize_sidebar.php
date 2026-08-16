<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reorganize the sidebar into clear, understandable sections.
 *
 * The old structure had 13 pillars using backend jargon (cx, revenue, delivery).
 * Users think in workflows and outcomes, not domain names.
 *
 * New structure: 7 sections, user-friendly names, sensible flow:
 *
 * 1. Overview — dashboard and workspace
 * 2. Sales — selling to customers (orders, POS, quotes)
 * 3. Finance — money tracking (invoicing, payments, ledgers)
 * 4. Inventory — what you have and sell (products, stock, warehouses)
 * 5. Fulfillment — delivering/serving (delivery, field service, bookings)
 * 6. Team — staff and payroll
 * 7. Customer Service — support and communications
 *
 * (Plus: Marketing, Operations, Storefront, Intelligence, Admin — category-specific)
 *
 * The reorganization is achieved by:
 * - Renaming pillars to be user-facing
 * - Re-parenting modules to the right pillar
 * - Resetting sort_order to reflect the new workflow
 */
return new class extends Migration
{
    public function up(): void
    {
        // Create new pillars or update existing ones
        $pillars = [
            // Core workflow
            ['key' => 'overview', 'label' => 'Overview', 'sort_order' => 0, 'icon' => 'gauge'],
            ['key' => 'sales', 'label' => 'Sales & Revenue', 'sort_order' => 1, 'icon' => 'shopping-cart'],
            ['key' => 'finance', 'label' => 'Finance & Money', 'sort_order' => 2, 'icon' => 'currency-dollar'],
            ['key' => 'inventory', 'label' => 'Inventory & Products', 'sort_order' => 3, 'icon' => 'package'],
            ['key' => 'fulfillment', 'label' => 'Fulfillment & Logistics', 'sort_order' => 4, 'icon' => 'truck'],
            ['key' => 'team', 'label' => 'Team & People', 'sort_order' => 5, 'icon' => 'users'],
            ['key' => 'service', 'label' => 'Customer Service', 'sort_order' => 6, 'icon' => 'headphones'],

            // Category-specific and advanced
            ['key' => 'marketing', 'label' => 'Marketing & Growth', 'sort_order' => 7, 'icon' => 'megaphone'],
            ['key' => 'operations', 'label' => 'Operations & Production', 'sort_order' => 8, 'icon' => 'wrench'],
            ['key' => 'web', 'label' => 'Storefront & Web', 'sort_order' => 9, 'icon' => 'storefront'],
            ['key' => 'intelligence', 'label' => 'Intelligence & Reports', 'sort_order' => 10, 'icon' => 'sparkle'],
            ['key' => 'admin', 'label' => 'Admin & Settings', 'sort_order' => 11, 'icon' => 'gear'],
        ];

        foreach ($pillars as $pillar) {
            DB::table('module_pillars')->updateOrInsert(
                ['key' => $pillar['key']],
                $pillar
            );
        }

        // Mapping: old pillar → new pillar and new sort order within section
        $mapping = [
            'work' => ['pillar' => 'overview', 'sort' => 0],
            'revenue' => [
                // Core sales flow
                'customers' => ['pillar' => 'sales', 'sort' => 1],
                'orders' => ['pillar' => 'sales', 'sort' => 2],
                'pos' => ['pillar' => 'sales', 'sort' => 3],
                'quotes' => ['pillar' => 'sales', 'sort' => 4],
                'returns' => ['pillar' => 'sales', 'sort' => 5],
                'leads' => ['pillar' => 'sales', 'sort' => 6],
                'pipeline' => ['pillar' => 'sales', 'sort' => 7],
                'contracts' => ['pillar' => 'sales', 'sort' => 8],
                'subscriptions' => ['pillar' => 'sales', 'sort' => 9],
                'courier' => ['pillar' => 'fulfillment', 'sort' => 1],
            ],
            'delivery' => [
                'courier' => ['pillar' => 'fulfillment', 'sort' => 1],
                'projects' => ['pillar' => 'fulfillment', 'sort' => 2],
                'bookings' => ['pillar' => 'fulfillment', 'sort' => 3],
                'field' => ['pillar' => 'fulfillment', 'sort' => 4],
                'jobs' => ['pillar' => 'fulfillment', 'sort' => 5],
                'scheduling' => ['pillar' => 'fulfillment', 'sort' => 6],
                'slas' => ['pillar' => 'fulfillment', 'sort' => 7],
                'timesheets' => ['pillar' => 'team', 'sort' => 4],
            ],
            'cx' => [
                'inbox' => ['pillar' => 'service', 'sort' => 1],
                'live_chat' => ['pillar' => 'service', 'sort' => 2],
                'channels' => ['pillar' => 'service', 'sort' => 3],
                'templates' => ['pillar' => 'service', 'sort' => 4],
                'automations' => ['pillar' => 'service', 'sort' => 5],
                'helpdesk' => ['pillar' => 'service', 'sort' => 6],
                'knowledge' => ['pillar' => 'service', 'sort' => 7],
                'feedback' => ['pillar' => 'service', 'sort' => 8],
            ],
            'catalogue' => [
                'products' => ['pillar' => 'inventory', 'sort' => 1],
                'services' => ['pillar' => 'inventory', 'sort' => 2],
                'stock' => ['pillar' => 'inventory', 'sort' => 3],
                'warehouses' => ['pillar' => 'inventory', 'sort' => 4],
                'pricing' => ['pillar' => 'inventory', 'sort' => 5],
                'purchasing' => ['pillar' => 'inventory', 'sort' => 6],
                'batches' => ['pillar' => 'inventory', 'sort' => 7],
            ],
            'operations' => [
                'bom' => ['pillar' => 'operations', 'sort' => 1],
                'production' => ['pillar' => 'operations', 'sort' => 2],
                'quality' => ['pillar' => 'operations', 'sort' => 3],
                'maintenance' => ['pillar' => 'operations', 'sort' => 4],
                'fleet' => ['pillar' => 'operations', 'sort' => 5],
            ],
            'finance' => [
                'invoicing' => ['pillar' => 'finance', 'sort' => 1],
                'payments' => ['pillar' => 'finance', 'sort' => 2],
                'transactions' => ['pillar' => 'finance', 'sort' => 3],
                'journal' => ['pillar' => 'finance', 'sort' => 4],
                'ledgers' => ['pillar' => 'finance', 'sort' => 5],
                'accounts' => ['pillar' => 'finance', 'sort' => 6],
                'fiscal' => ['pillar' => 'finance', 'sort' => 7],
                'expenses' => ['pillar' => 'finance', 'sort' => 8],
                'assets' => ['pillar' => 'finance', 'sort' => 9],
                'budgets' => ['pillar' => 'finance', 'sort' => 10],
                'tax' => ['pillar' => 'finance', 'sort' => 11],
                'reports' => ['pillar' => 'intelligence', 'sort' => 2],
            ],
            'people' => [
                'employees' => ['pillar' => 'team', 'sort' => 1],
                'attendance' => ['pillar' => 'team', 'sort' => 2],
                'shifts' => ['pillar' => 'team', 'sort' => 3],
                'payroll' => ['pillar' => 'team', 'sort' => 5],
                'commissions' => ['pillar' => 'team', 'sort' => 6],
                'recruitment' => ['pillar' => 'team', 'sort' => 7],
                'performance' => ['pillar' => 'team', 'sort' => 8],
                'training' => ['pillar' => 'team', 'sort' => 9],
            ],
            'growth' => [
                'campaigns' => ['pillar' => 'marketing', 'sort' => 1],
                'offers' => ['pillar' => 'marketing', 'sort' => 2],
                'loyalty' => ['pillar' => 'marketing', 'sort' => 3],
                'referrals' => ['pillar' => 'marketing', 'sort' => 4],
                'reviews' => ['pillar' => 'marketing', 'sort' => 5],
                'forms' => ['pillar' => 'marketing', 'sort' => 6],
            ],
            'web' => [
                'storefronts' => ['pillar' => 'web', 'sort' => 1],
                'store' => ['pillar' => 'web', 'sort' => 2],
                'booking_pages' => ['pillar' => 'web', 'sort' => 3],
                'portal' => ['pillar' => 'web', 'sort' => 4],
                'payment_links' => ['pillar' => 'web', 'sort' => 5],
            ],
            'intelligence' => [
                'ask' => ['pillar' => 'admin', 'sort' => 1],
                'dashboards' => ['pillar' => 'intelligence', 'sort' => 1],
                'reports' => ['pillar' => 'intelligence', 'sort' => 2],
                'alerts' => ['pillar' => 'intelligence', 'sort' => 3],
                'forecasting' => ['pillar' => 'intelligence', 'sort' => 4],
            ],
            'documents' => [
                'store' => ['pillar' => 'admin', 'sort' => 3],
            ],
            'platform' => [
                'partners' => ['pillar' => 'admin', 'sort' => 2],
                // others stay as-is, requiring manual review
            ],
        ];

        // Flatten the nested mapping and apply it
        $updates = [];
        foreach ($mapping as $oldPillar => $rules) {
            if (is_string($rules['pillar'] ?? null)) {
                // Direct pillar → pillar mapping (e.g., 'work')
                $oldId = DB::table('module_pillars')->where('key', $oldPillar)->value('id');
                $newId = DB::table('module_pillars')->where('key', $rules['pillar'])->value('id');
                if ($oldId && $newId) {
                    DB::table('modules')
                        ->where('pillar_id', $oldId)
                        ->update(['pillar_id' => $newId, 'sort_order' => $rules['sort'] ?? 0]);
                }
            } else {
                // Module-by-module mapping (e.g., revenue with sub-keys)
                $oldId = DB::table('module_pillars')->where('key', $oldPillar)->value('id');
                if (!$oldId) continue;

                foreach ($rules as $moduleKey => $directive) {
                    if (is_array($directive) && isset($directive['pillar'])) {
                        $newPillarId = DB::table('module_pillars')->where('key', $directive['pillar'])->value('id');
                        if ($newPillarId) {
                            DB::table('modules')
                                ->where('key', "$oldPillar.$moduleKey")
                                ->update([
                                    'pillar_id' => $newPillarId,
                                    'sort_order' => $directive['sort'] ?? 0,
                                ]);
                        }
                    }
                }
            }
        }

        // Delete old pillars (those not in the new structure)
        $newPillarKeys = array_column($pillars, 'key');
        DB::table('module_pillars')
            ->whereNotIn('key', $newPillarKeys)
            ->delete();
    }

    public function down(): void
    {
        // Restore original structure by resetting to known state
        // (A full rollback would require tracking which modules were where)
        // For now, document that this is a one-way migration
    }
};
