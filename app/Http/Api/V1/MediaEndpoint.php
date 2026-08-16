<?php

declare(strict_types=1);

namespace App\Http\Api\V1;

use App\Domain\Media\Actions\StoreMedia;
use App\Domain\Media\Models\MediaItem;
use App\Http\Api\ApiResponse;
use App\Http\Api\Endpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The media library.
 *
 * ── Paged by cursor, not by page number ──────────────────────────────────────
 *
 * A library grows without limit, and `OFFSET 5000` makes the database read five
 * thousand rows in order to throw them away. A cursor asks for "everything
 * after this id", which is an index seek whatever page you are on — the tenth
 * page costs exactly what the first did.
 *
 * It also does not shift under you: with an offset, uploading a file while
 * somebody is on page two pushes a row they have already seen onto page three.
 */
class MediaEndpoint extends Endpoint
{
    private const SORTS = [
        'newest' => ['id', 'desc'],
        'oldest' => ['id', 'asc'],
        'name' => ['title', 'asc'],
        'largest' => ['size', 'desc'],
        'smallest' => ['size', 'asc'],
    ];

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'kind' => ['nullable', Rule::in(['image', 'document'])],
            'sort' => ['nullable', Rule::in(array_keys(self::SORTS))],
        ]);

        [$column, $direction] = self::SORTS[$request->string('sort', 'newest')->value()] ?? self::SORTS['newest'];

        $query = MediaItem::query()
            ->when($request->string('kind')->value() === 'image', fn ($q) => $q->images())
            ->when($request->string('kind')->value() === 'document', fn ($q) => $q->documents())
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = trim((string) $request->string('search'));

                // Prefix match only. A leading wildcard cannot use an index, so
                // "%oo%" is a full scan of the subscriber's whole library on
                // every keystroke.
                $q->where(fn ($sub) => $sub
                    ->where('title', 'like', $term.'%')
                    ->orWhere('original_name', 'like', $term.'%'));
            })
            // The id is always the tiebreaker, whatever the primary order is —
            // a cursor needs a total order to seek against, and "sorted by
            // title" alone stalls the moment two files share one.
            ->orderBy($column, $direction)
            ->when($column !== 'id', fn ($q) => $q->orderBy('id', $direction));

        return ApiResponse::cursor(
            $request,
            $query,
            fn (MediaItem $item) => $item->toPayload(),
        );
    }

    /**
     * How much of the library is in use, and by what kind of file.
     *
     * A single grouped aggregate rather than the client counting what it can
     * see — the client only ever sees one page.
     */
    public function stats(): JsonResponse
    {
        $rows = MediaItem::query()
            ->selectRaw("case when mime_type like 'image/%' then 'image' else 'document' end as kind")
            ->selectRaw('count(*) as count')
            ->selectRaw('sum(size) as bytes')
            ->groupBy('kind')
            ->get();

        $images = $rows->firstWhere('kind', 'image');
        $documents = $rows->firstWhere('kind', 'document');

        return response()->json([
            'data' => [
                'total_count' => (int) $rows->sum('count'),
                'total_bytes' => (int) $rows->sum('bytes'),
                'images' => ['count' => (int) ($images->count ?? 0), 'bytes' => (int) ($images->bytes ?? 0)],
                'documents' => ['count' => (int) ($documents->count ?? 0), 'bytes' => (int) ($documents->bytes ?? 0)],
            ],
        ])->header('Cache-Control', 'private, max-age=15');
    }

    public function store(Request $request, StoreMedia $store): JsonResponse
    {
        $request->validate([
            // Both checks matter and neither replaces the other: `mimetypes`
            // reads the file's actual bytes, `max` stops a very large upload
            // being buffered before anything has looked at it.
            'file' => ['required', 'file', 'max:20480', 'mimetypes:'.implode(',', StoreMedia::allowedMimeTypes())],
        ], [
            'file.mimetypes' => 'Images and PDFs only.',
            'file.max' => 'That file is larger than 20 MB.',
        ]);

        try {
            [$media, $wasExisting] = $store->handle($request->file('file'), $request->user()?->getKey());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => $media->toPayload(),
            // True when the upload matched something already in the library by
            // content. The client uses this to say "already here" instead of
            // "uploaded" — the same file, found rather than duplicated.
            'duplicate' => $wasExisting,
        ], $wasExisting ? 200 : 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:120'],
            'alt_text' => ['nullable', 'string', 'max:200'],
        ]);

        // The account scope is the ownership check — another subscriber's file
        // is not visible to this query at all, so this 404s rather than 403s,
        // which is also the answer that leaks least.
        $media = MediaItem::query()->wherePublicId($id)->firstOrFail();

        $media->update($validated);

        return response()->json(['data' => $media->fresh()->toPayload()]);
    }

    public function destroy(string $id, StoreMedia $store): JsonResponse
    {
        $media = MediaItem::query()->wherePublicId($id)->firstOrFail();

        $store->delete($media);

        // Deliberately not hunting for settings that pointed at it. A settings
        // row holding a dead id resolves to a null URL and the screen shows its
        // placeholder — the same outcome as a cascade, without a scan of every
        // setting in the account on every delete.
        return response()->json(['message' => 'Removed from the library.']);
    }

    /**
     * Remove several at once.
     *
     * One request rather than the client firing N deletes: selecting twenty
     * files and pressing Delete should be one round trip, not twenty racing
     * each other.
     */
    public function destroyMany(Request $request, StoreMedia $store): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['string'],
        ]);

        $items = MediaItem::query()->whereIn('public_id', $validated['ids'])->get();

        foreach ($items as $item) {
            $store->delete($item);
        }

        return response()->json([
            'message' => $items->count() === 1 ? 'Removed from the library.' : "{$items->count()} files removed.",
            'removed' => $items->count(),
        ]);
    }
}
