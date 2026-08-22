<?php

declare(strict_types=1);

namespace App\Http\Api\V1;

use App\Domain\Identity\Models\User;
use App\Domain\Ledger\FinancialReports;
use App\Domain\Ledger\Models\JournalLine;
use App\Domain\Money\CurrencyService;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Tenancy\TenantContext;
use App\Http\Api\ApiResponse;
use App\Http\Api\Endpoint;
use App\Support\Modules;
use App\Support\Navigation;
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

    public function index(Request $request, TenantContext $tenant, CurrencyService $currencyService): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $key = $request->string('period', 'this_month')->value();
        $key = isset(self::PERIODS[$key]) ? $key : 'this_month';

        // Anchored to the user's own timezone. With the server on UTC and a
        // user in Asia/Dhaka, "today" would otherwise mean the UTC day — which
        // is still yesterday for the first six hours of their morning.
        $now = CarbonImmutable::now($user->timezoneOrDefault());

        /*
         * An explicit range beats a named one.
         *
         * The five presets cover most of what anybody asks for and none of them
         * can say "3 to 17 August", so the calendar sends dates and they win
         * where present. Without this the picker would be decorative — which is
         * the exact fault it was built to correct.
         *
         * Clamped to a century so a hand-edited query string cannot ask for a
         * ten-thousand-year window and have the comparison arithmetic below
         * spend the request working it out.
         */
        $explicit = $request->filled('from') && $request->filled('to');

        if ($explicit) {
            $tz = $user->timezoneOrDefault();
            $from = CarbonImmutable::parse($request->string('from')->value(), $tz)->startOfDay();
            $to = CarbonImmutable::parse($request->string('to')->value(), $tz)->endOfDay();

            if ($to->lessThan($from)) {
                [$from, $to] = [$to->startOfDay(), $from->endOfDay()];
            }

            if ($from->diffInDays($to) > 36_500) {
                $to = $from->addYears(100)->endOfDay();
            }
        } else {
            [$from, $to] = $this->range($key, $now);
        }

        [$prevFrom, $prevTo] = $this->previous($from, $to);

        $account = $tenant->account();

        $businesses = $account?->businesses()->where('is_active', true)->count() ?? 0;
        $team = $account?->users()->where('is_active', true)->count() ?? 0;
        $trialDays = $account?->trial_ends_at !== null
            ? max(0, (int) $now->diffInDays($account->trial_ends_at, false))
            : null;

        $business = $tenant->business();

        // The ledger's own currency — what actually changed hands at this
        // business's till — and the currency every figure on this screen is
        // shown in. They differ the moment a workspace holds more than one
        // business trading in different currencies, which is exactly when
        // converting stops being optional: a €4,200 figure and a ৳310,000
        // one cannot share a page unconverted without the reader doing the
        // arithmetic themselves.
        $businessCurrency = strtoupper($business?->base_currency ?? 'BDT');
        $currency = $currencyService->base();

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

                $convert = fn (int $minor): ?int => $currencyService->convertMinor($minor, $businessCurrency, $currency);

                $financialKpis = [
                    $this->moneyKpi(
                        'revenue', 'Net revenue', 'trend-up', $currency, true,
                        $convert($revenue), $convert($prevRevenue),
                    ),
                    $this->moneyKpi(
                        'profit', 'Operating profit', 'chart-line-up', $currency, true,
                        $convert($profit), $convert($prevProfit),
                    ),
                    $this->moneyKpi(
                        'receivables', 'Outstanding receivables', 'scales', $currency, false,
                        $convert($receivables), null,
                    ),
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
                    // A hand-picked window has no name, so it is described by
                    // its own dates rather than mislabelled with the preset it
                    // happens to be nearest.
                    'key'           => $explicit ? 'custom' : $key,
                    'label'         => $explicit
                        ? $this->describe($from, $to)
                        : self::PERIODS[$key],
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

                /*
                 * Whose figures these are, and what shape the screen should
                 * take for them.
                 *
                 * Sent with the figures rather than fetched separately because
                 * the two must never disagree: a header naming one business
                 * above another's revenue is the single worst thing this screen
                 * could do, and one payload makes it impossible.
                 */
                'context' => $this->context($request, $tenant),
            ],
            'meta' => [
                'generated_at' => now()->toIso8601String(),
                'timezone' => $user->timezoneOrDefault(),
            ],
        ], seconds: 15);
    }

    /**
     * Whose books these are, and what the business actually does.
     *
     * ── Multiple categories on one business ──────────────────────────────────
     *
     * A bakery with a café attached is one set of books carrying both retail and
     * hospitality, and it is the normal case rather than the exotic one. Two
     * things follow, and both were wrong before.
     *
     * The primary is the category chosen when the books were opened —
     * businesses.business_category_id, the column the pivot was seeded from. The
     * old code took categories->first(), which is whatever the join happened to
     * return: a business could see its dashboard described as a café one day and
     * a shop the next, having changed nothing. First-by-accident is not a
     * decision, and the trade a subscriber picked for themselves is.
     *
     * What the screen may show is a different question, and it is not answered
     * by the primary at all. It comes from the modules the business can reach —
     * the union across every category it carries, which Navigation already works
     * out for the menu. So the bakery gets the sales panels because it has an
     * orders module and the bookings panels because it has a bookings one, and
     * nothing here needs to know that "retail" and "hospitality" were the words
     * involved. Give a business a third category tomorrow and this keeps up on
     * its own.
     *
     * @return array<string, mixed>
     */
    private function context(Request $request, TenantContext $tenant): array
    {
        /** @var User $user */
        $user = $request->user();

        $business = $tenant->business();

        if ($business === null) {
            return [
                'business' => null,
                'primary_category' => null,
                'categories' => [],
                'capabilities' => [],
                'capabilities_by_category' => [],
            ];
        }

        $business->loadMissing(['categories.parent', 'category']);

        $categories = $business->categories
            ->map(fn ($category) => [
                'id' => $category->getAttributes()['id'],
                'key' => $category->key,
                'name' => $category->name,
                'icon' => $category->icon,
            ])
            ->values()
            ->all();

        // The chosen trade, not the first row back from the join. Falls back to
        // the pivot only when the column is empty — a business created straight
        // into the many-to-many with no primary ever recorded.
        $primary = $business->category !== null
            ? [
                'id' => $business->category->getAttributes()['id'],
                'key' => $business->category->key,
                'name' => $business->category->name,
                'icon' => $business->category->icon,
            ]
            : ($categories[0] ?? null);

        $capabilities = Navigation::capabilitiesFor($user, $tenant->workspace(), $business);

        return [
            'business' => [
                'id' => $business->public_id,
                'name' => $business->name,
                'currency' => $business->base_currency,
            ],
            'primary_category' => $primary,
            'categories' => $categories,
            // Only the modules that are genuinely open. A panel is a link to a
            // screen; offering one for a module that has not shipped is the
            // dead-link problem this codebase has already had once.
            'capabilities' => $capabilities['built'],
            // Keyed by category id — see Navigation::capabilitiesByCategory.
            // What lets the category chips act as tabs: clicking "Café"
            // filters the union above down to just what that category turns
            // on, without a second request.
            'capabilities_by_category' => $capabilities['by_category'],
        ];
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

    /**
     * One money KPI, built from an already-converted minor amount.
     *
     * Null rather than a guess when there is no rate for the pair — the same
     * rule CurrencyService is built around. A figure converted at par because
     * no rate existed would look exactly as plausible as a real one and be
     * wrong by whatever the true rate is, which is worse than plainly saying
     * the number could not be worked out.
     *
     * @return array<string, mixed>
     */
    private function moneyKpi(
        string $key,
        string $label,
        string $icon,
        string $currency,
        bool $riseIsGood,
        ?int $convertedMinor,
        ?int $convertedPrevMinor,
    ): array {
        if ($convertedMinor === null) {
            return [
                'key'         => $key,
                'label'       => $label,
                'icon'        => $icon,
                'value'       => 'Rate unavailable',
                'raw'         => 0,
                'delta'       => null,
                'direction'   => 'flat',
                'rise_is_good' => $riseIsGood,
            ];
        }

        return [
            'key'         => $key,
            'label'       => $label,
            'icon'        => $icon,
            'value'       => (new Money($convertedMinor, $currency))->toDecimalString(),
            'raw'         => $convertedMinor,
            'delta'       => $convertedPrevMinor !== null ? $this->delta($convertedMinor, $convertedPrevMinor) : null,
            'direction'   => $convertedPrevMinor !== null ? $this->direction($convertedMinor, $convertedPrevMinor) : 'flat',
            'rise_is_good' => $riseIsGood,
        ];
    }

    /**
     * Percentage change between two periods.
     *
     * Zero against zero is 0%, not an unknown: nothing happened either
     * month, and "no change" is exactly what that is. Growth *from* zero is
     * the genuinely undefined one — every positive figure is an infinite
     * increase on nothing — so that stays null and the card says so rather
     * than printing a made-up +100%.
     */
    private function delta(int $current, int $previous): ?float
    {
        if ($previous === 0) {
            return $current === 0 ? 0.0 : null;
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
