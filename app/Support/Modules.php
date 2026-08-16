<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Every department this tool covers or intends to, in one list.
 *
 * The sidebar, the "coming soon" pages and the roadmap all read from here, so
 * there is one answer to "what is this menu for" and it cannot drift from what
 * the menu actually does. It is serialised once into the Inertia page props,
 * which is why the React sidebar needs no second copy of it and cannot get out
 * of step with what the routes actually allow.
 *
 *   route → live now.
 *   (none) → the page exists and explains itself; the work does not.
 *   can   → the capability needed to see it at all.
 */
final class Modules
{
    /**
     * The paths the client actually has a screen for today.
     *
     * The catalogue below names a path for every department, built or not, so
     * the shape of the product is described in one place and does not move as
     * things ship. This is the separate, much shorter list of what exists — a
     * screen goes live by adding one line here, and until then the menu points
     * at its "coming soon" page instead.
     *
     * Deriving this from the router was possible when the server did the
     * routing; it is not now, because the routes live in the client bundle. An
     * explicit list is the honest replacement: it cannot claim a screen exists
     * when it does not.
     */
    public const BUILT = [
        '/dashboard',
        '/settings',
        '/reports',
        '/accounts',
        '/transactions',
        '/journal',
        '/credentials',
    ];

    public const GROUPS = [

        // ── What you personally are doing ────────────────────────────────────
        'work' => [
            'label' => null,
            'icon' => 'chart-line-up',
            'flat' => true,   // no parent to open — these are the top level
            'modules' => [
                'workspace' => [
                    'label' => 'My workspace', 'icon' => 'user-focus',
                    'path' => '/workspace', 'staff_only' => true,
                    'summary' => 'Your own work, your own pay, and the people who report to you.',
                ],
                'dashboard' => [
                    'label' => 'Dashboard', 'icon' => 'squares-four',
                    'path' => '/dashboard', 'can' => 'dashboard.view',
                    'summary' => 'The whole business at a glance — income, expenses, what is owed.',
                ],
            ],
        ],

        // ── Selling ──────────────────────────────────────────────────────────
        'selling' => [
            'label' => 'Sales',
            'icon' => 'shopping-cart',
            'modules' => [
                'orders' => [
                    'label' => 'Orders', 'icon' => 'shopping-cart',
                    'path' => '/orders', 'can' => 'orders.view',
                    'summary' => 'Every order from every storefront, and the money against each one.',
                ],
                'customers' => [
                    'label' => 'Customers', 'icon' => 'address-book', 'can' => 'orders.view',
                    'summary' => 'One record per buyer, kept across all their orders.',
                ],
                'returns' => [
                    'label' => 'Returns & RTO', 'icon' => 'arrow-u-down-left', 'can' => 'orders.view',
                    'summary' => 'Parcels that came back, what they cost, and why it keeps happening.',
                ],
                'courier' => [
                    'label' => 'Courier & Delivery', 'icon' => 'truck', 'can' => 'orders.view',
                    'summary' => 'Handover to couriers, tracking, and settling what they collected.',
                ],
                'pos' => [
                    'label' => 'Point of Sale', 'icon' => 'cash-register', 'can' => 'orders.create',
                    'summary' => 'Selling face to face — a counter, a shop, a stall.',
                ],
            ],
        ],

        // ── What you sell and what you hold ──────────────────────────────────
        'inventory' => [
            'label' => 'Products & Stock',
            'icon' => 'package',
            'modules' => [
                'catalogue' => [
                    'label' => 'Catalogue', 'icon' => 'squares-four',
                    'path' => '/catalogue', 'matches' => ['/catalogue'], 'can' => 'catalogue.view',
                    'summary' => 'Products and the stock behind them.',
                ],
                'procurement' => [
                    'label' => 'Purchasing', 'icon' => 'shopping-bag-open', 'can' => 'stock.edit',
                    'summary' => 'Buying from suppliers — orders out, goods in, bills to pay.',
                ],
                'warehouses' => [
                    'label' => 'Warehouses', 'icon' => 'warehouse', 'can' => 'stock.view',
                    'summary' => 'More than one place to keep stock, and moving it between them.',
                ],
            ],
        ],

        // ── Making things ────────────────────────────────────────────────────
        'production' => [
            'label' => 'Production',
            'icon' => 'factory',
            'modules' => [
                'manufacturing' => [
                    'label' => 'Production Orders', 'icon' => 'factory', 'can' => 'stock.edit',
                    'summary' => 'Turning raw material into finished goods, and what that costs.',
                ],
                'quality' => [
                    'label' => 'Quality Control', 'icon' => 'seal-check', 'can' => 'stock.edit',
                    'summary' => 'Checks on what came in, what was made, and what is going out.',
                ],
                'maintenance' => [
                    'label' => 'Maintenance', 'icon' => 'wrench', 'can' => 'stock.edit',
                    'summary' => 'Machines, vehicles, and keeping them running.',
                ],
            ],
        ],

        // ── Talking to customers ─────────────────────────────────────────────
        'conversations' => [
            'label' => 'Conversations',
            'icon' => 'chats-circle',
            'modules' => [
                'inbox' => [
                    'label' => 'Inbox', 'icon' => 'chats-circle', 'can' => 'orders.view',
                    'summary' => 'Every message from every channel, in one place.',
                ],
                'automations' => [
                    'label' => 'Automations', 'icon' => 'flow-arrow', 'can' => 'stores.edit',
                    'summary' => 'Replies and follow-ups that happen without anybody watching.',
                ],
                'templates' => [
                    'label' => 'Message Templates', 'icon' => 'chat-text', 'can' => 'stores.edit',
                    'summary' => 'The approved wordings, in the languages you sell in.',
                ],
            ],
        ],

        // ── Getting people to buy ────────────────────────────────────────────
        'marketing' => [
            'label' => 'Marketing',
            'icon' => 'megaphone',
            'modules' => [
                'campaigns' => [
                    'label' => 'Campaigns', 'icon' => 'megaphone', 'can' => 'reports.view',
                    'summary' => 'What was spent on getting orders, and what came back.',
                ],
                'offers' => [
                    'label' => 'Offers & Coupons', 'icon' => 'tag', 'can' => 'catalogue.edit',
                    'summary' => 'Discounts, codes, and bundles — and what they cost in margin.',
                ],
                'loyalty' => [
                    'label' => 'Loyalty', 'icon' => 'medal', 'can' => 'catalogue.edit',
                    'summary' => 'Points, tiers, and reasons to order again.',
                ],
            ],
        ],

        // ── Money ────────────────────────────────────────────────────────────
        'money' => [
            'label' => 'Money',
            'icon' => 'wallet',
            'modules' => [
                'transactions' => [
                    'label' => 'Transactions', 'icon' => 'receipt',
                    'path' => '/transactions', 'can' => 'transactions.view',
                    'summary' => 'The ledger — every entry, both sides.',
                ],
                'journal' => [
                    'label' => 'Daily Journal', 'icon' => 'notebook',
                    'path' => '/journal', 'can' => 'transactions.view',
                    'summary' => 'One day at a time, in and out.',
                ],
                'reports' => [
                    'label' => 'Reports', 'icon' => 'chart-line',
                    'path' => '/reports', 'can' => 'reports.view',
                    'summary' => 'Profit and loss, balance sheet, and the rest.',
                ],
                'accounts' => [
                    'label' => 'Chart of Accounts', 'icon' => 'tree-structure',
                    'path' => '/accounts', 'can' => 'transactions.view',
                    'summary' => 'How money is classified.',
                ],
                'fiscal' => [
                    'label' => 'Fiscal Years', 'icon' => 'calendar-check',
                    'path' => '/fiscal-years', 'can' => 'transactions.view',
                    'summary' => 'Accounting periods, and sealing them.',
                ],
                'receivables' => [
                    'label' => 'Receivable & Payable', 'icon' => 'scales', 'can' => 'transactions.view',
                    'summary' => 'Who owes you, who you owe, and how overdue each is.',
                ],
                'expenses' => [
                    'label' => 'Expense Claims', 'icon' => 'receipt-x', 'can' => 'transactions.create',
                    'summary' => 'What staff spent, and getting it approved and repaid.',
                ],
                'assets' => [
                    'label' => 'Assets', 'icon' => 'buildings', 'can' => 'transactions.edit',
                    'summary' => 'What the business owns, and what it is worth now.',
                ],
            ],
        ],

        // ── The people who work here ─────────────────────────────────────────
        'hr' => [
            'label' => 'HR Management',
            'icon' => 'users-three',
            'modules' => [
                'employees' => [
                    'label' => 'Employees', 'icon' => 'users-three',
                    'path' => '/people', 'matches' => ['/people'], 'can' => 'people.view',
                    'summary' => 'Everyone who works here, their job, and their pay.',
                ],
                'attendance' => [
                    'label' => 'Attendance & Leave', 'icon' => 'clock-user', 'can' => 'people.view',
                    'summary' => 'Who was in, who was not, and who is owed time off.',
                ],
                'recruitment' => [
                    'label' => 'Recruitment', 'icon' => 'user-plus', 'can' => 'people.edit',
                    'summary' => 'Vacancies, applicants, and hiring them onto the payroll.',
                ],
                'performance' => [
                    'label' => 'Performance', 'icon' => 'target', 'can' => 'people.view',
                    'summary' => 'Targets, reviews, and what somebody actually achieved.',
                ],
                'training' => [
                    'label' => 'Training', 'icon' => 'graduation-cap', 'can' => 'people.edit',
                    'summary' => 'What people have been taught, and what they still need.',
                ],
            ],
        ],

        // ── Ownership ────────────────────────────────────────────────────────
        'ownership' => [
            'label' => 'Ownership',
            'icon' => 'handshake',
            'modules' => [
                'partners' => [
                    'label' => 'Partners', 'icon' => 'handshake',
                    'path' => '/partners', 'matches' => ['/partners'], 'can' => 'partners.view',
                    'summary' => 'Capital in, drawings out, and profit shared on the agreed terms.',
                ],
            ],
        ],

        // ── Where this is going ──────────────────────────────────────────────
        'intelligence' => [
            'label' => 'Intelligence',
            'icon' => 'sparkle',
            'modules' => [
                'ai-assistant' => [
                    'label' => 'Ask Angisflow', 'icon' => 'sparkle', 'can' => 'reports.view',
                    'summary' => 'Ask a question about the business in plain language.',
                ],
                'ai-reports' => [
                    'label' => 'AI Reports', 'icon' => 'file-magnifying-glass', 'can' => 'reports.view',
                    'summary' => 'The month explained, not just tabulated.',
                ],
                'alerts' => [
                    'label' => 'Alerts & Anomalies', 'icon' => 'warning-diamond', 'can' => 'reports.view',
                    'summary' => 'Being told when something is wrong, rather than finding out.',
                ],
                'forecast' => [
                    'label' => 'Forecasting', 'icon' => 'chart-line-up', 'can' => 'reports.view',
                    'summary' => 'What the next few weeks look like, from what the last few did.',
                ],
            ],
        ],

        // ── Setting it all up ────────────────────────────────────────────────
        'setup' => [
            'label' => 'Setup',
            'icon' => 'gear-six',
            'modules' => [
                'stores' => [
                    'label' => 'Storefronts', 'icon' => 'storefront',
                    'path' => '/stores', 'matches' => ['/stores'], 'can' => 'stores.view',
                    'summary' => 'The shops you sell through, and how their fields map to yours.',
                ],
                'team' => [
                    'label' => 'Users & Roles', 'icon' => 'shield-check',
                    'path' => '/team', 'matches' => ['/team', '/roles'], 'can' => 'users.manage',
                    'summary' => 'Who may sign in, and what each role is allowed to do.',
                ],
                'settings' => [
                    'label' => 'Settings', 'icon' => 'gear',
                    'path' => '/settings', 'matches' => ['/settings'], 'can' => 'settings.view',
                    'summary' => 'Currency, integrations, media and the rest.',
                ],
                'credentials' => [
                    'label' => 'Credential Vault', 'icon' => 'key',
                    'path' => '/credentials', 'can' => 'settings.edit',
                    'summary' => 'API keys and tokens for integrations, stored encrypted.',
                ],
                'audit' => [
                    'label' => 'Audit Log', 'icon' => 'list-magnifying-glass', 'can' => 'settings.view',
                    'summary' => 'Who changed what, and when.',
                ],
            ],
        ],
    ];

