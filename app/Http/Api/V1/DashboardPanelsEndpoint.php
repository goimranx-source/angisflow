<?php

declare(strict_types=1);

namespace App\Http\Api\V1;

use App\Domain\Identity\Models\User;
use App\Domain\Money\CurrencyService;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Tenancy\TenantContext;
use App\Http\Api\Endpoint;
use App\Support\Navigation;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The dashboard's panels.
 *
 * The headline figures live in DashboardEndpoint; this is everything beneath
 * them — the chart, the cash strip, and the four lists. Each is its own request
 * because each is its own query: the client paints the shell immediately and
 * fills the panels in as they land, so one slow aggregate never holds up the
 * other six.
 *
 * ── Empty is a real answer ───────────────────────────────────────────────────
 *
 * Every method here reads the actual tables and returns what it finds. Today
 * that is nothing: no orders have been placed, no products created, no journal
 * lines posted. So these return empty lists, and the client renders its empty
 * state — "no orders yet" — rather than an error.
 *
 * That distinction is the whole point. Before this endpoint existed the client
 * asked for these seven paths, got a 404, and showed "Could not load recent
 * orders" seven times over. A subscriber reading that has been told their data
 * failed to arrive, when the truth is they have not sold anything yet. One is a
 * fault to report; the other is a shop that opened this morning.
 *
 * The counterpart rule, inherited from DashboardEndpoint: no invented figures.
 * A seeded ৳18,500 of revenue here would read as a real month's trading to the
 * person looking at it.
 *
 * ── As the modules land ──────────────────────────────────────────────────────
 *
 * The queries below already point at the columns the trading modules write, so
 * the panels populate on their own as rows appear. The scaling rule is the one
 * DashboardEndpoint states: when these aggregates get slow, read from a rollup
 * keyed (business_id, day) rather than widening the SUM().
 */
