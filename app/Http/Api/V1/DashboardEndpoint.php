<?php

declare(strict_types=1);

namespace App\Http\Api\V1;

use App\Domain\Identity\Models\User;
use App\Domain\Ledger\FinancialReports;
use App\Domain\Ledger\Models\JournalLine;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Tenancy\TenantContext;
use App\Http\Api\ApiResponse;
use App\Http\Api\Endpoint;
use App\Support\Modules;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The dashboard's figures.
 *
 * Fetched by the client rather than sent with the page, which is what lets the
 * screen paint before the numbers exist and fill in underneath.
 *
 * ── What this returns today ──────────────────────────────────────────────────
 *
 * The trading modules — orders, ledger, stock — are not built yet, so the
 * figures here are the ones that are genuinely true now: how many businesses,
 * how many people, where the trial stands. No invented revenue. A dashboard
 * showing a confident ৳0 for income reads as a business having a terrible
 * month rather than as a module that has not shipped.
 *
 * The shape is final. As each module lands its figures join the same array and
 * the client needs no change at all.
 *
 * ── The caching this is built for ────────────────────────────────────────────
 *
 * Every trading figure will be an aggregate over a period, and aggregates are
 * the queries that get slow first. The rule for this endpoint as it grows: read
 * from a rollup table maintained as orders and entries are written — never
 * SUM() a hundred million rows on request. A rollup keyed
 * (account_id, business_id, day) turns "this month" into thirty indexed rows
 * whatever the volume behind it.
 */
class DashboardEndpoint extends Endpoint
{
    /** The ranges the client offers. */
    private const PERIODS = [
        'today' => 'Today',
        'last_7_days' => 'Last 7 days',
        'this_month' => 'This month',
        'last_month' => 'Last month',
        'this_year' => 'This year',
    ];

    public function index(Request $request, TenantContext $tenant): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $key = $request->string('period', 'this_month')->value();
        $key = isset(self::PERIODS[$key]) ? $key : 'this_month';

        // Anchored to the user's own timezone. With the server on UTC and a
        // user in Asia/Dhaka, "today" would otherwise mean the UTC day — which
        // is still yesterday for the first six hours of their morning.
        $now = CarbonImmutable::now($user->timezoneOrDefault());

        [$from, $to] = $this->range($key, $now);
        [$prevFrom, $prevTo] = $this->previous($from, $to);

        $account = $tenant->account();

        $businesses = $account?->businesses()->where('is_active', true)->count() ?? 0;
        $team = $account?->users()->where('is_active', true)->count() ?? 0;
        $trialDays = $account?->trial_ends_at !== null
            ? max(0, (int) $now->diffInDays($account->trial_ends_at, false))
            : null;

        $business = $tenant->business();
        $currency = $business?->base_currency ?? $account?->base_currency ?? 'BDT';

        // Financial KPIs — only when a business is open and has ledger data.
        // The comparison period uses the same window shifted back, so the delta
        // is always apples-to-apples.
        $financialKpis = [];
        $tradingReady = false;

        if ($business !== null) {
            $tradingReady = true;
            $reports = app(FinancialReports::class);

            try {
                $pl      = $reports->profitAndLoss($from->toDateString(), $to->toDateString());
                $prevPl  = $reports->profitAndLoss($prevFrom->toDateString(), $prevTo->toDateString());
                $aging   = $reports->receivablesAging($to->toDateString());

                $revenue     = $pl['net_revenue']['minor'] ?? 0;
                $prevRevenue = $prevPl['net_revenue']['minor'] ?? 0;
                $profit      = $pl['operating_profit']['minor'] ?? 0;
                $prevProfit  = $prevPl['operating_profit']['minor'] ?? 0;
                $receivables = $aging['total']['minor'] ?? 0;

                $financialKpis = [
                    [
                        'key'         => 'revenue',
                        'label'       => 'Net revenue',
                        'icon'        => 'trend-up',
                        'value'       => (new Money($revenue, $currency))->toDecimalString(),
                        'raw'         => $revenue,
                        'delta'       => $this->delta($revenue, $prevRevenue),
                        'direction'   => $this->direction($revenue, $prevRevenue),
                        'rise_is_good' => true,
                    ],
                    [
                        'key'         => 'profit',
                        'label'       => 'Operating profit',
                        'icon'        => 'chart-line-up',
                        'value'       => (new Money($profit, $currency))->toDecimalString(),
                        'raw'         => $profit,
                        'delta'       => $this->delta($profit, $prevProfit),
                        'direction'   => $this->direction($profit, $prevProfit),
                        'rise_is_good' => true,
                    ],
                    [
                        'key'         => 'receivables',
                        'label'       => 'Outstanding receivables',
                        'icon'        => 'scales',
                        'value'       => (new Money($receivables, $currency))->toDecimalString(),
                        'raw'         => $receivables,
                        'delta'       => null,
                        'direction'   => 'flat',
                        'rise_is_good' => false,
                    ],
                ];
            } catch (\Throwable) {
                // No ledger data yet — fall through to the account KPIs.
                $tradingReady = false;
            }
        }

