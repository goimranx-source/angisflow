<?php

declare(strict_types=1);

namespace App\Http\Api\V1;

use App\Domain\Delivery\Adapters\TestCourierAdapter;
use App\Domain\Delivery\CourierAdapterResolver;
use App\Domain\Delivery\Models\CourierConnection;
use App\Domain\Delivery\Models\Shipment;
use App\Domain\Delivery\ShipmentTracker;
use App\Domain\Integrations\PushDispatcher;
use App\Domain\Integrations\Support\FieldMapSet;
use App\Domain\Integrations\Support\OrderStatuses;
use App\Domain\Integrations\Support\Transform;
use App\Domain\Money\Currencies;
use App\Domain\Money\CurrencyService;
use App\Domain\Sales\Models\Order;
use App\Domain\Storefront\StoreCode;
use App\Domain\Tenancy\TenantContext;
use App\Models\Storefront;
use App\Domain\Integrations\Models\IntegrationLink;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The order book — what this business has sold.
 *
 * ── Why the summary is not computed from the page ────────────────────────────
 *
 * The figures above the table answer for the whole filtered set, not for the
 * twenty-five rows being shown. Totalling the page instead is the mistake that
 * makes a revenue figure change when somebody clicks "next" — and the version
 * of it people notice last is the one where a filtered total is quietly a
 * twenty-fifth of the truth.
 *
 * So the summary is its own aggregate over the same filters, and it is computed
 * in SQL rather than by loading every matching order into memory: a business
 * with forty thousand orders would otherwise pay for all of them to show four
 * numbers.
 *
 * ── Money leaves here in one currency ────────────────────────────────────────
 *
 * An order carries the currency it was taken in. A business that sells in three
 * reads its book in one — its own — so every figure is converted on the way out
 * and the page never has to think about it. That conversion is the same service
 * the dashboard uses, so the two cannot disagree.
 */
class OrdersEndpoint
{
    /** Enough to fill a screen, few enough that a slow query is felt in testing. */
    private const PER_PAGE = 25;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly CurrencyService $currency,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $business = $this->tenant->business();

        abort_if($business === null, 409, 'No business is open, so there is no order book to show.');

        $base = $this->currency->base();

        // Tab filter: all, trashed, archived
        $tab = $request->query('tab', 'all');

        $query = $this->filtered($request, (int) $business->id, $tab);

        $perPage = min(100, max(5, (int) $request->integer('per_page', self::PER_PAGE)));

        $sort = $this->sort($request);

        $page = (clone $query)
            ->with([
                'customer:id,public_id,name,email',
                // logo_media_id and its media row: the invoice is issued by the
                // shop, so its mark travels with the order rather than being
                // fetched per document.
                'storefront:id,public_id,name,logo_media_id',
                'storefront.logo:id,path,thumb_path',
                'shipments.courierConnection.courier',

                // Eager, so the drawer can show what was bought without a second
                // request per order. One page is at most a few hundred lines;
                // the count below is still done in SQL because that is what the
                // table column needs and it is cheaper than counting in PHP.
                'lines:id,order_id,description,sku,quantity,unit_price_minor,total_minor',
            ])
            // Counted in SQL rather than by loading the lines: the table shows a
            // number, and loading four hundred line rows to count them is the
            // classic way a list page becomes slow only in production.
            ->withCount('lines')
            ->orderBy($sort[0], $sort[1]);

        // Add secondary sort if provided
        if (isset($sort[2]) && isset($sort[3])) {
            $page->orderBy($sort[2], $sort[3]);
        }

        $page = $page->paginate($perPage);

        // Collect unique statuses actually used by orders in current view
        // This shows only statuses that are mapped and in use
        $usedStatuses = (clone $query)
            ->select('status')
            ->distinct()
            ->pluck('status')
            ->filter()
            ->all();

        $allBusinessStatuses = OrderStatuses::for($business);

        // Build map of all business statuses for labels/tones
        $statusesMap = collect($allBusinessStatuses)
            ->map(fn (array $status, string $key): array => [
                'value' => $key,
                'label' => $status['label'],
                'tone' => $status['tone'],
                'custom' => $status['custom'] ?? false,
            ])
            ->all();

        // For the dropdown filter, show only statuses that are in use
        $availableStatuses = collect($usedStatuses)
            ->map(fn (string $key) => $statusesMap[$key] ?? null)
            ->filter()
            ->values()
            ->all();

        // If no statuses in use, show all available (for empty state)
        if (empty($availableStatuses)) {
            $availableStatuses = array_values($statusesMap);
        }

        // Resolved once for the whole business: the tags are unique across the
        // set, so they cannot be worked out one order at a time.
        $storeCodes = StoreCode::forBusiness((int) $business->id);

        /*
         * The business mark, read once for the page.
         *
         * Every order that belongs to a shop without its own logo shows this
         * one, so resolving it per row would be the same lookup twenty-five
         * times for a value that cannot change between them.
         */
        $businessLogo = $business->logoMedia?->url();
        $businessName = (string) $business->name;

        /*
         * Where the paperwork says it came from.
         *
         * Read from the business once for the page. Null entries are passed
         * through and dropped by the document rather than printed as empty
         * lines - an invoice with a blank space where the phone number goes
         * looks broken, one without a phone line simply has no phone.
         */
        $businessContact = [
            'address' => $business->address,
            'phone' => $business->phone,
            'email' => $business->email,
        ];

        /*
         * Which of these orders the shop has not taken.
         *
         * ── Why it is fetched for the page rather than per order ─────────────
         *
         * One query for the whole list instead of one per row. The answer is
         * almost always empty, and on the rare page where it is not, it is a
         * handful of rows — so this is a cheap indexed read that turns a silent
         * disagreement into something visible on the row it belongs to.
         */
        $unsent = IntegrationLink::query()
            ->where('entity', IntegrationLink::ORDER)
            ->whereIn('linkable_id', array_map(fn (Order $o): int => (int) $o->id, $page->items()))
            ->whereNotNull('push_pending_at')
            ->get(['linkable_id', 'push_pending_at', 'push_error'])
            ->keyBy('linkable_id');

