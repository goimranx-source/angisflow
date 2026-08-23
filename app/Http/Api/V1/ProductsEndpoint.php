<?php

declare(strict_types=1);

namespace App\Http\Api\V1;

use App\Domain\Catalogue\Models\Product;
use App\Domain\Money\Currencies;
use App\Domain\Money\CurrencyService;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The catalogue — what this business sells.
 *
 * ── Why this exists at all ───────────────────────────────────────────────────
 *
 * The products screen has been calling GET /products since it was written, and
 * the route was never built. Every visit answered 404 and the page showed
 * "Failed to load products" — a whole section of the application unreachable
 * because of a missing file rather than a hard problem.
 *
 * ── A product here, a variant in the table ───────────────────────────────────
 *
 * A product is the thing in the catalogue; a variant is the thing with a SKU, a
 * price and a stock level. The screen shows one row per product with a price and
 * a SKU on it, which means it is really showing the default variant — so that is
 * what is read, and a product with no variants still appears rather than
 * vanishing from its own catalogue.
 *
 * ── Money and stock are read in bulk ─────────────────────────────────────────
 *
 * Stock lives in stock_levels, one row per variant per location, and a product's
 * figure is the sum across locations. Asked per row that is two queries per
 * product; asked once for the page it is one, and the page is the same size
 * either way.
 */
class ProductsEndpoint
{
    private const PER_PAGE = 25;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly CurrencyService $currency,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $business = $this->tenant->business();

        abort_if($business === null, 409, 'No business is open, so there is no catalogue to show.');

        $base = $this->currency->base();
        $scale = 10 ** Currencies::scale($base);

        $query = $this->filtered($request, (int) $business->id);

        $sort = $this->sort($request);

        $page = (clone $query)
            ->with([
                'category:id,public_id,name',
                // Ordered so the default lands first when there is no flag set:
                // a catalogue row still needs a price to show.
                'variants' => fn ($q) => $q->orderByDesc('is_default')->orderBy('position'),
            ])
            ->orderBy($sort[0], $sort[1])
            ->paginate(min(100, max(5, (int) $request->integer('per_page', self::PER_PAGE))));

        /** @var list<Product> $items */
        $items = $page->items();

        $stock = $this->stockFor($items);

