<?php

declare(strict_types=1);

namespace App\Domain\Identity\Support;

use App\Support\Capabilities;

/**
 * The roles a new subscriber starts with.
 *
 * Not facts, defaults: a business renames these in the first week and adds its
 * own. What matters is that nobody is asked to invent a permission structure
 * from an empty page — a question almost nobody answers well, and whose usual
 * answer is one role called "staff" and a shared password.
 *
 * Families first, then the roles inside them. A family carries the workspace —
 * which screens this kind of work uses — so a new role added under Sales lands
 * somewhere sensible without anything being configured.
 */
final class DefaultRoles
{
    /**
     * @return list<array{
     *     slug: string, name: string, parent: ?string, workspace: ?string,
     *     scope: string, capabilities: list<string>, description: string
     * }>
     */
    public static function all(): array
    {
        return [
            // ── Families ─────────────────────────────────────────────────────
            self::family('sales', 'Sales', 'sales', 'Everyone who takes and chases orders.'),
            self::family('accounts', 'Accounts', 'accounts', 'Everyone who touches the ledger.'),
            self::family('inventory', 'Inventory', 'inventory', 'Everyone who handles stock and packing.'),
            self::family('management', 'Management', 'management', 'Everyone who runs a part of the business.'),

            // ── Roles ────────────────────────────────────────────────────────
            [
                'slug' => 'administrator',
                'name' => 'Administrator',
                'parent' => 'management',
                'workspace' => 'management',
                'scope' => 'all',
                'description' => 'Everything the owner can do, for somebody who is not the owner.',
                'capabilities' => [Capabilities::ALL],
            ],
            [
                'slug' => 'manager',
                'name' => 'Manager',
                'parent' => 'management',
                'workspace' => 'management',
                'scope' => 'team',
                'description' => 'Runs a team: sees their work, their orders and the figures behind them.',
                'capabilities' => [
                    'dashboard.view',
                    'orders.view', 'orders.create', 'orders.edit', 'orders.status', 'orders.payment',
                    // A manager prices and lists what the business sells; the
                    // capability existed and was granted to nobody, so the
                    // catalogue was readable by all and editable by none.
                    'catalogue.view', 'catalogue.edit', 'stock.view',
                    'transactions.view', 'reports.view',
                    'people.view',
                ],
            ],
            [
                'slug' => 'accountant',
                'name' => 'Accountant',
                'parent' => 'accounts',
                'workspace' => 'accounts',
                'scope' => 'all',
                'description' => 'Keeps the books. Sees every entry and every report.',
                'capabilities' => [
                    'dashboard.view',
                    'transactions.view', 'transactions.create', 'transactions.edit',
                    'reports.view',
                    'orders.view', 'orders.payment',
                    'partners.view',
                ],
            ],
            [
                'slug' => 'sales-representative',
                'name' => 'Sales Representative',
                'parent' => 'sales',
                'workspace' => 'sales',
                'scope' => 'own',
                'description' => 'Takes orders and follows them through to delivery.',
                'capabilities' => [
                    'orders.view', 'orders.create', 'orders.edit', 'orders.status',
                    'catalogue.view', 'stock.view',
                ],
            ],
            [
                'slug' => 'stock-keeper',
                'name' => 'Stock Keeper',
                'parent' => 'inventory',
                'workspace' => 'inventory',
                'scope' => 'own',
                'description' => 'Receives, counts and packs. Moves stock but does not price it.',
                'capabilities' => [
                    'catalogue.view', 'stock.view', 'stock.edit',
                    'orders.view', 'orders.status',
                ],
            ],
        ];
    }

    private static function family(string $slug, string $name, string $workspace, string $description): array
    {
        return [
            'slug' => $slug,
            'name' => $name,
            'parent' => null,
            'workspace' => $workspace,
            'scope' => 'team',
            'description' => $description,
            // A family is a grouping, not a grant. Giving one capabilities
            // would mean everybody under it inherited them by accident.
            'capabilities' => [],
        ];
    }
}
