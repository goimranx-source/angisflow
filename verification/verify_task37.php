<?php

/**
 * Task 37 — Entitlement Resolution verification
 *
 * Run:
 *   php artisan tinker --execute="require 'D:\\Povaly Group\\Applications\\angisflow\\angisflow\\verification\\verify_task37.php'"
 */

use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Billing\PlanEntitlement;
use App\Domain\Catalogue\Contracts\ModuleEntitlement;
use App\Domain\Catalogue\ModuleAccess;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Account;
use App\Domain\Tenancy\Models\Workspace;
use App\Domain\Tenancy\TenantContext;

$account   = Account::withoutGlobalScopes()->find(1);
$workspace = Workspace::withoutGlobalScopes()->where('account_id', 1)->first();
$user      = User::withoutGlobalScopes()->where('account_id', 1)->first();

$t = app(TenantContext::class);
$t->setAccount($account);
$t->setWorkspace($workspace);

$try = function (string $label, callable $fn) {
    try {
        $out = $fn();
        echo "  OK      {$label}" . ($out ? " -> {$out}" : '') . PHP_EOL;
    } catch (Throwable $e) {
        echo "  REFUSED {$label} -> " . $e->getMessage() . PHP_EOL;
    }
};

$entitlement = app(ModuleEntitlement::class);

echo PHP_EOL . '── Binding ──────────────────────────────────────────────────────' . PHP_EOL;

$try('ModuleEntitlement bound to PlanEntitlement', function () use ($entitlement) {
    if (! $entitlement instanceof PlanEntitlement) {
        throw new RuntimeException('Bound to ' . get_class($entitlement) . ', expected PlanEntitlement');
    }
    return get_class($entitlement);
});

echo PHP_EOL . '── Demo account (growth plan) ───────────────────────────────────' . PHP_EOL;

$try('Growth plan resolves permitted keys', function () use ($entitlement, $workspace) {
    PlanEntitlement::forgetAccount($workspace->account_id);
    $keys = $entitlement->permittedKeys($workspace);
    if ($keys === null) {
        throw new RuntimeException('Returned null — expected a list for growth plan');
    }
    return count($keys) . ' keys, first 5: ' . implode(', ', array_slice($keys, 0, 5));
});

$try('Growth plan includes revenue.orders', function () use ($entitlement, $workspace) {
    $keys = $entitlement->permittedKeys($workspace);
    if (! in_array('revenue.orders', $keys ?? [], true)) {
        throw new RuntimeException('revenue.orders not in permitted keys');
    }
    return 'present';
});

$try('Growth plan includes finance.reports', function () use ($entitlement, $workspace) {
    $keys = $entitlement->permittedKeys($workspace);
    if (! in_array('finance.reports', $keys ?? [], true)) {
        throw new RuntimeException('finance.reports not in permitted keys');
    }
    return 'present';
});

$try('Growth plan (no intelligence feature) blocks intelligence.ask', function () use ($entitlement, $workspace) {
    $sub      = Subscription::withoutGlobalScopes()->where('account_id', 1)->latest('id')->with('plan')->first();
    $features = $sub?->plan?->features ?? [];
    if (in_array('intelligence', $features, true)) {
        return 'intelligence feature present on this plan — skip';
    }
    $keys = $entitlement->permittedKeys($workspace);
    if (in_array('intelligence.ask', $keys ?? [], true)) {
        throw new RuntimeException('intelligence.ask permitted but intelligence not in plan features');
    }
    return 'intelligence.ask correctly blocked';
});

echo PHP_EOL . '── Trial (no subscription) → unrestricted ───────────────────────' . PHP_EOL;

$trialAccount = Account::withoutGlobalScopes()->forceCreate([
    'name' => '__verify37_trial__', 'slug' => '__verify37__',
    'status' => 'trialing', 'base_currency' => 'BDT',
]);
$trialWorkspace = Workspace::withoutGlobalScopes()->create([
    'account_id' => $trialAccount->id,
    'name' => '__verify37_ws__', 'slug' => '__verify37_ws__',
]);

