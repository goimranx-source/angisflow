<?php

declare(strict_types=1);

namespace App\Http\Api;

use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * One shape for every JSON response the front end reads.
 *
 * ── Why cursor pagination, everywhere, with no page numbers ──────────────────
 *
 * LIMIT/OFFSET is how almost every Laravel list is written and it is quietly
 * quadratic: to serve page 5,000 the database walks 100,000 rows and throws
 * away 99,980 of them. On a table with a hundred million orders, the early
 * pages are instant and page 500 takes eight seconds — so the problem never
 * shows up in testing and arrives with the biggest customer.
 *
 * Worse, paginate() runs a second COUNT(*) query on every request purely to
 * print "of 1,240 pages". On a large tenant-scoped table that count is often
 * slower than fetching the rows.
 *
 * Cursor pagination keys off the last row seen — `where id < ? order by id desc
 * limit 50` — which uses the index directly and costs the same whether it is
 * the first page or the millionth. There is no total, and that is the trade:
 * the interface says "load more" rather than "page 4 of 1,240". For a working
 * screen that is not a loss; nobody navigates to page 900 of their orders.
 *
 * Where a genuine total is needed — a report, a dashboard tile — it is a
 * separate, cached, deliberately-written aggregate rather than a side effect of
 * drawing a table.
 */
final class ApiResponse
{
    /** The largest page anybody can ask for, however they ask. */
    private const MAX_PER_PAGE = 200;

    private const DEFAULT_PER_PAGE = 50;

    /**
     * A single item.
     *
     * @param  array<string, mixed>  $meta
     */
    public static function item(mixed $data, array $meta = []): JsonResponse
    {
        return response()->json(array_filter([
            'data' => $data,
            'meta' => $meta ?: null,
        ], static fn ($value) => $value !== null));
    }

    /**
     * A plain list that is known to be small and bounded — currencies,
     * businesses, the roles in an account. Anything that could grow with the
     * business goes through cursor() instead.
     *
     * @param  iterable<mixed>  $data
     */
    public static function collection(iterable $data): JsonResponse
    {
        return response()->json([
            'data' => $data instanceof \Traversable ? iterator_to_array($data) : $data,
        ]);
    }

    /**
     * A cursor-paginated list.
     *
     * @param  EloquentBuilder|QueryBuilder  $query  already ordered
     * @param  callable(mixed): array<string, mixed>  $transform
     */
    public static function cursor(Request $request, $query, callable $transform): JsonResponse
    {
        $perPage = min(
            max((int) $request->integer('per_page', self::DEFAULT_PER_PAGE), 1),
            self::MAX_PER_PAGE,
        );

        $page = $query->cursorPaginate($perPage, ['*'], 'cursor', $request->query('cursor'));

        return response()->json([
            'data' => collect($page->items())->map($transform)->values()->all(),
            'meta' => [
                'per_page' => $page->perPage(),
                // The client passes this back to get the next slice. Opaque on
                // purpose: it encodes the ordering columns of the last row, and
                // a client that tries to construct one by hand should fail
                // rather than silently skip rows.
                'next_cursor' => $page->nextCursor()?->encode(),
                'prev_cursor' => $page->previousCursor()?->encode(),
                'has_more' => $page->hasMorePages(),
            ],
        ]);
    }

    /**
     * A response the browser may reuse without asking again.
     *
     * For data that is genuinely per-user and genuinely stable for a few
     * seconds. `private` because the response contains one subscriber's data
     * and a shared cache must never hold it; `stale-while-revalidate` because
     * showing a five-second-old figure instantly, then correcting it, is what
     * makes a screen feel like it was already there.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function cacheable(array $payload, int $seconds = 30): JsonResponse
    {
        return response()
            ->json($payload)
            ->header(
                'Cache-Control',
                "private, max-age={$seconds}, stale-while-revalidate=".($seconds * 4)
            );
    }
}
