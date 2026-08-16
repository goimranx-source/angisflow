<?php

declare(strict_types=1);

namespace App\Http\Api\V1;

use App\Domain\Identity\Models\User;
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
    public function salesChart(Request $request, TenantContext $tenant): JsonResponse
    {
        $business = $tenant->business();
        $currency = $this->currency($tenant);
        $period = $request->string('period', '30d')->value();
        $period = in_array($period, ['7d', '30d', '12m'], true) ? $period : '30d';

        $now = $this->now($request);
        $buckets = $this->buckets($period, $now);

        $revenue = [];
        $orders = [];
        $totalRevenue = 0;
        $totalOrders = 0;

        if ($business !== null) {
            // One grouped query per series rather than one per bucket — thirty
            // buckets must not mean thirty round trips.
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

                $revenue[] = ['label' => $bucket['label'], 'value' => $bucketRevenue];
                $orders[] = ['label' => $bucket['label'], 'value' => $bucketOrders];
                $totalRevenue += $bucketRevenue;
                $totalOrders += $bucketOrders;
            }
        } else {
            foreach ($buckets as $bucket) {
                $revenue[] = ['label' => $bucket['label'], 'value' => 0];
                $orders[] = ['label' => $bucket['label'], 'value' => 0];
            }
        }

        return response()->json([
            'data' => [
                'period' => $period,
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

    public function cashFlow(Request $request, TenantContext $tenant): JsonResponse
    {
        $business = $tenant->business();
        $currency = $this->currency($tenant);
        $now = $this->now($request);

        $series = [];
        $cashIn = 0;
        $cashOut = 0;
        $balance = 0;

        if ($business !== null) {
            // The cash line only — asset accounts money can actually be spent
            // from. A debit to one of those is cash in, a credit is cash out.
            //
            // journal_lines carries its own business_id and entry_date, so this
            // needs no join back to journal_entries.
            $rows = DB::table('journal_lines')
                ->join('ledger_accounts', 'ledger_accounts.id', '=', 'journal_lines.ledger_account_id')
                ->where('journal_lines.business_id', $business->id)
                ->where('ledger_accounts.type', 'asset')
                ->where('ledger_accounts.is_spendable', true)
                ->whereBetween('journal_lines.entry_date', [
                    $now->subDays(29)->toDateString(),
                    $now->toDateString(),
                ])
                ->selectRaw('journal_lines.entry_date AS d, SUM(journal_lines.debit_minor) AS in_minor, SUM(journal_lines.credit_minor) AS out_minor')
                ->groupBy('journal_lines.entry_date')
                ->get()
                ->keyBy(fn ($row) => CarbonImmutable::parse($row->d)->toDateString());

            for ($i = 29; $i >= 0; $i--) {
                $day = $now->subDays($i);
                $row = $rows->get($day->toDateString());
                $in = (int) ($row->in_minor ?? 0);
                $out = (int) ($row->out_minor ?? 0);

                $cashIn += $in;
                $cashOut += $out;
                $balance += $in - $out;

                $series[] = ['label' => $day->format('j M'), 'value' => $balance];
            }
        } else {
            for ($i = 29; $i >= 0; $i--) {
                $series[] = ['label' => $now->subDays($i)->format('j M'), 'value' => 0];
            }
        }

        $net = $cashIn - $cashOut;

        return response()->json([
            'data' => [
                'period' => '30d',
                'currency' => $currency,
                'current_balance' => $balance,
                'current_balance_formatted' => (new Money($balance, $currency))->toDecimalString(),
                'cash_in_total' => $cashIn,
                'cash_out_total' => $cashOut,
                'net_formatted' => (new Money($net, $currency))->toDecimalString(),
                'series' => $series,
            ],
        ]);
    }

    public function topProducts(Request $request, TenantContext $tenant): JsonResponse
    {
        $business = $tenant->business();
        $currency = $this->currency($tenant);
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
                ->map(fn ($row) => [
                    'id' => (string) ($row->public_id ?? ''),
                    'name' => $row->name ?? 'Unknown product',
                    'sku' => $row->sku !== null ? (string) $row->sku : null,
                    'image_url' => null,
                    'quantity_sold' => (int) $row->qty,
                    'revenue' => (int) $row->revenue_minor,
                    'revenue_formatted' => (new Money((int) $row->revenue_minor, $currency))->toDecimalString(),
                ])
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

    public function recentOrders(Request $request, TenantContext $tenant): JsonResponse
    {
        $business = $tenant->business();
        $currency = $this->currency($tenant);
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
                    'total_formatted' => (new Money((int) $row->total_minor, $currency))->toDecimalString(),
                    'status' => (string) $row->status,
                    'status_label' => ucfirst(str_replace('_', ' ', (string) $row->status)),
                    'status_variant' => $this->statusVariant((string) $row->status),
                    'created_at' => (string) $row->created_at,
                    'created_at_human' => CarbonImmutable::parse($row->created_at)->diffForHumans(),
                ])
                ->all();
        }

        return response()->json(['data' => ['orders' => $orders]]);
    }

    public function topCustomers(Request $request, TenantContext $tenant): JsonResponse
    {
        $business = $tenant->business();
        $currency = $this->currency($tenant);
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
                ->map(fn ($row) => [
                    'id' => (string) $row->public_id,
                    'name' => (string) $row->name,
                    'email' => (string) ($row->email ?? ''),
                    'avatar_url' => null,
                    'total_orders' => (int) $row->order_count,
                    'lifetime_value' => (int) $row->lifetime_value_minor,
                    'lifetime_value_formatted' => (new Money((int) $row->lifetime_value_minor, $currency))->toDecimalString(),
                ])
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

    private function currency(TenantContext $tenant): string
    {
        return $tenant->business()?->base_currency
            ?? $tenant->account()?->base_currency
            ?? 'BDT';
    }

    private function limit(Request $request, int $default): int
    {
        return max(1, min(50, (int) $request->integer('limit', $default)));
    }

    /**
     * @return list<array{label: string, from: CarbonImmutable, to: CarbonImmutable}>
     */
    private function buckets(string $period, CarbonImmutable $now): array
    {
        $buckets = [];

        if ($period === '12m') {
            for ($i = 11; $i >= 0; $i--) {
                $month = $now->subMonths($i);
                $buckets[] = [
                    'label' => $month->format('M'),
                    'from' => $month->startOfMonth(),
                    'to' => $month->endOfMonth(),
                ];
            }

            return $buckets;
        }

        $days = $period === '7d' ? 7 : 30;

        for ($i = $days - 1; $i >= 0; $i--) {
            $day = $now->subDays($i);
            $buckets[] = [
                'label' => $day->format('j M'),
                'from' => $day->startOfDay(),
                'to' => $day->endOfDay(),
            ];
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