        return response()->json([
            'data' => array_map(
                fn (Product $product): array => $this->present($product, $stock, $base, $scale),
                $items,
            ),

            'summary' => $this->summary(clone $query, $base, $scale),

            'categories' => \App\Domain\Catalogue\Models\ProductCategory::query()
                ->where('business_id', $business->id)
                ->orderBy('name')
                ->get(['public_id', 'name'])
                ->map(fn ($c): array => ['id' => $c->public_id, 'name' => $c->name])
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
     * Stock on hand for a page of products, in one query.
     *
     * Summed across locations, and net of what is reserved: a unit promised to
     * an order somebody has already placed is not a unit available to sell, and
     * showing it as though it were is how a shop oversells.
     *
     * @param  list<Product>  $products
     * @return array<int, array{on_hand: float, reorder: float}>  keyed by product id
     */
    private function stockFor(array $products): array
    {
        $variantIds = [];
        $ownerOf = [];

        foreach ($products as $product) {
            foreach ($product->variants as $variant) {
                $variantIds[] = (int) $variant->id;
                $ownerOf[(int) $variant->id] = (int) $product->id;
            }
        }

        if ($variantIds === []) {
            return [];
        }

        $totals = [];

        $rows = \Illuminate\Support\Facades\DB::table('stock_levels')
            ->whereIn('product_variant_id', $variantIds)
            ->selectRaw('product_variant_id, SUM(on_hand) AS on_hand, SUM(reserved) AS reserved, SUM(reorder_level) AS reorder_level')
            ->groupBy('product_variant_id')
            ->get();

        foreach ($rows as $row) {
            $product = $ownerOf[(int) $row->product_variant_id] ?? null;

            if ($product === null) {
                continue;
            }

            $totals[$product] ??= ['on_hand' => 0.0, 'reorder' => 0.0];

            $totals[$product]['on_hand'] += (float) $row->on_hand - (float) $row->reserved;
            $totals[$product]['reorder'] += (float) $row->reorder_level;
        }

        return $totals;
    }

    /**
     * @param  array<int, array{on_hand: float, reorder: float}>  $stock
     * @return array<string, mixed>
     */
    private function present(Product $product, array $stock, string $base, int $scale): array
    {
        $variant = $product->variants->firstWhere('is_default', true) ?? $product->variants->first();

        $figures = $stock[(int) $product->id] ?? ['on_hand' => 0.0, 'reorder' => 0.0];

        $onHand = $figures['on_hand'];

        /*
         * Below the reorder level rather than below an invented threshold.
         *
         * The level is the number somebody chose for this product; a fixed one
         * would be wrong for everything except by coincidence. Read from the
         * same bulk query as the quantity rather than through the relation,
         * because lazy loading throws here — deliberately, so an N+1 is found
         * by whoever wrote it.
         */
        $reorder = $figures['reorder'];

        return [
            'id' => $product->public_id,
            'sku' => $variant?->sku ?? '',
            'name' => $product->name,
            'description' => $product->summary ?: $product->description,

            'category' => $product->category === null ? null : [
                'id' => $product->category->public_id,
                'name' => $product->category->name,
            ],

            'price' => $this->money($variant?->price_minor, $variant?->currency, $base, $scale),
            'cost' => $this->money($variant?->cost_minor, $variant?->currency, $base, $scale),

            'stock_quantity' => $onHand,
            'stock_status' => match (true) {
                ! $product->is_stocked => 'in_stock',
                $onHand <= 0 => 'out_of_stock',
                $reorder > 0 && $onHand <= $reorder => 'low_stock',
                default => 'in_stock',
            },
            'reorder_point' => $reorder > 0 ? $reorder : null,

            'unit' => $variant?->unit,
            'barcode' => $variant?->barcode,
            'is_active' => (bool) $product->is_active,
            'is_featured' => false,
            'image_url' => null,
            'tags' => [],
            'created_at' => $product->created_at?->toIso8601String(),
        ];
    }

    /** A variant's money, converted into the books so the page can add it up. */
    private function money(?int $minor, ?string $from, string $base, int $scale): float
    {
        if ($minor === null) {
            return 0.0;
        }

        $converted = $this->currency->convertMinor((int) $minor, (string) ($from ?? $base), $base);

        return round(((int) ($converted ?? 0)) / $scale, 2);
    }

    /**
     * The figures above the table, for the whole filtered set.
     *
     * Computed over the same filters rather than from the page, for the reason
     * spelled out in OrdersEndpoint: totalling the page is what makes a figure
     * change when somebody clicks "next".
     *
     * @return array<string, mixed>
     */
    private function summary(Builder $query, string $base, int $scale): array
    {
        $total = (clone $query)->count();
        $active = (clone $query)->where('is_active', true)->count();

        /*
         * Stock value is the sellable quantity times what it cost us, summed.
         *
         * Cost rather than price: this answers "what is tied up in stock",
         * which is a number about money already spent, not money hoped for.
         */
        $value = \Illuminate\Support\Facades\DB::table('stock_levels')
            ->join('product_variants', 'product_variants.id', '=', 'stock_levels.product_variant_id')
            ->whereIn('product_variants.product_id', (clone $query)->select('id'))
            ->selectRaw('SUM((stock_levels.on_hand - stock_levels.reserved) * COALESCE(product_variants.cost_minor, 0)) AS worth')
            ->value('worth');

        $low = \Illuminate\Support\Facades\DB::table('stock_levels')
            ->join('product_variants', 'product_variants.id', '=', 'stock_levels.product_variant_id')
            ->whereIn('product_variants.product_id', (clone $query)->select('id'))
            ->whereColumn('stock_levels.on_hand', '<=', 'stock_levels.reorder_level')
            ->where('stock_levels.reorder_level', '>', 0)
            ->distinct()
            ->count('product_variants.product_id');

        return [
            'total_products' => $total,
            'active_products' => $active,
            'total_value' => round(((float) ($value ?? 0)) / $scale, 2),
            'low_stock_count' => $low,
        ];
    }

    private function filtered(Request $request, int $businessId): Builder
    {
        $query = Product::query()->where('business_id', $businessId);

        if ($search = trim((string) $request->query('search', ''))) {
            $like = '%'.$search.'%';

            $query->where(function (Builder $q) use ($like): void {
                $q->where('name', 'like', $like)
                    ->orWhere('brand', 'like', $like)
                    // Bound as a parameter, never interpolated — this is the
                    // only place user text reaches the query.
                    ->orWhereHas('variants', fn (Builder $v) => $v->where('sku', 'like', $like));
            });
        }

        if ($category = $request->query('category')) {
            $query->whereHas('category', fn (Builder $c) => $c->where('public_id', $category));
        }

        if (($status = $request->query('status')) !== null && $status !== '') {
            $query->where('is_active', $status === 'active');
        }

        return $query;
    }

    /** @return array{0: string, 1: string} */
    private function sort(Request $request): array
    {
        $columns = ['name' => 'name', 'created_at' => 'created_at', 'sku' => 'name'];

        $by = $columns[(string) $request->query('sort_by', 'name')] ?? 'name';
        $direction = $request->query('sort_direction') === 'desc' ? 'desc' : 'asc';

        return [$by, $direction];
    }
}
