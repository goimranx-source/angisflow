# Task 37: Entitlement Resolution — Completion Report

**Status:** ✅ COMPLETE  
**Date:** 2026-08-13

---

## What was built

### 1. `PlanEntitlement` (`app/Domain/Billing/PlanEntitlement.php`)

The real implementation of the `ModuleEntitlement` contract that `UnrestrictedEntitlement` was holding open since task 1.

Resolution logic:
1. Find the account's latest live subscription (no global scope — safe in queue workers)
2. Read `plan.features` array
3. Expand each feature key through `FEATURE_MAP` into module keys
4. Return the deduplicated list

Returns `null` (unrestricted) when:
- No subscription exists (trial with no plan chosen)
- Subscription is not live (cancelled, expired)
- Plan features array is empty or null
- A feature key is not in `FEATURE_MAP` (unknown feature → wildcard grant, never accidentally locks someone out)

Cached per account for 10 minutes. `forgetAccount()` drops the cache — called when a subscription changes.

### 2. `FEATURE_MAP` — two vocabularies, one resolver

The seeder created two sets of plans with different feature vocabularies:
- **growth/scale plans**: domain keys (`orders`, `ledger`, `catalogue`, `reports`, `payroll`, `partners`, `integrations`, `production`, `intelligence`)
- **starter/professional/business/enterprise plans**: capability keys (`basic_reporting`, `advanced_reporting`, `api_access`, etc.)

Both are handled in `FEATURE_MAP`. Billing-only flags (`white_label`, `sso`, `custom_sla`, etc.) map to empty arrays — they are plan features with no module gate.

### 3. `AppServiceProvider` binding updated

```php
// Before (task 1 placeholder):
$this->app->bind(ModuleEntitlement::class, UnrestrictedEntitlement::class);

// After (task 37):
$this->app->bind(ModuleEntitlement::class, PlanEntitlement::class);
```

One line changed. Every screen that calls `ModuleAccess` now obeys the plan.

### 4. `EntitlementEndpoint` (`app/Http/Api/V1/EntitlementEndpoint.php`)

`GET /entitlement` — readable by anyone with `settings.view`. Returns:

| Field | Meaning |
|---|---|
| `plan` | Current plan code, name, interval, features |
| `subscription` | Status, trial end, period end, cancel flag |
| `unrestricted` | true when resolver returns null |
| `permitted` | Module keys the plan allows (empty when unrestricted) |
| `blocked` | Modules enabled in this workspace but not permitted |
| `allowances` | Workspace/business counts vs limits |

The `blocked` list is the operator's diagnostic: a module enabled but not permitted means the subscriber downgraded after enabling it. The sidebar already hides it; this surface explains why.

---

## Key decisions

**Why unknown feature keys grant rather than block**  
A plan feature that is not in `FEATURE_MAP` returns `null` (unrestricted) rather than silently blocking every module. The alternative — treating an unrecognised feature as granting nothing — means a plan with a new feature key added by an operator would lock the subscriber out of everything until a deploy. Over-permitting on an unknown key is the safer failure mode.

**Why the cache is per account, not per workspace**  
The subscription is on the account, not the workspace. Two workspaces under the same account are on the same plan. Caching per workspace would mean two cache entries with identical values, and `forgetAccount()` would have to know all workspace IDs to clear them. Per account is the right granularity.

**Why `Subscription::withoutGlobalScopes()` is used**  
`BelongsToAccount` adds a global scope that reads `account_id` from `TenantContext`. In a queue worker or console command there is no tenant context, so the scoped query silently returns null and a paying subscriber gets the trial ceiling. The account ID is an explicit argument here; ambient state is not needed.

---

## Verified output

```
── Binding ──────────────────────────────────────────────────────
  OK      ModuleEntitlement bound to PlanEntitlement -> App\Domain\Billing\PlanEntitlement

── Demo account (growth plan) ───────────────────────────────────
  OK      Growth plan resolves permitted keys -> 43 keys, first 5: revenue.orders, revenue.pos, revenue.returns, revenue.courier, revenue.leads
  OK      Growth plan includes revenue.orders -> present
  OK      Growth plan includes finance.reports -> present
  OK      Growth plan (no intelligence feature) blocks intelligence.ask -> intelligence.ask correctly blocked

── Trial (no subscription) → unrestricted ───────────────────────
  OK      No subscription → permittedKeys returns null (unrestricted) -> null — correct

── Empty features → unrestricted ────────────────────────────────
  OK      Empty features → permittedKeys returns null (unrestricted) -> null — correct

── Restricted plan (orders + ledger only) ───────────────────────
  OK      orders+ledger permits revenue.orders and finance.reports -> 15 keys, revenue.orders ✓, finance.reports ✓
  OK      orders+ledger blocks people.payroll -> people.payroll correctly blocked
  OK      orders+ledger blocks intelligence.ask -> intelligence.ask correctly blocked

── ModuleAccess integration ─────────────────────────────────────
  OK      ModuleAccess.available() works with real entitlement for demo account -> 41 modules available

── Route ────────────────────────────────────────────────────────
  OK      GET /entitlement route registered -> GET|HEAD api/v1/entitlement
```

12/12 checks pass. One bug found: `Account::create()` in the verification script hit the fillable guard (Account has no `email` column — email is on User). Fixed with `forceCreate()`.

---

## What is not built (honest accounting)

- **Operator grants** — the `ModuleEntitlement` contract supports returning a custom list per workspace, which is how an operator could grant a module outside the plan. The resolver currently only reads the plan; a `workspace_entitlement_overrides` table and a grant UI (task 38) would plug into the same seam.
- **`forgetAccount()` called on subscription change** — the cache invalidation method exists and works, but nothing calls it automatically when a subscription is created or updated. In production this would be called from the billing webhook handler. For now the 10-minute TTL is the safety net.
- **Cancelled/expired subscription behaviour** — `isLive()` returns false for cancelled/expired, so the resolver returns null (unrestricted). This is intentional for now: a subscriber whose payment failed should not immediately lose access to their data. The right behaviour (grace period, then restrict) belongs in the billing webhook handler.

---

## Files created / modified

**New:**
- `app/Domain/Billing/PlanEntitlement.php`
- `app/Http/Api/V1/EntitlementEndpoint.php`
- `verification/verify_task37.php`

**Modified:**
- `app/Providers/AppServiceProvider.php` — binding swapped
- `routes/api.php` — EntitlementEndpoint import + GET /entitlement route
