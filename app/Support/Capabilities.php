<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Everything a person can be allowed to do, in one list.
 *
 * One catalogue rather than permissions scattered through the code, so the
 * question "what can this role do" has a single answer and the screen that
 * hands them out cannot drift from the checks that enforce them.
 *
 * ── Why this is a constant and not a table ───────────────────────────────────
 *
 * A permissions table means a query — usually several, usually joined — on
 * every request that asks whether somebody may do something, which on a page
 * with thirty guarded elements is thirty round trips to prove a fact that
 * changes about twice a year. Packages that do this are the single commonest
 * cause of a slow authorisation layer at scale.
 *
 * The capability list is code because it *is* code: adding one means writing
 * the feature it guards. What a role holds is data and lives in the database,
 * as a JSON array on the role — read once per request, turned into a hash set,
 * and answered from memory thereafter. Roles are cached by id and version, so
 * the usual cost of "may this person do X" is an array lookup.
 */
final class Capabilities
{
    /** Owners hold this implicitly; it can also be granted to a role. */
    public const ALL = '*';

    /**
     * Grouped the way the sidebar is grouped, because that is how somebody
     * assigning them thinks: they are not granting `orders.status`, they are
     * deciding whether this person may move an order along.
     */
    public const GROUPS = [
        'Selling' => [
            'orders.view' => 'See orders',
            'orders.create' => 'Take new orders',
            'orders.edit' => 'Change order details',
            'orders.status' => 'Move orders along (shipped, delivered…)',
            'orders.payment' => 'Record and reverse payments',
            'orders.delete' => 'Trash and restore orders',
        ],
        'Catalogue' => [
            'catalogue.view' => 'See products',
            'catalogue.edit' => 'Add and change products',
            'stock.view' => 'See stock levels',
            'stock.edit' => 'Move and adjust stock',
        ],
        'Money' => [
            'transactions.view' => 'See the ledger',
            'transactions.create' => 'Add transactions',
            'transactions.edit' => 'Change and void transactions',
            'reports.view' => 'Open the reports',
        ],
        'People' => [
            'people.view' => 'See employees and customers',
            'people.edit' => 'Add and change them',
            'payroll.run' => 'Run payroll',
            'partners.view' => 'See partners and their positions',
            'partners.edit' => 'Capital, drawings, profit sharing',
        ],
        'Setup' => [
            'dashboard.view' => 'See the dashboard',
            'stores.view' => 'See storefronts and their layouts',
            'stores.edit' => 'Change storefronts and integrations',
            'settings.view' => 'Open settings',
            'settings.edit' => 'Change settings, currency and media',
            'users.manage' => 'Add people and set what they can do',
        ],
    ];

    /**
     * Where each capability lands somebody, so a role can open on a page its
     * holder can actually reach.
     *
     * Paths, not route names. Routing is the client's job now — the server has
     * exactly one page route — so a name here would be a name nothing could
     * resolve.
     */
    public const LANDINGS = [
        'dashboard.view' => '/dashboard',
        'orders.view' => '/orders',
        'catalogue.view' => '/catalogue',
        'stock.view' => '/catalogue/stock',
        'transactions.view' => '/transactions',
        'reports.view' => '/reports',
        'people.view' => '/people',
        'partners.view' => '/partners',
        'stores.view' => '/stores',
        'settings.view' => '/settings',
    ];

    /** @var array<string, string>|null */
    private static ?array $flat = null;

    /**
     * Flat slug => label, for validation and lookups.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        return self::$flat ??= array_merge(...array_values(self::GROUPS));
    }

    /** @return list<string> */
    public static function slugs(): array
    {
        return array_keys(self::all());
    }

    public static function label(string $slug): string
    {
        return self::all()[$slug] ?? $slug;
    }

    public static function exists(string $slug): bool
    {
        return $slug === self::ALL || isset(self::all()[$slug]);
    }

    /**
     * Keep only the capabilities that actually exist.
     *
     * A role carrying a slug nothing checks is a permission somebody believes
     * they granted and did not, which is worse than an obvious refusal.
     *
     * @param  iterable<mixed>  $candidates
     * @return list<string>
     */
    public static function sanitise(iterable $candidates): array
    {
        $kept = [];

        foreach ($candidates as $slug) {
            if (is_string($slug) && self::exists($slug) && ! in_array($slug, $kept, true)) {
                $kept[] = $slug;
            }
        }

        return $kept;
    }
}