    /** One module by key, with its group folded in. */
    public static function find(string $key): ?array
    {
        foreach (self::GROUPS as $groupKey => $group) {
            if (isset($group['modules'][$key])) {
                return $group['modules'][$key] + [
                    'key' => $key,
                    'group' => $groupKey,
                    'group_label' => $group['label'],
                ];
            }
        }

        return null;
    }

    /**
     * Everything not yet built, in order — the roadmap, derived not written.
     *
     * "Not built" means "not in BUILT", not "has no path". Every module names a
     * path now, whether or not a screen answers it yet, so keying off the
     * presence of one would report the whole product as finished.
     */
    public static function planned(): array
    {
        $out = [];

        foreach (self::GROUPS as $groupKey => $group) {
            foreach ($group['modules'] as $key => $module) {
                if (self::isBuilt($module)) {
                    continue;
                }

                $out[$key] = $module + [
                    'key' => $key,
                    'group' => $groupKey,
                    'group_label' => $group['label'],
                ];
            }
        }

        return $out;
    }

    /**
     * A fingerprint of the whole catalogue.
     *
     * Travels in the boot payload and forms part of the /modules URL, so the
     * browser can cache that response for a day and still never serve a stale
     * one: change the catalogue and the URL changes with it.
     *
     * The alternative — a plain max-age — is what caused the bug this exists to
     * fix. A module was promoted from "soon" to built, the deploy went out, and
     * every browser that had already asked kept the old answer for an hour.
     */
    public static function version(): string
    {
        static $version = null;

        return $version ??= substr(hash('xxh128', serialize([self::GROUPS, self::BUILT])), 0, 12);
    }

    /** Whether a screen actually answers this module's path today. */
    public static function isBuilt(array $module): bool
    {
        return isset($module['path']) && in_array($module['path'], self::BUILT, true);
    }
}
