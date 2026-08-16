<?php

/**
 * Task 35 verification — Dashboards and AI
 *
 * Tests: P&L, balance sheet, trial balance, receivables aging,
 * chart of accounts, account ledger, and dashboard financial KPIs.
 */

use App\Domain\Ledger\FinancialReports;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Tenancy\Models\Account;
use App\Domain\Tenancy\Models\Business;
use App\Domain\Tenancy\TenantContext;

$b = Business::withoutGlobalScopes()->whereNull('deleted_at')->where('account_id', 1)->first();
$t = app(TenantContext::class);
$t->setAccount(Account::withoutGlobalScopes()->find(1));
$t->setBusiness($b);

$try = function (string $label, callable $fn) {
    try {
        $out = $fn();
        echo "  OK      {$label}" . ($out ? " -> {$out}" : '') . PHP_EOL;
    } catch (Throwable $e) {
        echo "  REFUSED {$label} -> " . $e->getMessage() . PHP_EOL;
    }
};

$reports = app(FinancialReports::class);
$today = now()->toDateString();
$monthStart = now()->startOfMonth()->toDateString();

echo PHP_EOL . "── Financial Reports ────────────────────────────────────────────" . PHP_EOL;

$try('P&L returns correct shape', function () use ($reports, $monthStart, $today) {
    $pl = $reports->profitAndLoss($monthStart, $today);
    assert(array_key_exists('net_revenue', $pl), 'missing net_revenue');
    assert(array_key_exists('gross_profit', $pl), 'missing gross_profit');
    assert(array_key_exists('operating_profit', $pl), 'missing operating_profit');
    assert(array_key_exists('gross_margin_pct', $pl), 'missing gross_margin_pct');
    return "net_revenue={$pl['net_revenue']['minor']} {$pl['currency']}";
});

$try('Balance sheet returns correct shape', function () use ($reports, $today) {
    $bs = $reports->balanceSheet($today);
    assert(array_key_exists('assets', $bs), 'missing assets');
    assert(array_key_exists('liabilities', $bs), 'missing liabilities');
    assert(array_key_exists('total_equity', $bs), 'missing total_equity');
    assert(array_key_exists('balances', $bs), 'missing balances');
    return 'balanced=' . ($bs['balances'] ? 'true' : 'false');
});

$try('Trial balance returns correct shape', function () use ($reports, $monthStart, $today) {
    $tb = $reports->trialBalance($monthStart, $today);
    assert(array_key_exists('rows', $tb), 'missing rows');
    assert(array_key_exists('balanced', $tb), 'missing balanced');
    assert($tb['debit'] instanceof \App\Domain\Shared\ValueObjects\Money, 'debit not Money');
    return count($tb['rows']) . ' accounts, balanced=' . ($tb['balanced'] ? 'true' : 'false');
});

$try('Receivables aging returns correct shape', function () use ($reports, $today) {
    $aging = $reports->receivablesAging($today);
    assert(array_key_exists('buckets', $aging), 'missing buckets');
    assert(array_key_exists('current', $aging['buckets']), 'missing current bucket');
    assert(array_key_exists('over_90', $aging['buckets']), 'missing over_90 bucket');
    return "total={$aging['total']['minor']}";
});

echo PHP_EOL . "── Chart of Accounts ────────────────────────────────────────────" . PHP_EOL;

$try('Postable accounts exist', function () use ($t) {
    $count = LedgerAccount::postable()->count();
    assert($count > 0, 'no postable accounts found');
    return "{$count} postable accounts";
});

$try('Account ledger works for a real account', function () use ($reports, $monthStart, $today) {
    $account = LedgerAccount::postable()->first();
    if ($account === null) {
        return 'no accounts to test';
    }
    $ledger = $reports->accountLedger($account, $monthStart, $today);
    assert(array_key_exists('rows', $ledger), 'missing rows');
    assert(array_key_exists('opening', $ledger), 'missing opening');
    assert(array_key_exists('closing', $ledger), 'missing closing');
    return "{$account->code} {$account->name} — " . count($ledger['rows']) . ' rows';
});

echo PHP_EOL . "── Dashboard financial KPIs ─────────────────────────────────────" . PHP_EOL;

$try('Dashboard endpoint resolves financial KPIs when business is open', function () use ($t) {
    $endpoint = app(\App\Http\Api\V1\DashboardEndpoint::class);
    $request = \Illuminate\Http\Request::create('/api/v1/dashboard', 'GET', ['period' => 'this_month']);
    $request->setUserResolver(fn () => \App\Domain\Identity\Models\User::withoutGlobalScopes()->where('account_id', 1)->first());
    $response = $endpoint->index($request, $t);
    $data = json_decode($response->getContent(), true);
    assert(isset($data['data']['kpis']), 'missing kpis');
    assert(isset($data['data']['trading_ready']), 'missing trading_ready');
    $tradingReady = $data['data']['trading_ready'] ? 'true' : 'false';
    $kpiCount = count($data['data']['kpis']);
    return "trading_ready={$tradingReady}, kpis={$kpiCount}";
});

echo PHP_EOL . "── AI Assistant ─────────────────────────────────────────────────" . PHP_EOL;

$try('Assistant is configured', function () {
    $configured = \App\Domain\Assistant\Assistant::isConfigured();
    return $configured ? 'yes' : 'no (OPENROUTER_API_KEY not set)';
});

$try('System prompt references Angisflow not Prism', function () {
    $reflection = new ReflectionClass(\App\Domain\Assistant\Assistant::class);
    $method = $reflection->getMethod('systemPrompt');
    $method->setAccessible(true);
    $assistant = new \App\Domain\Assistant\Assistant();
    $prompt = $method->invoke($assistant, []);
    assert(!str_contains($prompt, 'You answer questions about Prism'), 'prompt still says Prism');
    assert(str_contains($prompt, 'Angisflow'), 'prompt does not mention Angisflow');
    return 'OK — says Angisflow';
});

echo PHP_EOL . "── Modules::BUILT ───────────────────────────────────────────────" . PHP_EOL;

$try('New money modules are in BUILT', function () {
    $built = \App\Support\Modules::BUILT;
    foreach (['/reports', '/accounts', '/transactions', '/journal'] as $path) {
        assert(in_array($path, $built, true), "{$path} not in BUILT");
    }
    return implode(', ', $built);
});

echo PHP_EOL;
