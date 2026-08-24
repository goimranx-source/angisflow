<?php

declare(strict_types=1);

namespace App\Http\Api\V1;

use App\Domain\Media\Models\MediaItem;
use App\Domain\Catalogue\Models\Product;
use App\Domain\Integrations\Models\Integration;
use App\Domain\Integrations\PlatformRegistry;
use App\Domain\Integrations\PullSync;
use App\Domain\Integrations\Support\StatusMap;
use App\Domain\Money\Currencies;
use App\Domain\Money\CurrencyService;
use App\Domain\Sales\Models\Order;
use App\Domain\Storefront\StoreCode;
use App\Domain\Storefront\StorefrontService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Storefront;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * One shop this business sells through.
 *
 * ── Why the figures are here and not on the orders list ──────────────────────
 *
 * A storefront's whole reason to exist as a page is the question "how is this
 * shop doing, and is its feed healthy" — which is two facts from two different
 * places: the order book, and the connection behind it. Answering them on the
 * page about that shop saves somebody filtering the order list and then going
 * to Settings to check whether the sync ran.
 */
class StorefrontsEndpoint
{
    /**
     * What kinds of shop a business can have.
     *
     * Kept here rather than as a database lookup because these are this
     * application's own vocabulary — the same reason the order statuses are in
     * code. A business adding a sixth kind would want it to mean something to
     * the reports, and a free-text kind means nothing to anything.
     *
     * @var array<string, string>
     */
    public const TYPES = [
        'online' => 'Online store',
        'pos' => 'Walk-in / POS',
        'brand' => 'Brand store',
        'regional' => 'Regional store',
        'marketplace' => 'Marketplace',
        'wholesale' => 'Wholesale',
    ];

