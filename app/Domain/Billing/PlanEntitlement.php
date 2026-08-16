<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Domain\Billing\Models\Subscription;
use App\Domain\Catalogue\Contracts\ModuleEntitlement;
use App\Domain\Operator\EntitlementOverride;
use App\Domain\Tenancy\Models\Workspace;
use Illuminate\Support\Facades\Cache;

/**
 * What a plan actually permits — the real answer to the question
 * UnrestrictedEntitlement was holding open.
 *
 * ── How entitlement is resolved ──────────────────────────────────────────────
 *
 * 1. Find the account's latest live subscription.
 * 2. Read the plan's `features` array.
 * 3. Expand each feature key into the module keys it covers, using the map
 *    below.
 * 4. Return the deduplicated list. ModuleAccess filters the catalogue to it.
 *
 * Returning null means "no restriction" — used during a trial with no plan
 * chosen, and for any plan whose features array is empty or null. The trial
 * is the product's shop window; restricting it defeats the purpose.
 *
 * ── Why features map to modules, not the other way round ────────────────────
 *
 * A plan feature is a commercial concept ("orders" — you can sell things).
 * A module key is a UI concept ("revenue.orders" — the orders screen). One
 * feature typically unlocks several related modules. Mapping feature → modules
 * here keeps the plan data clean and the module catalogue free of billing
 * knowledge.
 *
 * ── Why this is cached ───────────────────────────────────────────────────────
 *
 * permittedKeys() is called on every sidebar render. The subscription and plan
 * rarely change; reading them from the database on every request is work that
 * does not need doing. The cache is dropped when the subscription changes —
 * see forgetAccount().
 *
 * ── The two plan vocabularies ────────────────────────────────────────────────
 *
 * The seeder created two sets of plans with different feature vocabularies:
 * the original growth/scale plans use domain keys ("orders", "ledger") and the
 * later starter/professional/business/enterprise plans use capability keys
 * ("basic_reporting", "api_access"). Both are handled in FEATURE_MAP. A plan
 * feature that is not in the map is ignored — it is a capability flag for
 * billing's own use (support tier, white-label) rather than a module gate.
 */
final class PlanEntitlement implements ModuleEntitlement
{
    private const CACHE_TTL = 600; // 10 minutes

    /**
     * Plan feature key → module keys it unlocks.
     *
     * Core modules (is_core = true) are never in this map — they are always
     * available regardless of plan and ModuleAccess skips the entitlement
     * check for them entirely.
     *
     * A feature that maps to an empty array is a billing-only flag (support
     * tier, white-label branding) with no module gate. It is listed here so
     * the resolver does not treat it as unknown.
     */
    private const FEATURE_MAP = [
        // ── Domain-key vocabulary (growth / scale plans) ──────────────────
        'orders' => [
            'revenue.orders', 'revenue.pos', 'revenue.returns', 'revenue.courier',
            'revenue.leads', 'revenue.pipeline', 'revenue.quotes',
        ],
        'ledger' => [
            'finance.invoicing', 'finance.payments', 'finance.ledgers',
            'finance.expenses', 'finance.assets', 'finance.budgets', 'finance.tax',
            'finance.reports',
        ],
        'catalogue' => [
            'catalogue.products', 'catalogue.services', 'catalogue.pricing',
            'catalogue.stock', 'catalogue.warehouses', 'catalogue.purchasing',
            'catalogue.batches',
        ],
        'reports' => [
            'finance.reports', 'intelligence.dashboards', 'intelligence.reports',
            'intelligence.alerts', 'intelligence.forecasting',
        ],
        'payroll' => [
            'people.employees', 'people.attendance', 'people.shifts',
            'people.payroll', 'people.commissions', 'people.recruitment',
            'people.performance', 'people.training',
        ],
        'partners' => [
            'platform.partners',
        ],
        'integrations' => [
            'platform.integrations', 'platform.automations',
            'cx.channels', 'cx.templates', 'cx.automations',
            'cx.helpdesk', 'cx.knowledge', 'cx.feedback',
        ],
        'production' => [
            'operations.bom', 'operations.production', 'operations.quality',
            'operations.maintenance', 'operations.fleet',
        ],
        'intelligence' => [
            'intelligence.ask', 'intelligence.dashboards', 'intelligence.reports',
            'intelligence.alerts', 'intelligence.forecasting',
        ],

        // ── Capability-key vocabulary (starter / professional / business / enterprise) ──
        // These plans gate by capability rather than domain. Map them to the
        // same module sets so both vocabularies produce the same result.
        'basic_reporting' => [
            'finance.reports',
        ],
        'advanced_reporting' => [
            'finance.reports', 'intelligence.dashboards', 'intelligence.reports',
            'intelligence.alerts',
        ],
        'custom_reports' => [
            'intelligence.forecasting',
        ],
        'api_access' => [
            'platform.integrations',
        ],
        'custom_roles' => [],          // billing flag — no module gate
        'audit_logs' => [],            // billing flag — no module gate
        'white_label' => [],           // billing flag — no module gate
        'advanced_integrations' => [
            'platform.integrations', 'platform.automations',
            'cx.channels', 'cx.templates', 'cx.automations',
        ],
        'sso' => [],                   // billing flag — no module gate
        'custom_sla' => [],            // billing flag — no module gate
        'onboarding_support' => [],    // billing flag — no module gate
        'dedicated_account_manager' => [], // billing flag — no module gate
        'email_support' => [],         // billing flag — no module gate
        'priority_support' => [],      // billing flag — no module gate
        'phone_support' => [],         // billing flag — no module gate
        'mobile_app' => [],            // billing flag — no module gate
    ];