        $accountKpis = [
            [
                'key'         => 'businesses',
                'label'       => 'Businesses',
                'icon'        => 'buildings',
                'value'       => (string) $businesses,
                'raw'         => $businesses,
                'delta'       => null,
                'direction'   => 'flat',
                'rise_is_good' => true,
            ],
            [
                'key'         => 'team',
                'label'       => 'People with a login',
                'icon'        => 'users-three',
                'value'       => (string) $team,
                'raw'         => $team,
                'delta'       => null,
                'direction'   => 'flat',
                'rise_is_good' => true,
            ],
            [
                'key'         => 'plan',
                'label'       => 'Subscription',
                'icon'        => 'wallet',
                'value'       => $trialDays !== null
                    ? $trialDays.' '.($trialDays === 1 ? 'day left' : 'days left')
                    : ucfirst((string) $account?->status),
                'raw'         => $trialDays ?? 0,
                'delta'       => null,
                'direction'   => 'flat',
                'rise_is_good' => true,
            ],
        ];

        return ApiResponse::cacheable([
            'data' => [
                'period' => [
                    'key'           => $key,
                    'label'         => self::PERIODS[$key],
                    'compare_label' => $this->describe($prevFrom, $prevTo),
                    'from'          => $from->toDateString(),
                    'to'            => $to->toDateString(),
                ],

                'currency' => $currency,

                /*
                 * Financial KPIs when a business is open and has ledger data;
                 * account-level KPIs otherwise. The client renders whichever
                 * arrives — the shape is the same either way.
                 */
                'kpis' => $tradingReady ? $financialKpis : $accountKpis,

                'trading_ready' => $tradingReady,
            ],
            'meta' => [
                'generated_at' => now()->toIso8601String(),
                'timezone' => $user->timezoneOrDefault(),
            ],
        ], seconds: 15);
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function range(string $key, CarbonImmutable $now): array
    {
        return match ($key) {
            'today' => [$now->startOfDay(), $now->endOfDay()],
            'last_7_days' => [$now->subDays(6)->startOfDay(), $now->endOfDay()],
            'last_month' => [
                $now->subMonthNoOverflow()->startOfMonth(),
                $now->subMonthNoOverflow()->endOfMonth(),
            ],
            'this_year' => [$now->startOfYear(), $now->endOfYear()],
            default => [$now->startOfMonth(), $now->endOfMonth()],
        };
    }

    /**
     * The equally long window immediately before the selected one.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function previous(CarbonImmutable $from, CarbonImmutable $to): array
    {
        // Start-of-day to start-of-day. Carbon returns a float here, so a
        // 23:59:59 end date yields 30.99… and silently widens the window by a
        // day — which makes every comparison quietly wrong.
        $days = (int) $from->startOfDay()->diffInDays($to->startOfDay()) + 1;

        $prevTo = $from->subDay()->endOfDay();

        return [$prevTo->subDays($days - 1)->startOfDay(), $prevTo];
    }

    /** Percentage change, or null when the base is zero (undefined, not infinite). */
    private function delta(int $current, int $previous): ?float
    {
        if ($previous === 0) {
            return null;
        }

        return round(($current - $previous) / abs($previous) * 100, 1);
    }

    private function direction(int $current, int $previous): string
    {
        if ($current > $previous) return 'up';
        if ($current < $previous) return 'down';
        return 'flat';
    }

    private function describe(CarbonImmutable $from, CarbonImmutable $to): string
    {
        if ($from->isSameDay($to)) {
            return $from->format('j M Y');
        }

        return $from->year === $to->year
            ? $from->format('j M').' – '.$to->format('j M Y')
            : $from->format('j M Y').' – '.$to->format('j M Y');
    }
}