$try('No subscription → permittedKeys returns null (unrestricted)', function () use ($entitlement, $trialWorkspace) {
    PlanEntitlement::forgetAccount($trialWorkspace->account_id);
    $keys = $entitlement->permittedKeys($trialWorkspace);
    if ($keys !== null) {
        throw new RuntimeException('Expected null, got ' . count($keys) . ' keys');
    }
    return 'null — correct';
});

echo PHP_EOL . '── Empty features → unrestricted ────────────────────────────────' . PHP_EOL;

$emptyPlan = Plan::create([
    'code' => '__verify37_empty__', 'name' => 'Empty Test',
    'price_minor' => 0, 'currency' => 'BDT', 'features' => [], 'limits' => [],
]);
$testSub = Subscription::create([
    'account_id' => $trialAccount->id, 'plan_id' => $emptyPlan->id,
    'status' => Subscription::STATUS_ACTIVE, 'started_at' => now(),
]);

$try('Empty features → permittedKeys returns null (unrestricted)', function () use ($entitlement, $trialWorkspace) {
    PlanEntitlement::forgetAccount($trialWorkspace->account_id);
    $keys = $entitlement->permittedKeys($trialWorkspace);
    if ($keys !== null) {
        throw new RuntimeException('Expected null, got ' . count($keys) . ' keys');
    }
    return 'null — correct';
});

echo PHP_EOL . '── Restricted plan (orders + ledger only) ───────────────────────' . PHP_EOL;

$restrictedPlan = Plan::create([
    'code' => '__verify37_restricted__', 'name' => 'Restricted Test',
    'price_minor' => 0, 'currency' => 'BDT',
    'features' => ['orders', 'ledger'], 'limits' => [],
]);
$testSub->update(['plan_id' => $restrictedPlan->id]);

$try('orders+ledger permits revenue.orders and finance.reports', function () use ($entitlement, $trialWorkspace) {
    PlanEntitlement::forgetAccount($trialWorkspace->account_id);
    $keys = $entitlement->permittedKeys($trialWorkspace);
    if ($keys === null) {
        throw new RuntimeException('Expected restricted list, got null');
    }
    if (! in_array('revenue.orders', $keys, true) || ! in_array('finance.reports', $keys, true)) {
        throw new RuntimeException('Missing expected keys. Got: ' . implode(', ', $keys));
    }
    return count($keys) . ' keys, revenue.orders ✓, finance.reports ✓';
});

$try('orders+ledger blocks people.payroll', function () use ($entitlement, $trialWorkspace) {
    $keys = $entitlement->permittedKeys($trialWorkspace);
    if (in_array('people.payroll', $keys ?? [], true)) {
        throw new RuntimeException('people.payroll permitted but payroll not in plan');
    }
    return 'people.payroll correctly blocked';
});

$try('orders+ledger blocks intelligence.ask', function () use ($entitlement, $trialWorkspace) {
    $keys = $entitlement->permittedKeys($trialWorkspace);
    if (in_array('intelligence.ask', $keys ?? [], true)) {
        throw new RuntimeException('intelligence.ask permitted but intelligence not in plan');
    }
    return 'intelligence.ask correctly blocked';
});

echo PHP_EOL . '── ModuleAccess integration ─────────────────────────────────────' . PHP_EOL;

$try('ModuleAccess.available() works with real entitlement for demo account', function () use ($workspace, $user) {
    $access    = app(ModuleAccess::class);
    $available = $access->availableKeys($workspace, $user);
    if (empty($available)) {
        throw new RuntimeException('No modules available — entitlement may be over-restricting');
    }
    return count($available) . ' modules available';
});

echo PHP_EOL . '── Route ────────────────────────────────────────────────────────' . PHP_EOL;

$try('GET /entitlement route registered', function () {
    $route = app('router')->getRoutes()->getByName('api.v1.entitlement');
    if ($route === null) {
        throw new RuntimeException('Route api.v1.entitlement not found');
    }
    return implode('|', $route->methods()) . ' ' . $route->uri();
});

// ── Cleanup ───────────────────────────────────────────────────────────────────
$testSub->forceDelete();
$emptyPlan->forceDelete();
$restrictedPlan->forceDelete();
$trialWorkspace->forceDelete();
$trialAccount->forceDelete();
PlanEntitlement::forgetAccount($trialAccount->id);

echo PHP_EOL . '  (test data deleted)' . PHP_EOL . PHP_EOL;