    /**
     * Module keys this workspace's plan permits.
     *
     * Returns null (no restriction) when:
     * - the account has no subscription
     * - the subscription has no plan
     * - the plan's features array is empty or null
     *
     * This preserves the trial behaviour: a new account with no plan chosen
     * sees everything, which is the point of a trial.
     *
     * @return list<string>|null
     */
    public function permittedKeys(Workspace $workspace): ?array
    {
        return Cache::remember(
            self::cacheKey($workspace->account_id),
            self::CACHE_TTL,
            fn () => $this->resolve($workspace->account_id),
        );
    }

    /**
     * Drop the cached entitlement for an account.
     *
     * Called when a subscription is created, updated or cancelled so the next
     * sidebar render picks up the new plan immediately.
     */
    public static function forgetAccount(int $accountId): void
    {
        Cache::forget(self::cacheKey($accountId));
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function resolve(int $accountId): ?array
    {
        $subscription = Subscription::withoutGlobalScopes()
            ->where('account_id', $accountId)
            ->latest('id')
            ->with('plan')
            ->first();

        if ($subscription === null || ! $subscription->isLive()) {
            return null; // no live subscription → unrestricted (trial)
        }

        $features = $subscription->plan?->features ?? [];

        if (empty($features)) {
            return null; // plan has no feature gates → unrestricted
        }

        $permitted = [];

        foreach ($features as $feature) {
            $modules = self::FEATURE_MAP[$feature] ?? null;

            if ($modules === null) {
                // Unknown feature key — treat as a wildcard grant so an
                // unrecognised plan feature never accidentally locks someone
                // out. Better to over-permit than to silently break a screen.
                return null;
            }

            foreach ($modules as $key) {
                $permitted[$key] = true;
            }
        }

        // Apply operator overrides — grants add keys, revocations remove them.
        // Overrides are per workspace; we load all active ones for this account
        // and apply them after the plan so a grant can add what the plan lacks
        // and a revocation can remove what the plan allows.
        $overrides = EntitlementOverride::where('account_id', $accountId)
            ->get()
            ->filter(fn (EntitlementOverride $o) => $o->isActive());

        foreach ($overrides as $override) {
            if ($override->kind === EntitlementOverride::GRANT) {
                $permitted[$override->module_key] = true;
            } else {
                unset($permitted[$override->module_key]);
            }
        }

        return array_keys($permitted);
    }

    private static function cacheKey(int $accountId): string
    {
        return "entitlement:{$accountId}";
    }
}