    /**
     * The states a shop can be in.
     *
     * Four rather than a boolean, because "switched off", "down for work" and
     * "not finished yet" are different things to whoever is reading the list —
     * and only the first of them means somebody turned it off deliberately.
     *
     * @var array<string, string>
     */
    public const STATUSES = [
        'active' => 'Active',
        'inactive' => 'Inactive',
        'maintenance' => 'Maintenance',
        'draft' => 'Draft',
    ];

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly CurrencyService $currency,
        private readonly PlatformRegistry $registry,
    ) {}

    /** Every shop this business sells through. */
    public function index(Request $request): JsonResponse
    {
        $businessId = $this->businessId();

        $query = Storefront::query()->where('business_id', $businessId);

        /*
         * The filters the page actually sends.
         *
         * It has been sending search, status and sort since it was written; this
         * endpoint ignored them, so typing in the box did nothing and clicking a
         * column heading did nothing. A control that does not work is worse than
         * one that is absent, because somebody trusts the result.
         */
        if ($search = trim((string) $request->query('search', ''))) {
            $like = '%'.$search.'%';

            $query->where(fn ($q) => $q
                ->where('name', 'like', $like)
                ->orWhere('slug', 'like', $like)
                ->orWhere('custom_domain', 'like', $like));
        }

        if ($status = trim((string) $request->query('status', ''))) {
            $query->where('status', $status);
        }

        if ($type = trim((string) $request->query('type', ''))) {
            $query->where('type', $type);
        }

        // From an allowlist: a column name out of a query string and into an
        // ORDER BY is an injection, and "the page only sends four" is not a
        // control, since the page is not what sends the request.
        $sortable = [
            'name' => 'name',
            'created_at' => 'created_at',
            'last_updated' => 'updated_at',
            'status' => 'status',
            'type' => 'type',
        ];

        $by = $sortable[(string) $request->query('sort_by', 'name')] ?? 'name';
        $direction = strtolower((string) $request->query('sort_direction', 'asc')) === 'desc' ? 'desc' : 'asc';

        // One resolution for the whole list rather than one per row.
        $codes = StoreCode::forBusiness($businessId);

        $shops = $query
            ->orderBy($by, $direction)
            ->get()
            ->map(fn (Storefront $s): array => $this->summarise($s, $businessId, $codes))
            ->all();

        /*
         * Sorting by a figure this endpoint computes rather than stores — orders
         * or revenue — is done after the fact, because those come from the order
         * book per shop and cannot be an ORDER BY on this table.
         */
        $computed = ['total_orders' => 'total_orders', 'total_revenue' => 'total_revenue'];
        $sortKey = $computed[(string) $request->query('sort_by', '')] ?? null;

        if ($sortKey !== null) {
            usort($shops, fn (array $a, array $b): int => $direction === 'desc'
                ? $b[$sortKey] <=> $a[$sortKey]
                : $a[$sortKey] <=> $b[$sortKey]);
        }

        return response()->json([
            'data' => $shops,

            // The vocabularies, so the filter and the form offer exactly what
            // the column accepts rather than each keeping its own copy.
            'types' => array_map(
                fn (string $k, string $v): array => ['value' => $k, 'label' => $v],
                array_keys(self::TYPES),
                array_values(self::TYPES),
            ),
            // Every currency this application can scale, so the shop's currency
            // is chosen rather than typed — a code it does not know would store
            // yen a hundred times too large.
            'currencies' => Currencies::options(),

            'statuses' => array_map(
                fn (string $k, string $v): array => ['value' => $k, 'label' => $v],
                array_keys(self::STATUSES),
                array_values(self::STATUSES),
            ),

            // The figures above the table, for every shop rather than the page.
            'summary' => [
                'total_storefronts' => count($shops),
                'active_count' => count(array_filter($shops, fn (array $s): bool => (bool) $s['is_active'])),
                'total_products' => (int) Product::query()->where('business_id', $businessId)->count(),
                // Summed in the books' currency, never in the shops' own — six
                // shops selling in six currencies cannot be added together, and
                // a total that quietly does it is worse than no total.
                'total_revenue' => round(array_sum(array_column($shops, 'books_revenue')), 2),
            ],
            /*
             * The shape behind each figure, for the sparklines on the cards.
             *
             * A card can say revenue is 75,815 and be read two opposite ways
             * depending on whether that is the top of a climb or the end of a
             * slide, and no single figure tells them apart. The line does,
             * which is the only reason to draw one — a line invented to fill
             * the space under a number is worse than the empty space.
             */
            'trends' => $this->trends($businessId),

            // The list page reads meta for its pagination footer. Every shop is
            // returned in one page — a business has a handful, not thousands.
            'meta' => [
                'total' => count($shops),
                'per_page' => max(count($shops), 1),
                'current_page' => 1,
                'last_page' => 1,
            ],
        ]);
    }

    /**
     * Fourteen days of shape for each headline figure.
     *
     * ── Why counted from the orders rather than stored ───────────────────────
     *
     * Because a stored daily total is a second copy of what the orders already
     * say, and the two disagree the first time an order is amended, cancelled
     * or moved between shops. Counting on demand is one query per series
     * against an indexed date column, over a fortnight of a single business's
     * rows — cheap enough that the copy would be optimising the wrong thing.
     *
     * ── Days with nothing in them are still days ─────────────────────────────
     *
     * A shop that sold nothing on Sunday still has a Sunday. Grouping in SQL
     * returns only the days that have rows, and drawing those evenly spaced
     * quietly closes the gap — a quiet week and a busy one come out looking
     * identical. The frame is built first and filled second.
     *
     * @return array<string, list<float|int>>
     */
    private function trends(int $businessId): array
    {
        $from = now()->subDays(13)->startOfDay();

        $frame = [];

        for ($day = $from->copy(); $day->lte(now()); $day->addDay()) {
            $frame[$day->toDateString()] = 0;
        }

        $revenue = $frame;
        $orders = $frame;

        $rows = Order::query()
            ->where('business_id', $businessId)
            ->whereNull('archived_at')
            ->where('ordered_on', '>=', $from->toDateString())
            ->selectRaw('ordered_on, COUNT(*) as orders_count, SUM(total_minor) as total_minor')
            ->groupBy('ordered_on')
            ->get();

        foreach ($rows as $row) {
            // Dates come back as datetimes on some drivers, and a row outside
            // the frame would add a fifteenth point and stretch the line.
            $day = mb_substr((string) $row->ordered_on, 0, 10);

            if (! array_key_exists($day, $frame)) {
                continue;
            }

            $orders[$day] = (int) $row->orders_count;

            // Minor units divided here rather than in the browser, which does
            // not know the scale of this business's currency.
            $revenue[$day] = round(((int) $row->total_minor) / 100, 2);
        }

        /*
         * Products are counted as they stood at the end of each day.
         *
         * It is a total rather than daily activity, so the honest line is the
         * running one. A count of products *added* each day would draw a line
         * of mostly zeros beneath a figure reading 24, which says nothing true
         * about either.
         */
        $running = (int) Product::query()
            ->where('business_id', $businessId)
            ->where('created_at', '<', $from)
            ->count();

        $added = Product::query()
            ->where('business_id', $businessId)
            ->where('created_at', '>=', $from)
            ->selectRaw('DATE(created_at) as day, COUNT(*) as added')
            ->groupBy('day')
            ->pluck('added', 'day');

        $products = [];

        foreach (array_keys($frame) as $day) {
            $running += (int) ($added[$day] ?? 0);
            $products[] = $running;
        }

        return [
            'revenue' => array_values($revenue),
            'orders' => array_values($orders),
            'products' => $products,
        ];
    }


    /**
     * Add a shop.
     *
     * Through StorefrontService, which knows the two dozen columns a storefront
     * needs and their defaults — half of them NOT NULL. Building one here from
     * the three fields a form collects fails on the fourth column.
     */
    public function store(Request $request, StorefrontService $storefronts): JsonResponse
    {
        $businessId = $this->businessId();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => ['nullable', 'string', 'max:8'],
            'logo' => ['nullable', 'string', 'max:64'],
            'custom_domain' => ['nullable', 'string', 'max:191'],
            'type' => ['nullable', Rule::in(array_keys(self::TYPES))],
            'status' => ['nullable', Rule::in(array_keys(self::STATUSES))],
            'currency' => ['nullable', 'string', 'size:3'],
        ]);

        $name = trim((string) $validated['name']);
        $slug = Str::slug($name) ?: 'shop';
        $suffix = 2;

        // A business may well run "Main Shop" in two regions; the name is theirs
        // to repeat and the slug is ours to keep unique.
        while (Storefront::query()->where('business_id', $businessId)->where('slug', $slug)->exists()) {
            $slug = Str::slug($name).'-'.$suffix++;
        }

        $shop = $storefronts->createStorefront([
            'name' => $name,
            'slug' => $slug,

            // Blank is a real answer: StoreCode falls back to initials, so a
            // shop is legible from the moment it exists and the tag can be
            // chosen later by whoever cares what it says.
            'code' => $this->codeFor($validated['code'] ?? null, $businessId, null),
            'logo_media_id' => $this->mediaIdFor($validated['logo'] ?? null),
            'type' => $validated['type'] ?? 'main',
            'status' => $status = $validated['status'] ?? 'active',

            // The flag follows the status here too. Set only on update, a shop
            // created as Draft or Maintenance would have been live from the
            // moment it was made.
            'is_active' => $status === 'active',
            'currency' => filled($validated['currency'] ?? null)
                ? mb_strtoupper((string) $validated['currency'])
                : null,
            'custom_domain' => filled($validated['custom_domain'] ?? null)
                ? preg_replace('#^https?://#', '', trim((string) $validated['custom_domain']))
                : null,
        ]);

        return response()->json(['data' => $this->summarise($shop, $businessId)], 201);
    }

    /**
     * Change a shop.
     *
     * ── Why currency is editable per shop ────────────────────────────────────
     *
     * A business selling into two markets prices in two currencies, and the
     * shop is where that decision belongs — an order taken there is taken in
     * that currency, and a product listed there is priced in it. The books still
     * read in one currency; conversion happens on the way out, so the figures on
     * this page and on the order list agree without either side pretending the
     * sale happened in a currency it did not.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $shop = $this->find($id);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'custom_domain' => ['nullable', 'string', 'max:191'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'code' => ['sometimes', 'nullable', 'string', 'max:8'],
            'logo' => ['sometimes', 'nullable', 'string', 'max:64'],
            'type' => ['sometimes', Rule::in(array_keys(self::TYPES))],
            'status' => ['sometimes', Rule::in(array_keys(self::STATUSES))],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        /*
         * The flag follows the status.
         *
         * is_active is what the rest of the application reads; status is what a
         * person sets. Letting them drift would mean a shop marked Maintenance
         * still quietly serving, which is the opposite of what was asked for.
         */
        if (array_key_exists('status', $validated)) {
            $validated['is_active'] = $validated['status'] === 'active';
        }

        if (array_key_exists('currency', $validated)) {
            $code = mb_strtoupper(trim((string) $validated['currency']));

            // From the table this application actually knows. A code it cannot
            // scale is a shop whose money would be stored wrong.
            abort_unless(Currencies::isKnown($code), 422, 'That is not a currency this application knows.');

            $validated['currency'] = $code;
        }

        if (array_key_exists('logo', $validated)) {
            // The request names a picture by its public id; the column holds the
            // row id. Unset either way, or fill() would try to write 'logo'.
            $validated['logo_media_id'] = $this->mediaIdFor($validated['logo']);
            unset($validated['logo']);
        }

        if (array_key_exists('code', $validated)) {
            $validated['code'] = $this->codeFor($validated['code'], (int) $shop->business_id, (int) $shop->id);
        }

        if (array_key_exists('custom_domain', $validated) && filled($validated['custom_domain'])) {
            $validated['custom_domain'] = preg_replace('#^https?://#', '', trim((string) $validated['custom_domain']));
        }

        $shop->fill($validated)->save();

        return response()->json(['data' => $this->summarise($shop->fresh(), (int) $shop->business_id)]);
    }

    /**
     * A picture from the library, named by its public id.
     *
     * Looked up rather than trusted: the column is a foreign key, and a client
     * that sends an id belonging to another account must not be able to point a
     * shop at somebody else's file. MediaItem is account-scoped, so a stranger's
     * id simply is not found.
     */
    private function mediaIdFor(?string $publicId): ?int
    {
        if (! filled($publicId)) {
            return null;
        }

        $media = MediaItem::query()->where('public_id', $publicId)->first();

        abort_if($media === null, 422, 'That image is not in your media library.');

        return (int) $media->id;
    }

    /**
     * A tag as it should be stored, or null for "let it be derived".
     *
     * ── Why the clash is refused rather than adjusted ────────────────────────
     *
     * Because it was typed on purpose. A derived tag can be quietly numbered —
     * nobody chose it, so nobody is surprised by VB2. A tag somebody entered is
     * a decision, and silently storing something else would mean the field they
     * filled in does not say what they filled in.
     *
     * The database enforces this too. Checking here as well is what turns a
     * constraint violation into a sentence, and lets it name the shop already
     * using the tag.
     */
    private function codeFor(?string $given, int $businessId, ?int $ignoreId): ?string
    {
        $code = StoreCode::clean((string) $given);

        if ($code === '') {
            return null;
        }

        $clash = Storefront::query()
            ->where('business_id', $businessId)
            ->where('code', $code)
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
            ->value('name');

        abort_if($clash !== null, 422, "\"{$code}\" is already the short code for {$clash}.");

        return $code;
    }

    /**
     * Remove a shop.
     *
     * Soft-deleted, and refused while orders point at it. A storefront is what
     * attributes revenue to a channel; deleting one with sales behind it would
     * leave those orders reading as counter sales and the channel's history
     * gone — which is not a deletion somebody can undo from the screen.
     */
    public function destroy(string $id): JsonResponse
    {
        $shop = $this->find($id);

        $orders = Order::query()
            ->where('business_id', $shop->business_id)
            ->where('storefront_id', $shop->id)
            ->count();

        if ($orders > 0) {
            return response()->json([
                'message' => "This shop has {$orders} order".($orders === 1 ? '' : 's')
                    .' behind it. Deactivate it instead — deleting would detach that revenue from the channel that earned it.',
            ], 422);
        }

        $shop->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    /**
     * Bring this shop's records in now.
     *
     * The same sync the Settings screen runs, reached from the shop itself —
     * because "something is missing from this shop" is noticed while looking at
     * the shop, not while looking at a list of connections. It runs the same
     * code, so the two cannot produce different results.
     *
     * Supports both incremental and full sync via the `full` parameter:
     * - false (default): Incremental sync from last high-water mark
     * - true: Full sync from the beginning, ignoring last sync timestamp
     */
    public function sync(Request $request, string $id, PullSync $sync): JsonResponse
    {
        $shop = $this->find($id);
        $connection = $this->connectionFor($shop);

        if ($connection === null) {
            return response()->json([
                'message' => 'Nothing is connected to this shop, so there is nothing to bring in.',
            ], 422);
        }

        $report = $sync->run(
            $connection,
            null, // entities - null means all available entities
            (bool) $request->boolean('full'), // full sync flag
        );

        if ($report->failed()) {
            return response()->json(['message' => $report->error, 'data' => $report->toArray()], 422);
        }

        return response()->json(['data' => $report->toArray()]);
    }

    private function find(string $id): Storefront
    {
        return Storefront::query()
            ->where('business_id', $this->businessId())
            ->where(fn ($q) => $q->where('public_id', $id)->orWhere('slug', $id))
            ->firstOrFail();
    }

    public function show(string $id): JsonResponse
    {
        $businessId = $this->businessId();

        $shop = Storefront::query()
            ->where('business_id', $businessId)
            ->where(fn ($q) => $q->where('public_id', $id)->orWhere('slug', $id))
            ->firstOrFail();

        $connections = Integration::query()
            ->where('business_id', $businessId)
            ->where('storefront_id', $shop->id)
            ->with('business')
            ->orderBy('name')
            ->get()
            ->map(function (Integration $i): array {
                $driver = $this->registry->for($i);

                return [
                    'id' => $i->public_id,
                    'name' => $i->name,
                    'platform' => $driver->label(),
                    'kind' => $this->registry->kindLabel($this->registry->kindFor($i)),
                    'status' => $i->status,
                    'is_active' => (bool) $i->is_active,
                    'last_success_at' => $i->last_success_at?->toIso8601String(),
                    'records_synced_total' => (int) $i->records_synced_total,
                    'unmapped_statuses' => count(StatusMap::for($i)->unclaimed()),
                    'store' => ['id' => $i->storefront?->public_id, 'name' => $i->storefront?->name],
                ];
            })
            ->all();

        return response()->json([
            'data' => $this->summarise($shop, $businessId) + ['connections' => $connections],
        ]);
    }

    /**
     * What this shop has sold, in the books' currency.
     *
     * Grouped by the currency each order was taken in and converted after,
     * because summing across currencies produces a number that is not wrong so
     * much as meaningless.
     *
     * @return array<string, mixed>
     */
    /**
     * @param  array<int, string>|null  $codes  resolved once by the caller where it
     *                                          lists more than one shop
     */
    private function summarise(Storefront $shop, int $businessId, ?array $codes = null): array
    {
        // Uniqueness is a property of the whole set, so this cannot be worked
        // out per shop. Resolved here only when summarising one on its own.
        $codes ??= StoreCode::forBusiness($businessId);

        $base = $this->currency->base();

        $rows = Order::query()
            ->where('business_id', $businessId)
            ->where('storefront_id', $shop->id)
            ->getQuery()
            ->select('currency')
            ->selectRaw('COUNT(*) as orders')
            ->selectRaw('SUM(total_minor) as total')
            ->groupBy('currency')
            ->get();

        /*
         * ── Two figures, because they answer two questions ───────────────────
         *
         * A shop selling in dollars should say so. Reporting its takings in the
         * books' currency answers "what is this worth to the business", which is
         * a fine question but not the one somebody asks while looking at that
         * shop — they want to know what it actually took, in the money it
         * actually took it in, so the figure can be checked against the shop's
         * own admin.
         *
         * So the shop's own currency leads, and the books' figure travels
         * alongside for the dashboard and for adding shops together.
         */
        $sells = mb_strtoupper((string) ($shop->currency ?: $base));

        $orders = 0;
        $inShop = 0;
        $inBooks = 0;

        foreach ($rows as $row) {
            $orders += (int) $row->orders;
            $from = (string) $row->currency;

            $inShop += $this->currency->convertMinor((int) $row->total, $from, $sells) ?? 0;
            $inBooks += $this->currency->convertMinor((int) $row->total, $from, $base) ?? 0;
        }

        $revenue = round($inShop / (10 ** Currencies::scale($sells)), 2);
        $booksRevenue = round($inBooks / (10 ** Currencies::scale($base)), 2);

        /*
         * Two vocabularies, deliberately.
         *
         * The list page was written before this endpoint and reads
         * products_count / total_orders / total_revenue / theme; the detail page
         * reads orders_count / revenue. Both are served rather than one being
         * renamed, because renaming would break the page that already works —
         * and a list crashing on an undefined figure is exactly what happened
         * when this endpoint first answered with only half of them.
         */
        return [
            'id' => $shop->public_id,
            'name' => $shop->name,
            'slug' => $shop->slug,
            'domain' => $this->addressOf($shop),
            'external_url' => $this->addressOf($shop, true),

            /*
             * The connection feeding this shop, if any.
             *
             * Carried on the list so the edit drawer can offer that shop's
             * mapping without a second request — and so a storefront hosted by
             * us can be told apart from one that is somebody else's shop, which
             * decides whether appearance settings mean anything at all.
             */
            'connection_id' => $this->connectionFor($shop)?->public_id,
            'is_connected' => $this->connectionFor($shop) !== null,
            'type' => (string) ($shop->type ?: 'main'),
            'status' => (string) ($shop->status ?: ($shop->is_active ? 'active' : 'inactive')),
            'is_active' => (bool) $shop->is_active,

            'products_count' => (int) Product::query()->where('business_id', $businessId)->count(),

            // The detail page's names.
            'orders_count' => $orders,
            'revenue' => $revenue,

            // The list page's names for the same two figures.
            'total_orders' => $orders,
            'total_revenue' => $revenue,

            // The same takings in the books' currency, for the dashboard and
            // for adding shops together — which cannot be done in six.
            'books_revenue' => $booksRevenue,
            'currency_symbol' => Currencies::symbol($sells),

            'theme' => (string) ($shop->theme_template ?: 'default'),
            'language' => 'en',

            /*
             * Two currencies, and they are not the same question.
             *
             * `currency` is what this shop sells in — the field somebody edits,
             * and what an order taken there is denominated in. `books_currency`
             * is what the figures above are stated in, because revenue is
             * converted on the way out.
             *
             * Returning the books currency as the shop's own made changing a
             * shop to dirhams appear to do nothing.
             */
            'currency' => (string) ($shop->currency ?: $base),
            'books_currency' => $base,

            /*
             * The short tag, in both forms.
             *
             * `code` is what was actually chosen and is what the edit form must
             * show — blank when nothing was, so the field does not appear filled
             * in with something nobody typed. `code_display` is what screens
             * render, which falls back to initials so no order row is ever
             * tagged with nothing.
             */
            'code' => $shop->code,
            'logo_url' => $shop->logo?->url(),
            'logo_id' => $shop->logo?->public_id,
            'code_display' => $codes[(int) $shop->id] ?? null,
            'created_at' => $shop->created_at?->toIso8601String(),
            'last_updated' => $shop->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Where this shop actually lives on the internet.
     *
     * ── Why it comes from the connection ─────────────────────────────────────
     *
     * A connected storefront is not hosted here. Its address is the WooCommerce
     * site or Shopify shop it was connected to, which is already stored as that
     * connection's base URL — so "Visit store" should open the shop somebody
     * actually sells through.
     *
     * Built from a custom domain first, then the connection, and only then the
     * slug. Before this it was the slug alone, which produced links to
     * `https://vorosa-bajar` — an address that does not exist.
     *
     * Only the address, never the credentials beside it in the same column.
     *
     * @param  bool  $full  with the scheme, for an href
     */
    private function addressOf(Storefront $shop, bool $full = false): string
    {
        $host = trim((string) $shop->custom_domain);

        if ($host === '') {
            $connection = $this->connectionFor($shop);

            $host = trim((string) (
                $connection?->config('base_url')
                ?? $connection?->config('shop_domain')
                ?? ''
            ));
        }

        if ($host === '') {
            return $full ? '' : (string) $shop->slug;
        }

        $host = rtrim($host, '/');
        $bare = preg_replace('#^https?://#', '', $host) ?? $host;

        // The list shows the bare host because a scheme is noise in a table;
        // the link needs one or the browser treats it as a relative path.
        return $full ? (str_starts_with($host, 'http') ? $host : 'https://'.$bare) : $bare;
    }

    /**
     * The connection behind a shop.
     *
     * Memoised per request: the address, the connected flag and the connection
     * id all want it, and reading it three times means decrypting the same
     * credentials three times for one row in a list.
     *
     * @var array<int, Integration|null>
     */
    private array $connections = [];

    private function connectionFor(Storefront $shop): ?Integration
    {
        return $this->connections[$shop->id] ??= Integration::query()
            ->where('storefront_id', $shop->id)
            ->orderByDesc('is_active')
            ->first();
    }

    private function businessId(): int
    {
        $id = $this->tenant->business()?->id;

        abort_if($id === null, 409, 'No business is open, so there are no shops to show.');

        return (int) $id;
    }
}