        return response()->json([
            'data' => array_map(
                fn (Order $order): array => $this->present($order, $base, $storeCodes, $unsent->get($order->id), $businessLogo, $businessName, $businessContact),
                $page->items(),
            ),
            'summary' => $this->summary(clone $query, $base),

            /*
             * The shops this business actually sells through, so the filter
             * offers real options rather than a free-text box. Sent with the
             * list rather than fetched separately: it is three rows, and a
             * second request to populate one dropdown is a second round trip on
             * every page load.
             */
            'stores' => Storefront::query()
                ->where('business_id', $business->id)
                ->orderBy('name')
                ->get(['public_id', 'name'])
                ->map(fn ($s): array => ['id' => $s->public_id, 'name' => $s->name])
                ->all(),

            // Status vocabularies - filtered list for dropdown, full map for rendering
            'statuses' => $availableStatuses,
            // Full status map so any status in orders can be displayed with proper color
            'all_statuses' => array_values($statusesMap),

            'payment_statuses' => [
                ['value' => Order::UNPAID, 'label' => 'Unpaid'],
                ['value' => Order::PAID, 'label' => 'Paid'],
                ['value' => Order::REFUNDED, 'label' => 'Refunded'],
            ],
            'fulfilment_statuses' => [
                ['value' => Order::UNFULFILLED, 'label' => 'Unfulfilled'],
                ['value' => Order::PARTIAL, 'label' => 'Partial'],
                ['value' => Order::FULFILLED, 'label' => 'Fulfilled'],
            ],

            // Connected couriers for dispatch
            'couriers' => CourierConnection::query()
                ->where('business_id', $business->id)
                ->usable()
                ->with('courier:id,name,slug')
                ->orderBy('label')
                ->get()
                ->map(fn ($conn): array => [
                    'id' => $conn->public_id,
                    'label' => $conn->label ?? $conn->courier?->name,
                    'slug' => $conn->courier?->slug,
                ])
                ->all(),

            'meta' => [
                'total' => $page->total(),
                'per_page' => $page->perPage(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    /**
     * The filters the page offers, and nothing it does not.
     *
     * Every one of them is an exact match or a bounded range except the search,
     * which is the only place user text reaches the query — and it is bound as a
     * parameter rather than interpolated.
     */
    private function filtered(Request $request, int $businessId, string $tab = 'all'): Builder
    {
        $query = Order::query()->where('business_id', $businessId);

        // Apply tab filter
        switch ($tab) {
            case 'trashed':
                $query->onlyTrashed();
                break;
            case 'archived':
                $query->where(function (Builder $q): void {
                    $q->whereNotNull('archived_at')
                        ->orWhereIn('status', [Order::COMPLETED, Order::CANCELLED]);
                });
                break;
            case 'all':
            default:
                // The active book excludes explicit archives and terminal orders.
                $query->whereNull('archived_at')
                    ->whereNotIn('status', [Order::COMPLETED, Order::CANCELLED]);
                break;
        }

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function (Builder $q) use ($search): void {
                $like = '%'.$search.'%';

                $q->where('number', 'like', $like)
                    ->orWhere('shipping_name', 'like', $like)
                    ->orWhere('shipping_phone', 'like', $like)
                    ->orWhereHas('customer', fn (Builder $c) => $c
                        ->where('name', 'like', $like)
                        ->orWhere('email', 'like', $like));
            });
        }

        foreach (['status' => 'status', 'payment_status' => 'payment_status', 'channel' => 'channel'] as $param => $column) {
            if ($value = trim((string) $request->query($param, ''))) {
                $query->where($column, $value);
            }
        }

        /*
         * Which shop, or the counter.
         *
         * 'walk_in' is its own value rather than an empty one, because "no
         * store" is a real answer here and an empty parameter already means
         * "any". Conflating them would make the walk-in filter impossible to
         * express.
         */
        if ($store = trim((string) $request->query('store', ''))) {
            if ($store === 'walk_in') {
                $query->whereNull('storefront_id');
            } else {
                $query->whereHas('storefront', fn (Builder $s) => $s->where('public_id', $store));
            }
        }

        if ($from = trim((string) $request->query('date_from', ''))) {
            $query->whereDate('ordered_on', '>=', $from);
        }

        if ($to = trim((string) $request->query('date_to', ''))) {
            $query->whereDate('ordered_on', '<=', $to);
        }

        return $query;
    }

    /**
     * Sorting, from an allowlist.
     *
     * A column name arriving from a query string and going into an ORDER BY is
     * an injection waiting to happen, and "the frontend only sends four values"
     * is not a control — the frontend is not what sends the request.
     *
     * When sorting by date, adds created_at as secondary sort to ensure orders
     * on the same day appear in chronological order (newest first by default).
     *
     * @return array<int, string>
     */
    private function sort(Request $request): array
    {
        $columns = [
            'date' => 'ordered_on',
            'number' => 'number',
            'total' => 'total_minor',
            'status' => 'status',
            'created_at' => 'created_at',
        ];

        $by = $columns[(string) $request->query('sort_by', 'date')] ?? 'ordered_on';
        $direction = strtolower((string) $request->query('sort_direction', 'desc')) === 'asc' ? 'asc' : 'desc';

        // When sorting by date, add created_at as secondary sort for same-day ordering
        if ($by === 'ordered_on') {
            return [$by, $direction, 'created_at', $direction];
        }

        return [$by, $direction];
    }

    /**
     * The figures above the table, for the whole filtered set.
     *
     * ── Why this groups by currency ──────────────────────────────────────────
     *
     * Summing total_minor across currencies would add yen to euros and produce a
     * number that is not wrong so much as meaningless. Grouped first, converted
     * after, the total is a real figure in one currency.
     *
     * @return array<string, mixed>
     */
    private function summary(Builder $query, string $base): array
    {
        $rows = (clone $query)
            ->select('currency')
            ->selectRaw('COUNT(*) as orders')
            ->selectRaw('SUM(total_minor) as total')
            ->groupBy('currency')
            ->get();

        $orders = 0;
        $revenueMinor = 0;

        foreach ($rows as $row) {
            $orders += (int) $row->orders;

            $converted = $this->currency->convertMinor((int) $row->total, (string) $row->currency, $base);

            // A currency with no rate is counted but not totalled. Treating a
            // missing rate as zero would understate revenue silently; leaving
            // the order out of the count would make the two figures disagree.
            $revenueMinor += $converted ?? 0;
        }

        $awaiting = (clone $query)->where('payment_status', 'unpaid')->count();

        $scale = 10 ** Currencies::scale($base);

        return [
            'total_orders' => $orders,
            'total_revenue' => round($revenueMinor / $scale, 2),
            'avg_order_value' => $orders > 0 ? round($revenueMinor / $orders / $scale, 2) : 0.0,
            'pending_count' => $awaiting,
            'currency' => $base,
        ];
    }

    /**
     * One order, in the shape the page reads.
     *
     * @return array<string, mixed>
     */
    /**
     * Minor units as a plain number in that currency's own scale.
     *
     * Not formatted — the browser decides how to render a figure for whoever is
     * reading it. Scaled here because the scale belongs to the currency, and
     * dividing by a hundred in JavaScript is wrong for the currencies that are
     * not decimal in that way.
     */
    private static function plain(?int $minor, string $currency): float
    {
        return round(((int) $minor) / (10 ** Currencies::scale($currency)), 2);
    }

    /**
     * @param  array<int, string>  $storeCodes  storefront id => short tag
     * @param  IntegrationLink|null  $unsent  set when the shop has not taken this order's changes
     */
    private function present(Order $order, string $base, array $storeCodes = [], ?IntegrationLink $unsent = null, ?string $businessLogo = null, string $businessName = '', array $businessContact = []): array
    {
        $from = (string) $order->currency;

        $money = function (?int $minor) use ($from, $base): float {
            $converted = $this->currency->convertMinor((int) $minor, $from, $base);

            return round(($converted ?? 0) / (10 ** Currencies::scale($base)), 2);
        };

        return [
            'id' => $order->public_id,
            'order_number' => $order->number,

            // Null rather than a placeholder name: a walk-in sale genuinely has
            // no customer, and inventing "Guest" here would make it impossible
            // to tell one from a customer actually called that.
            'customer' => $order->customer === null ? null : [
                'id' => $order->customer->public_id,
                'name' => $order->customer->name,
                'email' => $order->customer->email,
            ],

            /*
             * Which shop it came through, or null for a walk-in.
             *
             * A business selling through two websites and a counter needs to
             * see which is which on the row itself — "where did this come from"
             * is the first question asked of any order, and a channel of
             * 'online' does not answer it once there is more than one shop.
             */
            'store' => $order->storefront === null ? null : [
                'id' => $order->storefront->public_id,
                'name' => $order->storefront->name,
                /*
                 * This shop's mark, or the business's when it has none.
                 *
                 * ── Why it falls back rather than showing nothing ───────────
                 *
                 * Most businesses here run one shop and think of the brand as
                 * theirs, not the storefront's - so requiring a logo per shop
                 * would leave the common case with blank paperwork for no
                 * reason. A shop that sets its own overrides it, which is what
                 * makes the per-shop field worth having at all.
                 *
                 * If neither exists the invoice prints the name, because a gap
                 * where a mark should be reads as a fault and a name does not.
                 */
                'logo_url' => $order->storefront->logo?->url() ?? $businessLogo,
            ],
            'is_walk_in' => $order->storefront_id === null,

            /*
             * Who the paperwork is from.
             *
             * ── Why this is not read off 'store' ────────────────────────────
             *
             * Because a counter sale has no storefront at all, and an invoice
             * for one still has to say who issued it. Resolved here so a
             * document never has to work out the fallback itself - shop first,
             * because an order belongs to the shop it was placed in, then the
             * business, which is what most people mean by their brand.
             */
            'brand' => [
                'name' => $order->storefront?->name ?? $businessName,
                // "A brand of ..." - only when the shop is not the business
                // itself, or it would print the same name twice.
                'tagline' => $order->storefront !== null && $order->storefront->name !== $businessName
                    ? $businessName
                    : null,
                'logo_url' => $order->storefront?->logo?->url() ?? $businessLogo,
                'address' => $businessContact['address'] ?? null,
                'phone' => $businessContact['phone'] ?? null,
                'email' => $businessContact['email'] ?? null,
            ],

            /*
             * A short tag for the shop, to sit in front of the number.
             *
             * Order numbers come from each platform's own sequence, so a
             * business selling in three places can hold three different
             * orders numbered 1043. On a list that mixes them the number
             * alone identifies nothing, and 'WALK' rather than null for a
             * counter sale keeps the column one shape instead of two.
             */
            'store_code' => $order->storefront_id === null
                ? 'WALK'
                : ($storeCodes[(int) $order->storefront_id] ?? null),

            /*
             * Whether this order's changes have reached the shop.
             *
             * Null when there is nothing outstanding, which is the ordinary
             * case — so a screen shows nothing at all rather than a reassuring
             * green tick on every row, which would be noise the moment it
             * mattered.
             *
             * `error` separates "still on its way" from "this needs somebody":
             * a pending stamp alone is work in flight, a pending stamp with a
             * reason is work that stopped.
             */
            'unsent' => $unsent === null ? null : [
                'since' => $unsent->push_pending_at?->toIso8601String(),
                'error' => $unsent->push_error,
            ],

            'date' => $order->ordered_on?->toDateString(),
            'status' => $order->status,
            'payment_status' => $order->payment_status,
            'fulfilment_status' => $order->fulfilment_status,
            'channel' => $order->channel,
            'is_cod' => (bool) $order->is_cod,
            'items_count' => (int) ($order->lines_count ?? 0),

            'subtotal' => $money($order->subtotal_minor),
            'tax' => $money($order->tax_minor),
            'shipping' => $money($order->shipping_minor),
            'discount' => $money($order->discount_minor),
            'total' => $money($order->total_minor),
            'paid' => $money($order->paid_minor),

            /*
             * The same breakdown in the currency the sale was actually taken in.
             *
             * ── Why both are returned ────────────────────────────────────────
             *
             * Because a breakdown and its total have to be in one currency or
             * they do not add up. The figures above are converted for the books,
             * which is right for totalling a column across shops and wrong for
             * showing somebody one order: subtotal, tax and shipping in one
             * currency under a total in another is not a rounding difference,
             * it is arithmetic that visibly fails.
             *
             * Unscaled here rather than in the browser so there is one place
             * that knows a currency's scale — yen has no minor unit and dinars
             * have three.
             */
            'native' => [
                'currency' => $from,
                'symbol' => Currencies::symbol($from),
                /*
                 * Derived from the figures the shop is authoritative about,
                 * rather than read from our own column.
                 *
                 * The shop states the total, the tax, the shipping and the
                 * discount; the subtotal is what those imply, and it is the only
                 * value that makes the block add up to the amount the customer
                 * was actually charged. Our stored subtotal is computed from the
                 * lines we hold, so any disagreement between the two shows up
                 * here as arithmetic that visibly fails — which is exactly what
                 * a summary must never do.
                 */
                'subtotal' => self::plain(
                    (int) $order->total_minor + (int) $order->discount_minor
                        - (int) $order->shipping_minor - (int) $order->tax_minor,
                    $from,
                ),
                'discount' => self::plain($order->discount_minor, $from),
                'tax' => self::plain($order->tax_minor, $from),
                'shipping' => self::plain($order->shipping_minor, $from),
                'total' => self::plain($order->total_minor, $from),
                'paid' => self::plain($order->paid_minor, $from),
            ],

            /*
             * What was actually bought.
             *
             * Loaded with the order rather than fetched per row when a drawer
             * opens, because the list already has the order in memory and a
             * second round trip to show four lines is a spinner nobody needs.
             */
            'items' => $order->relationLoaded('lines')
                ? $order->lines->map(fn ($line): array => [
                    'description' => (string) $line->description,
                    'sku' => $line->sku,
                    'quantity' => (float) $line->quantity,
                    'unit_price' => self::plain($line->unit_price_minor, $from),
                    'total' => self::plain($line->total_minor, $from),
                ])->all()
                : [],

            /*
             * The figure as the shop actually charged it, alongside the
             * converted one.
             *
             * A business selling through a dirham shop and a taka shop reads its
             * book in one currency, but the dirham order was a dirham order —
             * and when somebody checks a row against the shop's own admin, or
             * against what the customer paid, the converted figure will not
             * match and there is nothing on screen to explain why. So both
             * travel: the converted one for totals, the original for the row.
             */
            'total_native' => round((int) $order->total_minor / (10 ** Currencies::scale($from)), 2),
            'source_currency' => $from,
            'source_symbol' => Currencies::symbol($from),
            'is_converted' => $from !== $base,
            'currency' => $base,

            'dispatch' => ($shipment = $order->shipments->first()) === null ? null : [
                'shipment_number' => $shipment->number,
                'courier' => [
                    'id' => $shipment->courierConnection?->public_id,
                    'label' => $shipment->courierConnection?->label
                        ?? $shipment->courierConnection?->courier?->name,
                ],
                'amount' => round($shipment->cod_amount_minor / (10 ** Currencies::scale($from)), 2),
                'currency' => $from,
                'tracking_number' => $shipment->tracking_number,
                'status' => $shipment->status,
            ],

            'shipping_address' => $order->shipping_address === null ? null : [
                'line1' => $order->shipping_address,
                'city' => $order->shipping_city,
                'postal_code' => $order->shipping_postcode,
                'country' => $order->shipping_country,
            ],

            'notes' => $order->notes,
            'created_at' => $order->created_at?->toIso8601String(),
        ];
    }

    /**
     * Update multiple orders at once.
     *
     * Supports bulk status changes, which is the most common bulk operation.
     * Each order is updated independently so one failure doesn't stop the rest.
     */
    public function bulkUpdate(Request $request, PushDispatcher $pushes): JsonResponse
    {
        $business = $this->tenant->business();
        if ($business === null) {
            return response()->json(['message' => 'No business context.'], 422);
        }

        // Get valid status keys for this business
        $validStatuses = array_keys(OrderStatuses::for($business));

        $validated = $request->validate([
            'order_ids' => ['required', 'array', 'min:1', 'max:100'],
            'order_ids.*' => ['required', 'string'],
            'action' => ['required', 'string', 'in:update_status,mark_paid,cancel,trash,restore,unarchive,delete_permanently'],
            'status' => ['nullable', 'string', 'in:'.implode(',', $validStatuses)],
            'payment_status' => ['nullable', 'string', 'in:'.implode(',', [
                Order::UNPAID,
                Order::PAID,
                Order::REFUNDED,
            ])],
            'fulfilment_status' => ['nullable', 'string', 'in:'.implode(',', [
                Order::UNFULFILLED,
                Order::PARTIAL,
                Order::FULFILLED,
            ])],
        ]);

        $orderIds = $validated['order_ids'];
        $action = $validated['action'];

        // Fetch orders (include trashed for restore/delete actions)
        $query = Order::query()->where('business_id', $business->id);

        if (in_array($action, ['restore', 'delete_permanently'])) {
            $query->onlyTrashed();
        }

        $orders = $query->whereIn('public_id', $orderIds)->get();

        if ($orders->isEmpty()) {
            return response()->json(['message' => 'No orders found.'], 404);
        }

        $updated = 0;
        $failed = 0;
        $errors = [];

        /** @var list<\App\Domain\Integrations\Jobs\PushIntegrationRecord> $queued */
        $queued = [];

        foreach ($orders as $order) {
            try {
                $changes = [];

                switch ($action) {
                    case 'update_status':
                        if (isset($validated['status'])) {
                            $changes['status'] = $validated['status'];

                            // Auto-set timestamps based on status
                            if ($validated['status'] === Order::CONFIRMED && $order->confirmed_at === null) {
                                $changes['confirmed_at'] = now();
                            } elseif ($validated['status'] === Order::FULFILLED && $order->fulfilled_at === null) {
                                $changes['fulfilled_at'] = now();
                            } elseif ($validated['status'] === Order::CANCELLED && $order->cancelled_at === null) {
                                $changes['cancelled_at'] = now();
                            }

                            /*
                             * Archiving follows the status, in both directions.
                             *
                             * Completing or cancelling files an order away, which
                             * is right: it is finished and does not belong in a
                             * working list. But the rule only ever ran one way,
                             * and that made the commonest mistake unrecoverable —
                             * mark an order completed by accident and it vanished
                             * into the archive, where the status could not be
                             * changed back.
                             *
                             * So a status that is not final brings it back. An
                             * order being worked on again is an active order, and
                             * leaving it filed away would mean a live order nobody
                             * can see on the screen they work from.
                             */
                            $isFinal = in_array($validated['status'], [Order::COMPLETED, Order::CANCELLED], true);

                            if ($isFinal && $order->archived_at === null) {
                                $changes['archived_at'] = now();
                            }

                            if (! $isFinal && $order->archived_at !== null) {
                                $changes['archived_at'] = null;
                            }
                        }
                        if (isset($validated['payment_status'])) {
                            $changes['payment_status'] = $validated['payment_status'];
                        }
                        if (isset($validated['fulfilment_status'])) {
                            $changes['fulfilment_status'] = $validated['fulfilment_status'];
                        }
                        break;

                    case 'mark_paid':
                        $changes['payment_status'] = Order::PAID;
                        $changes['paid_minor'] = $order->total_minor;
                        break;

                    case 'cancel':
                        $changes['status'] = Order::CANCELLED;
                        $changes['archived_at'] = now(); // Auto-archive cancelled orders
                        if ($order->cancelled_at === null) {
                            $changes['cancelled_at'] = now();
                        }
                        break;

                    case 'trash':
                        $order->delete(); // Soft delete
                        $updated++;

                        continue 2; // Skip the update logic below

                    case 'restore':
                        $order->restore();
                        // Unarchive when restoring
                        $order->update(['archived_at' => null]);
                        $updated++;

                        continue 2;

                    case 'unarchive':
                        $changes['archived_at'] = null;
                        break;

                    case 'delete_permanently':
                        $order->forceDelete();
                        $updated++;

                        continue 2;
                }

                if (! empty($changes)) {
                    /*
                     * The change and the record that it is owed to the shop are
                     * written together, or neither is.
                     *
                     * ── Why this order needs a transaction of its own ────────
                     *
                     * Because between updating the order and noting that the
                     * shop has not been told, a failure leaves exactly the state
                     * this application has twice shipped: an order that has
                     * moved here, with nothing anywhere saying the shop still
                     * disagrees. One transaction per order rather than one for
                     * the batch, so a single bad order fails alone instead of
                     * abandoning the two hundred good ones beside it.
                     *
                     * Collected rather than sent: they travel as one batch
                     * below, which is what lets the work be counted.
                     */
                    $jobs = DB::transaction(function () use ($order, $changes, $pushes): array {
                        $order->update($changes);

                        return $pushes->jobsFor($order->fresh());
                    });

                    foreach ($jobs as $job) {
                        $queued[] = $job;
                    }

                    $updated++;
                }
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = "Order {$order->number}: ".$e->getMessage();
            }
        }

        $message = "{$updated} order".($updated === 1 ? '' : 's').' updated';
        if ($failed > 0) {
            $message .= ", {$failed} failed";
        }

        /*
         * The local change is done; reaching the shops is not, and nobody
         * should be made to wait for it.
         *
         * ── Why a batch and not a queue of loose jobs ────────────────────────
         *
         * Both get the work off the request. Only a batch can answer "how far
         * along is it" — and without that answer the only honest thing a screen
         * can do is either lie that the work is finished or hold somebody there
         * watching a spinner. A batch id turns a wait into a progress line
         * somebody can ignore while they carry on.
         *
         * With no worker this falls back to the old behaviour, which is slow
         * but real, rather than queueing into a void. See PushDispatcher.
         */
        $batchId = null;

        if ($queued !== []) {
            if ($pushes->hasWorker()) {
                $batchId = Bus::batch($queued)
                    ->name("Sending {$updated} order".($updated === 1 ? '' : 's').' to connected shops')
                    // One shop refusing one order must not abandon the rest.
                    ->allowFailures()
                    ->dispatch()
                    ->id;

                /*
                 * Remembered against the business, not left to the browser.
                 *
                 * ── Why the server has to hold this ──────────────────────────
                 *
                 * Because the tab that started the work is not the only place
                 * it matters, and is the least reliable of them. A reload, a
                 * move to another screen, or a second tab all lose a batch id
                 * kept in component state — and the work carries on regardless,
                 * so the person is left with no way of knowing whether their
                 * two hundred orders ever reached the shop.
                 *
                 * Held here, any page can ask what is still running, and the
                 * answer is the same on every device the account is open on.
                 *
                 * Capped and short-lived because it is a notice, not a record:
                 * the batch table is the record.
                 */
                self::rememberBatch((int) $business->id, $batchId);
            } else {
                foreach ($queued as $job) {
                    dispatch($job)->afterResponse();
                }
            }
        }

        return response()->json([
            'message' => $message,
            'data' => [
                'updated' => $updated,
                'failed' => $failed,
                'errors' => $errors,

                // What the screen needs to follow the shop-side work.
                'batch_id' => $batchId,
                'pushes' => count($queued),
            ],
        ], $failed > 0 && $updated === 0 ? 422 : 200);
    }

    /**
     * Everything the edit screen needs to draw itself, for one order.
     *
     * ── Why the form is described here and not hard-coded in the page ────────
     *
     * Because what an order carries is not the same from one business to the
     * next. The built-in fields are fixed and can be laid out by hand — every
     * shop has a status, an address, a total. Custom fields are not: a business
     * defines its own, gives each one a type, and a screen that does not know
     * about them either ignores what its owner told it to keep or has to be
     * rewritten every time somebody adds one.
     *
     * So the page gets the definitions and renders by type. A delivery slot is
     * a text box, a gift-wrap flag is a switch, a proof-of-delivery photo goes
     * in the media column — decided from the type, not from a list of names
     * somebody has to maintain.
     *
     * `mapped` says which built-in fields this shop actually sends. Nothing is
     * hidden on the strength of it — an order can be edited here whether or not
     * a shop fills the field — but it lets the screen lead with what this
     * business really uses instead of showing every field at equal weight.
     */
    public function editor(string $order): JsonResponse
    {
        $business = $this->tenant->business();

        abort_if($business === null, 409, 'No business is open.');

        $model = Order::query()
            ->with(['customer', 'storefront'])
            ->where('business_id', $business->id)
            ->where('public_id', $order)
            ->firstOrFail();

        $scale = 10 ** Currencies::scale((string) $model->currency);

        $money = fn (?int $minor): float => round(((int) $minor) / $scale, 2);

        /*
         * The link carries the custom values, not the order.
         *
         * Custom fields belong to the shop's mapping rather than to our schema,
         * so they live alongside the link that ties this order to that shop.
         * An order placed at the counter has no link and therefore no customs,
         * which is correct rather than a gap.
         */
        $link = IntegrationLink::query()
            ->where('entity', IntegrationLink::ORDER)
            ->where('linkable_id', $model->id)
            ->first();

        $integration = $link?->integration;

        $mapped = [];

        if ($integration !== null) {
            foreach (FieldMapSet::for($integration, 'order')->toArray() as $map) {
                $mapped[] = $map['target'] ?? null;
            }
        }

        /*
         * A business's own fields, each with the type it was given.
         *
         * The type is a transform name — the same vocabulary the mapping screen
         * uses — so a field defined once is understood the same way by the
         * sync, the push and this form.
         */
        $customFields = collect($business->custom_fields['order'] ?? [])
            ->map(fn (array $field): array => [
                'key' => (string) ($field['key'] ?? ''),
                'label' => (string) ($field['label'] ?? $field['key'] ?? ''),
                'type' => (string) ($field['type'] ?? 'trim'),
                // The same human name the mapping screen shows, so a field
                // means one thing in both places.
                'type_label' => Transform::options()[(string) ($field['type'] ?? 'trim')] ?? 'Text',
            ])
            ->filter(fn (array $field): bool => $field['key'] !== '')
            ->values()
            ->all();

        return response()->json([
            'data' => [
                'id' => $model->public_id,
                'number' => $model->number,

                'values' => [
                    'status' => $model->status,
                    'payment_status' => $model->payment_status,
                    'fulfilment_status' => $model->fulfilment_status,
                    'channel' => $model->channel,
                    'is_cod' => (bool) $model->is_cod,
                    'ordered_on' => $model->ordered_on?->toDateString(),
                    'external_ref' => $model->external_ref,
                    'notes' => $model->notes,
                    'currency' => $model->currency,

                    'shipping_name' => $model->shipping_name,
                    'shipping_phone' => $model->shipping_phone,
                    'shipping_address' => $model->shipping_address,
                    'shipping_city' => $model->shipping_city,
                    'shipping_postcode' => $model->shipping_postcode,
                    'shipping_country' => $model->shipping_country,

                    // Sent as decimals, in the order's own currency, because
                    // that is what somebody types. Scaled back on the way in.
                    'subtotal' => $money($model->subtotal_minor),
                    'discount' => $money($model->discount_minor),
                    'shipping' => $money($model->shipping_minor),
                    'tax' => $money($model->tax_minor),
                    'total' => $money($model->total_minor),
                    'paid' => $money($model->paid_minor),

                    /*
                     * The buyer, in full.
                     *
                     * A shop repeats these on every order it sends and the
                     * mapping screen offers all of them, so a form showing
                     * three of the twelve cannot edit what the shop is allowed
                     * to change.
                     */
                    'customer_name' => $model->customer?->name,
                    'customer_email' => $model->customer?->email,
                    'customer_phone' => $model->customer?->phone,
                    'customer_company' => $model->customer?->company,
                    'customer_tax_number' => $model->customer?->tax_number,
                    'customer_billing_address' => $model->customer?->billing_address,
                    'customer_billing_city' => $model->customer?->billing_city,
                    'customer_billing_postcode' => $model->customer?->billing_postcode,
                    'customer_billing_country' => $model->customer?->billing_country,
                    'customer_notes' => $model->customer?->notes,

                    // Shown but not editable: these identify the order, and a
                    // form that hides them makes somebody look elsewhere to be
                    // sure they are changing the right one.
                    'number' => $model->number,
                ],

                'custom' => (object) ($link?->custom_fields ?? []),
                'custom_fields' => $customFields,

                // What this shop actually sends, so the form can lead with it.
                'mapped' => array_values(array_filter(array_unique($mapped))),

                'shop' => $model->storefront?->name,
                'symbol' => Currencies::symbol((string) $model->currency),

                'statuses' => array_values(collect(OrderStatuses::for($business))
                    ->map(fn (array $s, string $k): array => [
                        'value' => $k,
                        'label' => $s['label'],
                        'custom' => $s['custom'] ?? false,
                    ])
                    ->all()),
            ],
        ]);
    }

    /**
     * Save one order's fields.
     *
     * ── Why this is not the bulk endpoint with a list of one ─────────────────
     *
     * Bulk exists to apply a single decision to many orders — a status, a
     * courier — and its whole shape is that of one instruction repeated.
     * Editing is the opposite: many fields, one order, most of them unchanged.
     * Squeezing it through the other would mean either sending every field as
     * its own bulk call or inventing an action per field.
     *
     * Only what is present in the request is written, so a form that sends the
     * three fields somebody touched does not blank the twenty they did not.
     */
    public function updateOrder(Request $request, string $order, PushDispatcher $pushes): JsonResponse
    {
        $business = $this->tenant->business();

        abort_if($business === null, 409, 'No business is open.');

        $model = Order::query()
            ->with('customer')
            ->where('business_id', $business->id)
            ->where('public_id', $order)
            ->firstOrFail();

        $validated = $request->validate([
            'status' => ['sometimes', 'string', 'max:40'],
            'payment_status' => ['sometimes', 'string', 'in:paid,unpaid'],
            'fulfilment_status' => ['sometimes', 'string', 'in:fulfilled,unfulfilled'],
            'channel' => ['sometimes', 'string', 'max:20'],
            'is_cod' => ['sometimes', 'boolean'],
            'ordered_on' => ['sometimes', 'nullable', 'date'],
            'external_ref' => ['sometimes', 'nullable', 'string', 'max:120'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],

            'shipping_name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'shipping_phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'shipping_address' => ['sometimes', 'nullable', 'string', 'max:400'],
            'shipping_city' => ['sometimes', 'nullable', 'string', 'max:120'],
            'shipping_postcode' => ['sometimes', 'nullable', 'string', 'max:40'],
            'shipping_country' => ['sometimes', 'nullable', 'string', 'max:80'],

            'subtotal' => ['sometimes', 'numeric', 'min:0'],
            'discount' => ['sometimes', 'numeric', 'min:0'],
            'shipping' => ['sometimes', 'numeric', 'min:0'],
            'tax' => ['sometimes', 'numeric', 'min:0'],
            'total' => ['sometimes', 'numeric', 'min:0'],
            'paid' => ['sometimes', 'numeric', 'min:0'],

            'customer_name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'customer_email' => ['sometimes', 'nullable', 'email', 'max:190'],
            'customer_phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'customer_company' => ['sometimes', 'nullable', 'string', 'max:160'],
            'customer_tax_number' => ['sometimes', 'nullable', 'string', 'max:60'],
            'customer_billing_address' => ['sometimes', 'nullable', 'string', 'max:400'],
            'customer_billing_city' => ['sometimes', 'nullable', 'string', 'max:120'],
            'customer_billing_postcode' => ['sometimes', 'nullable', 'string', 'max:40'],
            'customer_billing_country' => ['sometimes', 'nullable', 'string', 'max:80'],
            'customer_notes' => ['sometimes', 'nullable', 'string', 'max:5000'],

            'custom' => ['sometimes', 'array'],
        ]);

        $scale = 10 ** Currencies::scale((string) $model->currency);

        $changes = [];

        foreach (['status', 'payment_status', 'fulfilment_status', 'channel', 'is_cod',
            'external_ref', 'notes', 'shipping_name', 'shipping_phone', 'shipping_address',
            'shipping_city', 'shipping_postcode', 'shipping_country'] as $field) {
            if (array_key_exists($field, $validated)) {
                $changes[$field] = $validated[$field];
            }
        }

        if (array_key_exists('ordered_on', $validated)) {
            $changes['ordered_on'] = $validated['ordered_on'];
        }

        // Typed back into minor units here, so the rest of the application
        // never sees a float where it expects an integer number of paisa.
        foreach (['subtotal', 'discount', 'shipping', 'tax', 'total', 'paid'] as $field) {
            if (array_key_exists($field, $validated)) {
                $changes[$field.'_minor'] = (int) round(((float) $validated[$field]) * $scale);
            }
        }

        /*
         * Archiving follows the status, in both directions.
         *
         * The same rule the bulk endpoint applies: a terminal status files the
         * order away, and moving it back off one brings it out. Without this an
         * order completed from the edit screen would stay in the active list,
         * and an order rescued from Completed would stay filed.
         */
        if (array_key_exists('status', $validated)) {
            $isFinal = in_array($validated['status'], [Order::COMPLETED, Order::CANCELLED], true);

            if ($isFinal && $model->archived_at === null) {
                $changes['archived_at'] = now();
            }

            if (! $isFinal && $model->archived_at !== null) {
                $changes['archived_at'] = null;
            }
        }

        $jobs = DB::transaction(function () use ($model, $changes, $validated, $pushes): array {
            if ($changes !== []) {
                $model->update($changes);
            }

            // The customer is its own record; only the three fields this form
            // offers are touched, and only when it has one to touch.
            if ($model->customer !== null) {
                $person = [];

                /*
                 * Keyed by what the form sends, written under the column name.
                 *
                 * Only keys the request actually carried, so a screen showing
                 * six of these cannot blank the other four.
                 */
                foreach ([
                    'customer_name' => 'name',
                    'customer_email' => 'email',
                    'customer_phone' => 'phone',
                    'customer_company' => 'company',
                    'customer_tax_number' => 'tax_number',
                    'customer_billing_address' => 'billing_address',
                    'customer_billing_city' => 'billing_city',
                    'customer_billing_postcode' => 'billing_postcode',
                    'customer_billing_country' => 'billing_country',
                    'customer_notes' => 'notes',
                ] as $sent => $column) {
                    if (array_key_exists($sent, $validated)) {
                        $person[$column] = $validated[$sent];
                    }
                }

                if ($person !== []) {
                    $model->customer->update($person);
                }
            }

            if (array_key_exists('custom', $validated)) {
                $link = IntegrationLink::query()
                    ->where('entity', IntegrationLink::ORDER)
                    ->where('linkable_id', $model->id)
                    ->first();

                // Merged, not replaced: a form sending the two fields it shows
                // must not erase a third the shop set and this screen never
                // displayed.
                $link?->mergeCustom($validated['custom']);
                $link?->save();
            }

            return $pushes->jobsFor($model->fresh());
        });

        foreach ($jobs as $job) {
            dispatch($job);
        }

        return response()->json([
            'message' => "Order {$model->number} saved.",
            'data' => ['pushes' => count($jobs)],
        ]);
    }

    /**
     * Send one order to its shop again, now.
     *
     * ── Why this is asked for rather than swept up ───────────────────────────
     *
     * The timed sweep deliberately leaves alone anything the shop refused: a
     * rejected line, an order it will not reopen. Asking again on a timer is
     * only being refused on a timer, and it buries a real problem under noise.
     *
     * But a refusal is often something a person can fix — correct the order,
     * reconnect the shop — and having fixed it they need a way to say "try that
     * again" without inventing a change to the order just to provoke a push.
     * This is that.
     */
    public function retryPush(Request $request, string $order, PushDispatcher $pushes): JsonResponse
    {
        $business = $this->tenant->business();

        abort_if($business === null, 409, 'No business is open.');

        $model = Order::query()
            ->where('business_id', $business->id)
            ->where('public_id', $order)
            ->firstOrFail();

        $jobs = $pushes->jobsFor($model);

        if ($jobs === []) {
            return response()->json([
                'message' => 'This order does not belong to a connected shop.',
            ], 422);
        }

        foreach ($jobs as $job) {
            dispatch($job);
        }

        return response()->json([
            'message' => 'Sending this order to the shop again.',
            'data' => ['pushes' => count($jobs)],
        ], 202);
    }

    /** Cache key holding the recent push batches for one business. */
    private static function batchKey(int $businessId): string
    {
        return "orders.push-batches.{$businessId}";
    }

    private static function rememberBatch(int $businessId, string $batchId): void
    {
        $key = self::batchKey($businessId);

        $ids = Cache::get($key, []);
        $ids[] = $batchId;

        // The last handful only: anything older has either finished or is no
        // longer something a screen should be reporting.
        Cache::put($key, array_slice(array_unique($ids), -10), now()->addHours(6));
    }

    /**
     * What bulk work is still reaching the shops, for whoever is looking.
     *
     * ── Why this is asked without a batch id ─────────────────────────────────
     *
     * So that any page, in any tab, after any reload, can find out. Progress
     * tied to an id the browser has to keep is progress that disappears the
     * moment somebody refreshes — which is precisely when they most want to
     * know the work survived. The server knows what is running; the screen
     * only has to ask.
     *
     * Finished batches are dropped from the list as they are found, so this
     * settles back to a single cheap read once the work is done.
     */
    public function activePushes(): JsonResponse
    {
        $business = $this->tenant->business();

        abort_if($business === null, 409, 'No business is open.');

        $key = self::batchKey((int) $business->id);
        $ids = Cache::get($key, []);

        $running = [];
        $keep = [];

        foreach ($ids as $id) {
            $batch = Bus::findBatch($id);

            // Missing means pruned, which means finished.
            if ($batch === null || $batch->finished()) {
                continue;
            }

            $keep[] = $id;
            $running[] = $batch;
        }

        if ($keep !== $ids) {
            Cache::put($key, $keep, now()->addHours(6));
        }

        if ($running === []) {
            return response()->json(['data' => ['active' => false]]);
        }

        $total = array_sum(array_map(fn ($b): int => $b->totalJobs, $running));
        $pending = array_sum(array_map(fn ($b): int => $b->pendingJobs, $running));
        $failed = array_sum(array_map(fn ($b): int => $b->failedJobs, $running));

        return response()->json([
            'data' => [
                'active' => true,
                'batches' => count($running),
                'total' => $total,
                'done' => $total - $pending,
                'failed' => $failed,
                'progress' => $total > 0 ? (int) round((($total - $pending) / $total) * 100) : 0,
            ],
        ]);
    }

    /**
     * How far along a bulk change's shop-side work is.
     *
     * ── Why this is polled rather than pushed ────────────────────────────────
     *
     * Because the alternative is a websocket for a progress line, and this
     * answer is cheap: it is one read of the batch row, and the screen stops
     * asking the moment it finishes. A connection held open for the length of
     * every bulk edit costs more than the question does.
     *
     * A batch that cannot be found is reported as finished rather than as an
     * error. Laravel prunes completed batches, so "gone" and "done" are the
     * same thing from here, and a screen that treated it as a failure would
     * show one for every bulk change somebody left open long enough.
     */
    public function bulkProgress(string $batch): JsonResponse
    {
        $found = Bus::findBatch($batch);

        if ($found === null) {
            return response()->json([
                'data' => ['finished' => true, 'total' => 0, 'done' => 0, 'failed' => 0, 'progress' => 100],
            ]);
        }

        return response()->json([
            'data' => [
                'finished' => $found->finished(),
                'cancelled' => $found->cancelled(),
                'total' => $found->totalJobs,
                // pendingJobs counts down, so what is done is the difference.
                'done' => $found->totalJobs - $found->pendingJobs,
                'failed' => $found->failedJobs,
                'progress' => $found->progress(),
            ],
        ]);
    }

    /**
     * Dispatch a single order to a courier.
     *
     * Creates a shipment record linking the order to the courier connection.
     * If amount is not provided, uses the order total.
     */
    public function dispatch(Request $request, string $orderId): JsonResponse
    {
        $validated = $request->validate([
            'courier_id' => ['required', 'string'],
            'amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        $business = $this->tenant->business();
        if ($business === null) {
            return response()->json(['message' => 'No business context.'], 422);
        }

        // Find the order
        $order = Order::query()
            ->where('business_id', $business->id)
            ->where('public_id', $orderId)
            ->first();

        if ($order === null) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        if ($order->archived_at !== null || in_array($order->status, [Order::COMPLETED, Order::CANCELLED], true)) {
            return response()->json(['message' => 'Archived, completed, and cancelled orders cannot be dispatched.'], 422);
        }

        // Find the courier connection
        $courier = CourierConnection::query()
            ->where('business_id', $business->id)
            ->where('public_id', $validated['courier_id'])
            ->usable()
            ->first();

        if ($courier === null) {
            return response()->json(['message' => 'Courier connection not found or not usable.'], 404);
        }

        // Determine COD amount (use provided amount or order total)
        $scale = 10 ** Currencies::scale($order->currency);
        $codAmountMinor = isset($validated['amount'])
            ? (int) round((float) $validated['amount'] * $scale)
            : (int) $order->total_minor;

        // Create shipment
        $shipment = Shipment::create([
            'account_id' => $business->account_id,
            'business_id' => $business->id,
            'order_id' => $order->id,
            'courier_connection_id' => $courier->id,
            'status' => 'draft',
            'is_cod' => (bool) $order->is_cod,
            'currency' => $order->currency,
            'cod_amount_minor' => $codAmountMinor,
            'recipient_name' => $order->shipping_name ?? $order->customer?->name,
            'recipient_phone' => $order->shipping_phone ?? $order->customer?->phone,
            'address' => $order->shipping_address,
            'city' => $order->shipping_city,
            'postcode' => $order->shipping_postcode,
            'country' => $order->shipping_country,
        ]);

        if ($courier->courier?->adapter === 'test') {
            $booked = app(TestCourierAdapter::class)->book($courier, $shipment);
            $shipment->forceFill([
                'tracking_number' => $booked['tracking_number'],
                'external_id' => $booked['external_id'],
                'status' => 'booked',
                'raw_status' => 'booked',
                'booked_at' => now(),
            ])->save();
        }

        // Update order fulfillment status
        if ($order->fulfilment_status === Order::UNFULFILLED) {
            $order->update(['status' => 'shipped', 'fulfilment_status' => Order::PARTIAL]);
        } else {
            $order->update(['status' => 'shipped']);
        }

        return response()->json([
            'message' => "Order dispatched to {$courier->label}.",
            'data' => ['shipment_id' => $shipment->public_id],
        ]);
    }

    /** Cancel the latest active shipment and notify the courier adapter. */
    public function cancelDispatch(string $orderId, CourierAdapterResolver $adapters): JsonResponse
    {
        $business = $this->tenant->business();

        abort_if($business === null, 409, 'No business context.');

        $order = Order::query()
            ->where('business_id', $business->id)
            ->where('public_id', $orderId)
            ->with(['shipments.courierConnection.courier'])
            ->firstOrFail();

        $shipment = $order->shipments
            ->first(fn (Shipment $candidate): bool => ! $candidate->status()->isFinal());

        if ($shipment === null) {
            return response()->json(['message' => 'This order has no active shipment to cancel.'], 422);
        }

        $connection = $shipment->courierConnection;
        $adapter = $connection === null ? null : $adapters->for($connection);

        if ($connection === null || $adapter === null || ! $adapter->cancel($connection, $shipment)) {
            return response()->json([
                'message' => 'This courier does not support cancellation through its API.',
            ], 422);
        }

        app(ShipmentTracker::class)->record(
            $shipment,
            'cancelled',
            ['source' => 'dashboard'],
            ['source' => 'dashboard', 'description' => 'Cancelled from the order book'],
        );

        return response()->json([
            'message' => "Shipment cancelled with {$connection->label}.",
            'data' => ['shipment_id' => $shipment->public_id],
        ]);
    }

    /**
     * Dispatch multiple orders to a courier.
     *
     * Each order is dispatched independently so one failure doesn't stop the rest.
     */
    public function bulkDispatch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order_ids' => ['required', 'array', 'min:1', 'max:100'],
            'order_ids.*' => ['required', 'string'],
            'courier_id' => ['required', 'string'],
        ]);

        $business = $this->tenant->business();
        if ($business === null) {
            return response()->json(['message' => 'No business context.'], 422);
        }

        // Find the courier connection
        $courier = CourierConnection::query()
            ->where('business_id', $business->id)
            ->where('public_id', $validated['courier_id'])
            ->usable()
            ->first();

        if ($courier === null) {
            return response()->json(['message' => 'Courier connection not found or not usable.'], 404);
        }

        // Fetch orders
        $orders = Order::query()
            ->where('business_id', $business->id)
            ->whereIn('public_id', $validated['order_ids'])
            ->get();

        if ($orders->isEmpty()) {
            return response()->json(['message' => 'No orders found.'], 404);
        }

        $dispatched = 0;
        $failed = 0;
        $errors = [];

        foreach ($orders as $order) {
            try {
                if ($order->archived_at !== null || in_array($order->status, [Order::COMPLETED, Order::CANCELLED], true)) {
                    throw new \RuntimeException('Archived, completed, and cancelled orders cannot be dispatched.');
                }

                // Use order total for COD amount in bulk dispatch
                $shipment = Shipment::create([
                    'account_id' => $business->account_id,
                    'business_id' => $business->id,
                    'order_id' => $order->id,
                    'courier_connection_id' => $courier->id,
                    'status' => 'draft',
                    'is_cod' => (bool) $order->is_cod,
                    'currency' => $order->currency,
                    'cod_amount_minor' => (int) $order->total_minor,
                    'recipient_name' => $order->shipping_name ?? $order->customer?->name,
                    'recipient_phone' => $order->shipping_phone ?? $order->customer?->phone,
                    'address' => $order->shipping_address,
                    'city' => $order->shipping_city,
                    'postcode' => $order->shipping_postcode,
                    'country' => $order->shipping_country,
                ]);

                if ($courier->courier?->adapter === 'test') {
                    $booked = app(TestCourierAdapter::class)->book($courier, $shipment);
                    $shipment->forceFill([
                        'tracking_number' => $booked['tracking_number'],
                        'external_id' => $booked['external_id'],
                        'status' => 'booked',
                        'raw_status' => 'booked',
                        'booked_at' => now(),
                    ])->save();
                }

                // Update order fulfillment status
                if ($order->fulfilment_status === Order::UNFULFILLED) {
                    $order->update(['status' => 'shipped', 'fulfilment_status' => Order::PARTIAL]);
                } else {
                    $order->update(['status' => 'shipped']);
                }

                $dispatched++;
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = "Order {$order->number}: ".$e->getMessage();
            }
        }

        $message = "{$dispatched} order".($dispatched === 1 ? '' : 's').' dispatched';
        if ($failed > 0) {
            $message .= ", {$failed} failed";
        }

        return response()->json([
            'message' => $message,
            'data' => [
                'dispatched' => $dispatched,
                'failed' => $failed,
                'errors' => $errors,
            ],
        ], $failed > 0 && $dispatched === 0 ? 422 : 200);
    }
}