class DashboardPanelsEndpoint extends Endpoint
{
    public function salesChart(Request $request, TenantContext $tenant, CurrencyService $currencyService): JsonResponse
    {
        $business = $tenant->business();
        $businessCurrency = $this->currency($tenant);
        $currency = $currencyService->base();
        $now = $this->now($request);

        // The same range the rest of the dashboard reads — the picker at the
        // top of the page, not a second period control living inside this
        // one panel. Two controls that can disagree about what "the period"
        // means is worse than one that is occasionally a click further away.
        [$from, $to] = $request->filled('from') && $request->filled('to')
            ? [CarbonImmutable::parse($request->string('from')->value())->startOfDay(), CarbonImmutable::parse($request->string('to')->value())->endOfDay()]
            : [$now->startOfMonth(), $now->endOfMonth()];

        // A single day reads by the hour rather than as one bar for the
        // whole day — "today" is exactly the range a subscriber picks when
        // they want to see when today's trade actually happened, and a
        // single column cannot answer that. ordered_on has no time of day
        // to bucket by at all, so the hourly path reads created_at instead.
        $isSingleDay = $from->isSameDay($to);
        $buckets = $isSingleDay ? $this->hourlyBuckets($from) : $this->rangeBuckets($from, $to);

        $revenue = [];
        $orders = [];
        $totalRevenue = 0;
        $totalOrders = 0;

        if ($business !== null) {
            if ($isSingleDay) {
                // Raw rows, matched to their hour in PHP — a day's worth of
                // orders is a small enough set that this costs nothing, and
                // created_at has no truncated column SQL could group by
                // portably across the databases this runs on.
                $orderRows = DB::table('orders')
                    ->where('business_id', $business->id)
                    ->whereNull('cancelled_at')
                    ->whereBetween('created_at', [$from, $to])
                    ->select('created_at', 'total_minor')
                    ->get();

                foreach ($buckets as $bucket) {
                    $bucketRevenue = 0;
                    $bucketOrders = 0;

                    foreach ($orderRows as $row) {
                        $at = CarbonImmutable::parse($row->created_at);
                        if ($at->betweenIncluded($bucket['from'], $bucket['to'])) {
                            $bucketRevenue += (int) $row->total_minor;
                            $bucketOrders++;
                        }
                    }

                    $convertedRevenue = $currencyService->convertMinor($bucketRevenue, $businessCurrency, $currency) ?? 0;

                    $revenue[] = ['label' => $bucket['label'], 'value' => $convertedRevenue];
                    $orders[] = ['label' => $bucket['label'], 'value' => $bucketOrders];
                    $totalRevenue += $convertedRevenue;
                    $totalOrders += $bucketOrders;
                }
            } else {
                // One grouped query per series rather than one per bucket —
                // thirty buckets must not mean thirty round trips.
                $rows = DB::table('orders')
                    ->where('business_id', $business->id)
                    ->whereNull('cancelled_at')
                    ->whereBetween('ordered_on', [
                        $buckets[0]['from']->toDateString(),
                        end($buckets)['to']->toDateString(),
                    ])
                    ->selectRaw('ordered_on, SUM(total_minor) AS revenue_minor, COUNT(*) AS order_count')
                    ->groupBy('ordered_on')
                    ->get()
                    ->keyBy(fn ($row) => (string) $row->ordered_on);

                foreach ($buckets as $bucket) {
                    $bucketRevenue = 0;
                    $bucketOrders = 0;

                    foreach ($rows as $date => $row) {
                        $day = CarbonImmutable::parse($date);
                        if ($day->betweenIncluded($bucket['from'], $bucket['to'])) {
                            $bucketRevenue += (int) $row->revenue_minor;
                            $bucketOrders += (int) $row->order_count;
                        }
                    }

                    // Converted per bucket rather than once on the total —
                    // the series itself is shown in the workspace's
                    // currency, not just the headline figure beside it. A
                    // missing rate is treated as zero for the bucket rather
                    // than breaking the chart; the settings screen is where
                    // that gap gets fixed.
                    $convertedRevenue = $currencyService->convertMinor($bucketRevenue, $businessCurrency, $currency) ?? 0;

                    $revenue[] = ['label' => $bucket['label'], 'value' => $convertedRevenue];
                    $orders[] = ['label' => $bucket['label'], 'value' => $bucketOrders];
                    $totalRevenue += $convertedRevenue;
                    $totalOrders += $bucketOrders;
                }
            }
        } else {
            foreach ($buckets as $bucket) {
                $revenue[] = ['label' => $bucket['label'], 'value' => 0];
                $orders[] = ['label' => $bucket['label'], 'value' => 0];
            }
        }

        return response()->json([
            'data' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'currency' => $currency,
                'series' => ['revenue' => $revenue, 'orders' => $orders],
                'totals' => [
                    'revenue' => $totalRevenue,
                    'revenue_formatted' => (new Money($totalRevenue, $currency))->toDecimalString(),
                    'orders' => $totalOrders,
                ],
            ],
        ]);
    }

    public function cashFlow(Request $request, TenantContext $tenant, CurrencyService $currencyService): JsonResponse
    {
        $business = $tenant->business();
        $businessCurrency = $this->currency($tenant);
        $currency = $currencyService->base();
        $now = $this->now($request);

        // The same range the rest of the dashboard reads. No panel-local
        // "last 30 days" any more — the picker at the top says what window
        // is in view, and this chart follows it like every other one.
        [$from, $to] = $request->filled('from') && $request->filled('to')
            ? [CarbonImmutable::parse($request->string('from')->value())->startOfDay(), CarbonImmutable::parse($request->string('to')->value())->endOfDay()]
            : [$now->startOfMonth(), $now->endOfMonth()];

        // Single day: hourly, for the same reason salesChart() does it — a
        // day picked to see when today's trade happened cannot be answered
        // by one column for the whole day, and entry_date has no time of
        // day to bucket by at all.
        $isSingleDay = $from->isSameDay($to);
        $buckets = $isSingleDay ? $this->hourlyBuckets($from) : $this->rangeBuckets($from, $to);

        $series = [];
        // Money in and money out kept as their own series, not just netted into
        // the running balance. A flat balance line is the same picture for a
        // business trading nothing as for one taking and spending equally, and
        // those are not the same month.
        $inflow = [];
        $outflow = [];
        $cashIn = 0;
        $cashOut = 0;
        $balance = 0;

        if ($business !== null) {
            if ($isSingleDay) {
                // Raw rows, matched to their hour in PHP — see salesChart()'s
                // identical reasoning for created_at over the date column.
                $lineRows = DB::table('journal_lines')
                    ->join('ledger_accounts', 'ledger_accounts.id', '=', 'journal_lines.ledger_account_id')
                    ->where('journal_lines.business_id', $business->id)
                    ->where('ledger_accounts.type', 'asset')
                    ->where('ledger_accounts.is_spendable', true)
                    ->whereBetween('journal_lines.created_at', [$from, $to])
                    ->select('journal_lines.created_at', 'journal_lines.debit_minor', 'journal_lines.credit_minor')
                    ->get();

                foreach ($buckets as $bucket) {
                    $bucketIn = 0;
                    $bucketOut = 0;

                    foreach ($lineRows as $row) {
                        $at = CarbonImmutable::parse($row->created_at);
                        if ($at->betweenIncluded($bucket['from'], $bucket['to'])) {
                            $bucketIn += (int) $row->debit_minor;
                            $bucketOut += (int) $row->credit_minor;
                        }
                    }

                    $in = $currencyService->convertMinor($bucketIn, $businessCurrency, $currency) ?? 0;
                    $out = $currencyService->convertMinor($bucketOut, $businessCurrency, $currency) ?? 0;

                    $cashIn += $in;
                    $cashOut += $out;
                    $balance += $in - $out;

                    $series[] = ['label' => $bucket['label'], 'value' => $balance];
                    $inflow[] = ['label' => $bucket['label'], 'value' => $in];
                    $outflow[] = ['label' => $bucket['label'], 'value' => $out];
                }
            } else {
                // The cash line only — asset accounts money can actually be
                // spent from. A debit to one of those is cash in, a credit
                // is cash out.
                //
                // journal_lines carries its own business_id and entry_date,
                // so this needs no join back to journal_entries.
                $rows = DB::table('journal_lines')
                    ->join('ledger_accounts', 'ledger_accounts.id', '=', 'journal_lines.ledger_account_id')
                    ->where('journal_lines.business_id', $business->id)
                    ->where('ledger_accounts.type', 'asset')
                    ->where('ledger_accounts.is_spendable', true)
                    ->whereBetween('journal_lines.entry_date', [
                        $from->toDateString(),
                        $to->toDateString(),
                    ])
                    ->selectRaw('journal_lines.entry_date AS d, SUM(journal_lines.debit_minor) AS in_minor, SUM(journal_lines.credit_minor) AS out_minor')
                    ->groupBy('journal_lines.entry_date')
                    ->get()
                    ->keyBy(fn ($row) => CarbonImmutable::parse($row->d)->toDateString());

                foreach ($buckets as $bucket) {
                    $bucketIn = 0;
                    $bucketOut = 0;

                    foreach ($rows as $date => $row) {
                        $day = CarbonImmutable::parse($date);
                        if ($day->betweenIncluded($bucket['from'], $bucket['to'])) {
                            $bucketIn += (int) $row->in_minor;
                            $bucketOut += (int) $row->out_minor;
                        }
                    }

                    $in = $currencyService->convertMinor($bucketIn, $businessCurrency, $currency) ?? 0;
                    $out = $currencyService->convertMinor($bucketOut, $businessCurrency, $currency) ?? 0;

                    $cashIn += $in;
                    $cashOut += $out;
                    $balance += $in - $out;

                    $series[] = ['label' => $bucket['label'], 'value' => $balance];
                    $inflow[] = ['label' => $bucket['label'], 'value' => $in];
                    $outflow[] = ['label' => $bucket['label'], 'value' => $out];
                }
            }
        } else {
            foreach ($buckets as $bucket) {
                $series[] = ['label' => $bucket['label'], 'value' => 0];
                $inflow[] = ['label' => $bucket['label'], 'value' => 0];
                $outflow[] = ['label' => $bucket['label'], 'value' => 0];
            }
        }

        $net = $cashIn - $cashOut;

        return response()->json([
            'data' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'currency' => $currency,
                'current_balance' => $balance,
                'current_balance_formatted' => (new Money($balance, $currency))->toDecimalString(),
                'cash_in_total' => $cashIn,
                'cash_in_formatted' => (new Money($cashIn, $currency))->toDecimalString(),
                'cash_out_total' => $cashOut,
                'cash_out_formatted' => (new Money($cashOut, $currency))->toDecimalString(),
                'net_formatted' => (new Money($net, $currency))->toDecimalString(),
                'series' => $series,
                'inflow' => $inflow,
                'outflow' => $outflow,
            ],
        ]);
    }

    /**
     * Where the money went, by ledger account.
     *
     * Grouped by account rather than by any hand-written category list: the
     * chart of accounts is the categorisation the business already keeps, and it
     * is the one their accountant will recognise. Anything past the top few is
     * rolled into "Other" — a ring with fourteen segments is a colour wheel, not
     * a breakdown.
     */
    public function expenseBreakdown(Request $request, TenantContext $tenant, CurrencyService $currencyService): JsonResponse
    {
        $business = $tenant->business();
        $businessCurrency = $this->currency($tenant);
        $currency = $currencyService->base();
        $now = $this->now($request);
        $limit = $this->limit($request, 5);

        // The same range the rest of the dashboard reads — see salesChart().
        [$from, $to] = $request->filled('from') && $request->filled('to')
            ? [CarbonImmutable::parse($request->string('from')->value())->startOfDay(), CarbonImmutable::parse($request->string('to')->value())->endOfDay()]
            : [$now->startOfMonth(), $now->endOfMonth()];

        $slices = [];
        $total = 0;

        if ($business !== null) {
            $rows = DB::table('journal_lines')
                ->join('ledger_accounts', 'ledger_accounts.id', '=', 'journal_lines.ledger_account_id')
                ->where('journal_lines.business_id', $business->id)
                ->where('ledger_accounts.type', 'expense')
                ->whereBetween('journal_lines.entry_date', [
                    $from->toDateString(),
                    $to->toDateString(),
                ])
                // An expense account is debited to increase it; a credit is a
                // refund or reclassification and nets off rather than counting
                // as more spending.
                ->groupBy('ledger_accounts.id', 'ledger_accounts.name')
                ->selectRaw('ledger_accounts.name, SUM(journal_lines.debit_minor - journal_lines.credit_minor) AS spent_minor')
                ->havingRaw('SUM(journal_lines.debit_minor - journal_lines.credit_minor) > 0')
                ->orderByDesc('spent_minor')
                ->get()
                ->map(function ($row) use ($currencyService, $businessCurrency, $currency) {
                    $row->spent_minor = $currencyService->convertMinor((int) $row->spent_minor, $businessCurrency, $currency) ?? 0;

                    return $row;
                });

            $top = $rows->take($limit);
            $rest = $rows->skip($limit);

            $slices = $top
                ->map(fn ($row) => [
                    'label' => (string) $row->name,
                    'value' => (int) $row->spent_minor,
                    'formatted' => (new Money((int) $row->spent_minor, $currency))->toDecimalString(),
                ])
                ->values()
                ->all();

            if ($rest->isNotEmpty()) {
                $other = (int) $rest->sum('spent_minor');

                $slices[] = [
                    'label' => 'Other',
                    'value' => $other,
                    'formatted' => (new Money($other, $currency))->toDecimalString(),
                ];
            }

            $total = (int) $rows->sum('spent_minor');
        }

        return response()->json([
            'data' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'currency' => $currency,
                'total' => $total,
                'total_formatted' => (new Money($total, $currency))->toDecimalString(),
                'slices' => $slices,
            ],
        ]);
    }

    public function topProducts(Request $request, TenantContext $tenant, CurrencyService $currencyService): JsonResponse
    {
        $business = $tenant->business();
        $businessCurrency = $this->currency($tenant);
        $currency = $currencyService->base();
        $limit = $this->limit($request, 5);

        $products = [];

        if ($business !== null) {
            // Lines record a variant, not a product, so the roll-up to product
            // goes through product_variants. Grouping on the product means two
            // sizes of one shirt count as that shirt rather than as two lines.
            $products = DB::table('order_lines')
                ->join('orders', 'orders.id', '=', 'order_lines.order_id')
                ->leftJoin('product_variants', 'product_variants.id', '=', 'order_lines.product_variant_id')
                ->leftJoin('products', 'products.id', '=', 'product_variants.product_id')
                ->where('orders.business_id', $business->id)
                ->whereNull('orders.cancelled_at')
                ->groupBy('products.id', 'products.public_id', 'products.name')
                ->selectRaw('products.public_id, products.name, MIN(order_lines.sku) AS sku, SUM(order_lines.quantity) AS qty, SUM(order_lines.total_minor) AS revenue_minor')
                ->orderByDesc('revenue_minor')
                ->limit($limit)
                ->get()
                ->map(function ($row) use ($currencyService, $businessCurrency, $currency) {
                    $revenue = $currencyService->convertMinor((int) $row->revenue_minor, $businessCurrency, $currency) ?? 0;

                    return [
                        'id' => (string) ($row->public_id ?? ''),
                        'name' => $row->name ?? 'Unknown product',
                        'sku' => $row->sku !== null ? (string) $row->sku : null,
                        'image_url' => null,
                        'quantity_sold' => (int) $row->qty,
                        'revenue' => $revenue,
                        'revenue_formatted' => (new Money($revenue, $currency))->toDecimalString(),
                    ];
                })
                ->all();
        }

        return response()->json([
            'data' => [
                'period' => 'this_month',
                'currency' => $currency,
                'products' => $products,
            ],
        ]);
    }

    public function recentOrders(Request $request, TenantContext $tenant, CurrencyService $currencyService): JsonResponse
    {
        $business = $tenant->business();
        $businessCurrency = $this->currency($tenant);
        $currency = $currencyService->base();
        $limit = $this->limit($request, 10);

        $orders = [];

        if ($business !== null) {
            $orders = DB::table('orders')
                ->leftJoin('customers', 'customers.id', '=', 'orders.customer_id')
                ->where('orders.business_id', $business->id)
                ->orderByDesc('orders.created_at')
                ->limit($limit)
                ->select([
                    'orders.public_id',
                    'orders.number',
                    'orders.status',
                    'orders.total_minor',
                    'orders.created_at',
                    'orders.shipping_name',
                    'customers.name AS customer_name',
                ])
                ->get()
                ->map(fn ($row) => [
                    'id' => (string) $row->public_id,
                    'order_number' => (string) $row->number,
                    // Walk-in sales have no customer record; the shipping name
                    // is the only name that exists for them.
                    'customer_name' => $row->customer_name ?? $row->shipping_name ?? 'Walk-in',
                    'total_formatted' => (new Money(
                        $currencyService->convertMinor((int) $row->total_minor, $businessCurrency, $currency) ?? 0,
                        $currency,
                    ))->toDecimalString(),
                    'status' => (string) $row->status,
                    'status_label' => ucfirst(str_replace('_', ' ', (string) $row->status)),
                    'status_variant' => $this->statusVariant((string) $row->status),
                    'created_at' => (string) $row->created_at,
                    'created_at_human' => CarbonImmutable::parse($row->created_at)->diffForHumans(),
                ])
                ->all();
        }

        return response()->json(['data' => ['orders' => $orders, 'currency' => $currency]]);
    }

    public function topCustomers(Request $request, TenantContext $tenant, CurrencyService $currencyService): JsonResponse
    {
        $business = $tenant->business();
        $businessCurrency = $this->currency($tenant);
        $currency = $currencyService->base();
        $limit = $this->limit($request, 5);

        $customers = [];

        if ($business !== null) {
            // customers carries its own rollup columns, so this is an ordered
            // read rather than an aggregate over every order ever placed.
            $customers = DB::table('customers')
                ->where('business_id', $business->id)
                ->whereNull('deleted_at')
                ->whereNull('merged_into_id')
                ->where('lifetime_value_minor', '>', 0)
                ->orderByDesc('lifetime_value_minor')
                ->limit($limit)
                ->get(['public_id', 'name', 'email', 'order_count', 'lifetime_value_minor'])
                ->map(function ($row) use ($currencyService, $businessCurrency, $currency) {
                    $ltv = $currencyService->convertMinor((int) $row->lifetime_value_minor, $businessCurrency, $currency) ?? 0;

                    return [
                        'id' => (string) $row->public_id,
                        'name' => (string) $row->name,
                        'email' => (string) ($row->email ?? ''),
                        'avatar_url' => null,
                        'total_orders' => (int) $row->order_count,
                        'lifetime_value' => $ltv,
                        'lifetime_value_formatted' => (new Money($ltv, $currency))->toDecimalString(),
                    ];
                })
                ->all();
        }

        return response()->json([
            'data' => [
                'period' => 'all_time',
                'currency' => $currency,
                'customers' => $customers,
            ],
        ]);
    }

    public function pendingTasks(Request $request, TenantContext $tenant): JsonResponse
    {
        $business = $tenant->business();
        $tasks = [];

        if ($business !== null) {
            $unpaid = DB::table('orders')
                ->where('business_id', $business->id)
                ->whereNull('cancelled_at')
                ->where('payment_status', '!=', 'paid')
                ->count();

            if ($unpaid > 0) {
                $tasks[] = [
                    'id' => 'orders-unpaid',
                    'type' => 'payment',
                    'title' => $unpaid . ' order' . ($unpaid === 1 ? '' : 's') . ' awaiting payment',
                    'description' => 'Chase payment or mark as settled.',
                    'icon' => 'currency-circle-dollar',
                    'href' => '/orders?payment_status=unpaid',
                    'priority' => 'high',
                ];
            }

            $unfulfilled = DB::table('orders')
                ->where('business_id', $business->id)
                ->whereNull('cancelled_at')
                ->whereNull('fulfilled_at')
                ->count();

            if ($unfulfilled > 0) {
                $tasks[] = [
                    'id' => 'orders-unfulfilled',
                    'type' => 'order',
                    'title' => $unfulfilled . ' order' . ($unfulfilled === 1 ? '' : 's') . ' to fulfil',
                    'description' => 'Pack and dispatch outstanding orders.',
                    'icon' => 'package',
                    'href' => '/orders?fulfilment_status=pending',
                    'priority' => 'medium',
                ];
            }
        }

        return response()->json(['data' => ['tasks' => $tasks]]);
    }

    /**
     * The actions offered beneath the panels.
     *
     * Filtered by what this subscriber's plan and category actually put in the
     * menu — offering "New order" to a business whose category has no orders
     * module is a link to a screen they cannot open.
     */
    public function quickActions(Request $request, TenantContext $tenant): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $candidates = [
            ['id' => 'new-order',    'label' => 'New order',    'description' => 'Record a sale',            'icon' => 'plus-circle',   'href' => '/orders',    'module' => 'revenue.orders'],
            ['id' => 'new-customer', 'label' => 'Add customer', 'description' => 'Create a customer record', 'icon' => 'user-plus',     'href' => '/customers', 'module' => 'revenue.customers'],
            ['id' => 'new-product',  'label' => 'Add product',  'description' => 'Add to the catalogue',     'icon' => 'package',       'href' => '/products',  'module' => 'catalogue.products'],
            ['id' => 'new-invoice',  'label' => 'New invoice',  'description' => 'Bill a customer',          'icon' => 'receipt',       'href' => '/invoicing', 'module' => 'finance.invoicing'],
        ];

        $allowed = collect(Navigation::forUser($user, $tenant->workspace(), $tenant->business()))
            ->pluck('items')
            ->flatten(1)
            ->pluck('key')
            ->all();

        $actions = collect($candidates)
            ->filter(fn ($action) => in_array($action['module'], $allowed, true))
            ->map(fn ($action) => [
                'id' => $action['id'],
                'label' => $action['label'],
                'description' => $action['description'],
                'icon' => $action['icon'],
                'href' => $action['href'],
            ])
            ->values()
            ->all();

        return response()->json(['data' => ['actions' => $actions]]);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function now(Request $request): CarbonImmutable
    {
        /** @var User $user */
        $user = $request->user();

        return CarbonImmutable::now($user->timezoneOrDefault());
    }

    /**
     * What actually changed hands — the open business's own ledger currency.
     *
     * Not what gets shown: every amount pulled from these tables is converted
     * into CurrencyService::base() (the workspace's reporting currency)
     * before it reaches a response. This is only the "from" side of that
     * conversion.
     */
    private function currency(TenantContext $tenant): string
    {
        return strtoupper(
            $tenant->business()?->base_currency
                ?? $tenant->account()?->base_currency
                ?? 'BDT',
        );
    }

    private function limit(Request $request, int $default): int
    {
        return max(1, min(50, (int) $request->integer('limit', $default)));
    }

    /**
     * @return list<array{label: string, from: CarbonImmutable, to: CarbonImmutable}>
     */
    /**
     * The selected range, split into columns a chart can actually show.
     *
     * The granularity adapts to the span rather than being a choice somebody
     * makes separately: a week reads naturally as one column per day, but a
     * year at daily resolution is three hundred and sixty-five slivers no
     * axis can label. So the same picker that says "this year" gets months
     * instead of days, without a second control anywhere asking which.
     *
     * @return list<array{label: string, from: CarbonImmutable, to: CarbonImmutable}>
     */
    /**
     * One day, split into its twenty-four hours.
     *
     * The counterpart to rangeBuckets() for the one range that function
     * cannot serve: a single day is a single column at its coarsest
     * granularity, and "today" is exactly the range somebody picks when a
     * whole day is not the resolution they want. Labelled "12 AM", "1 AM" —
     * the same wording a clock face uses, not "00:00" — so it reads at a
     * glance rather than asking to be parsed.
     *
     * @return list<array{label: string, from: CarbonImmutable, to: CarbonImmutable}>
     */
    private function hourlyBuckets(CarbonImmutable $day): array
    {
        $buckets = [];

        for ($hour = 0; $hour < 24; $hour++) {
            $start = $day->setTime($hour, 0, 0);

            $buckets[] = [
                'label' => $start->format('g A'),
                'from' => $start,
                'to' => $start->setTime($hour, 59, 59),
            ];
        }

        return $buckets;
    }

    private function rangeBuckets(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $from = $from->startOfDay();
        $to = $to->endOfDay();
        $days = $from->diffInDays($to) + 1;

        // A year of months (up to 12 columns) once the range would otherwise
        // print more than about four months of individual days.
        if ($days > 120) {
            $buckets = [];
            $cursor = $from->startOfMonth();

            while ($cursor->lte($to)) {
                $monthEnd = $cursor->endOfMonth();
                $buckets[] = [
                    'label' => $cursor->format('M'),
                    'from' => $cursor,
                    'to' => $monthEnd->lte($to) ? $monthEnd : $to,
                ];
                $cursor = $cursor->addMonthNoOverflow()->startOfMonth();
            }

            return $buckets;
        }

        // A week per column once daily would otherwise run past about nine
        // weeks of columns — still fine-grained enough to see a trend, at a
        // third of the labels.
        if ($days > 62) {
            $buckets = [];
            $cursor = $from;

            while ($cursor->lte($to)) {
                $weekEnd = $cursor->addDays(6)->endOfDay();
                $weekEnd = $weekEnd->lte($to) ? $weekEnd : $to;

                $buckets[] = [
                    'label' => $cursor->format('j M'),
                    'from' => $cursor,
                    'to' => $weekEnd,
                ];
                $cursor = $weekEnd->addDay()->startOfDay();
            }

            return $buckets;
        }

        $buckets = [];
        $cursor = $from;

        while ($cursor->lte($to)) {
            $buckets[] = [
                'label' => $cursor->format('j M'),
                'from' => $cursor->startOfDay(),
                'to' => $cursor->endOfDay(),
            ];
            $cursor = $cursor->addDay();
        }

        return $buckets;
    }

    private function statusVariant(string $status): string
    {
        return match ($status) {
            'completed', 'delivered', 'fulfilled' => 'success',
            'cancelled', 'refunded', 'failed' => 'error',
            'pending', 'on_hold' => 'warning',
            'processing', 'shipped', 'confirmed' => 'info',
            default => 'default',
        };
    }
}
