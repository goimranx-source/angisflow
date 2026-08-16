<?php

declare(strict_types=1);

namespace App\Domain\Media\Actions;

use App\Domain\Media\Models\MediaItem;
use App\Domain\Media\Thumbnailer;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Put an uploaded file into the library.
 *
 * ── Everything is read before the file moves ─────────────────────────────────
 *
 * Storing consumes the temporary upload, and any question asked of the
 * UploadedFile afterwards — its size, its dimensions, its hash — fails with
 * "stat failed for …tmp", because there is nothing left at that path to stat.
 * Cheap to get wrong, and the error names a temp file rather than the mistake.
 *
 * ── The name on disk is not the name they gave it ────────────────────────────
 *
 * A filename from a browser is user input: it can collide with an existing one,
 * carry a path, or carry an extension the server would execute. What is stored
 * is a random name with an extension derived from the *detected* type, keeping
 * the original only as a label to show. That closes the whole class of "upload
 * a .php and then ask for it" without needing to reason about it.
 *
 * ── Uploading the same file twice gives the same item ────────────────────────
 *
 * People re-upload the same logo constantly — from the desktop, then from
 * downloads, then again next month because they could not find it. Matching on
 * a hash of the contents returns what is already there instead of filling the
 * library with copies nobody can tell apart.
 *
 * ── Foldered by account ──────────────────────────────────────────────────────
 *
 * A million subscribers sharing one directory is a directory no filesystem
 * enjoys listing and no operator enjoys inspecting. Files land under the
 * account's id, which also makes "delete everything belonging to this
 * subscriber" a single recursive delete.
 */
final class StoreMedia
{
    /** What the library will take. Anything else is refused by name. */
    private const ALLOWED = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/svg+xml' => 'svg',
        'image/x-icon' => 'ico',
        'image/vnd.microsoft.icon' => 'ico',
        'application/pdf' => 'pdf',
    ];

    public function __construct(private readonly TenantContext $tenant) {}

    /** @return list<string> */
    public static function allowedMimeTypes(): array
    {
        return array_keys(self::ALLOWED);
    }

    /**
     * @return array{0: MediaItem, 1: bool} the item, and whether it already existed
     */
    public function handle(UploadedFile $file, ?int $userId = null): array
    {
        $accountId = $this->tenant->requireAccountId();

        // The detected type, not the one the browser claimed. A client can send
        // any Content-Type it likes.
        $mime = $file->getMimeType() ?: 'application/octet-stream';
        $extension = self::ALLOWED[$mime] ?? null;

        if ($extension === null) {
            throw new RuntimeException('That kind of file cannot go in the library.');
        }

        // Read now — after store() there is no temporary file left to ask.
        $original = $file->getClientOriginalName();
        $size = (int) $file->getSize();
        $checksum = hash_file('sha256', $file->getPathname()) ?: null;
        [$width, $height] = $this->dimensions($file, $mime);

        // Already held. Returned rather than stored again, and said so, so the
        // interface can tell somebody why no new tile appeared.
        if ($checksum !== null) {
            $existing = MediaItem::query()->where('checksum', $checksum)->first();

            if ($existing !== null) {
                return [$existing, true];
            }
        }

        $disk = Storage::disk(config('filesystems.default'));
        $folder = "media/{$accountId}";

        $path = $file->storeAs($folder, Str::ulid().'.'.$extension, ['disk' => config('filesystems.default')]);

        if ($path === false) {
            throw new RuntimeException('That file could not be stored.');
        }

        // The preview. Generated from the file *now on the disk*, because the
        // temporary upload is gone by this line.
        [$thumbPath, $thumbWidth, $thumbHeight] = $this->makeThumbnail($disk, $path, $folder, $mime);

        return [
            MediaItem::create([
                'account_id' => $accountId,
                'uploaded_by_user_id' => $userId,
                'path' => $path,
                'thumb_path' => $thumbPath,
                'thumb_width' => $thumbWidth,
                'thumb_height' => $thumbHeight,
                'original_name' => $original,
                'mime_type' => $mime,
                'size' => $size,
                'checksum' => $checksum,
                'width' => $width,
                'height' => $height,
                'title' => pathinfo($original, PATHINFO_FILENAME),
            ]),
            false,
        ];
    }

    /**
     * Remove a file, its thumbnail and its row.
     *
     * The row goes whether or not the files did. A record pointing at something
     * no longer there is worse than no record: every screen that reads it
     * renders a broken image and nobody can tell why.
     */
    public function delete(MediaItem $media): void
    {
        $disk = Storage::disk(config('filesystems.default'));

        $disk->delete(array_filter([$media->path, $media->thumb_path]));

        $media->delete();
    }

    /**
     * @return array{0: ?string, 1: ?int, 2: ?int}
     */
    private function makeThumbnail(\Illuminate\Contracts\Filesystem\Filesystem $disk, string $path, string $folder, string $mime): array
    {
        if (! Thumbnailer::canHandle($mime)) {
            return [null, null, null];
        }

        try {
            // GD needs a real path. On a local disk that is the stored file; on
            // S3 it would be a temporary copy, which is the point at which this
            // work belongs on the queue rather than in the request.
            $absolute = $disk->path($path);

            $result = Thumbnailer::make($absolute, $mime);

            if ($result === null) {
                return [null, null, null];
            }

            [$binary, $extension, $width, $height] = $result;

            $thumbPath = $folder.'/thumbs/'.pathinfo($path, PATHINFO_FILENAME).'.'.$extension;

            $disk->put($thumbPath, $binary);

            return [$thumbPath, $width, $height];
        } catch (\Throwable $e) {
            // A thumbnail is an optimisation. Failing to make one must never
            // fail the upload — the reader falls back to the original, which is
            // slower and entirely correct.
            Log::warning('Thumbnail generation failed', ['path' => $path, 'error' => $e->getMessage()]);

            return [null, null, null];
        }
    }

    /**
     * @return array{0: ?int, 1: ?int}
     */
    private function dimensions(UploadedFile $file, string $mime): array
    {
        if (! str_starts_with($mime, 'image/') || $mime === 'image/svg+xml') {
            return [null, null];
        }

        // Suppressed rather than trusted: getimagesize warns on a file that
        // claims to be an image and is not, and a bad upload should be a
        // refusal, not a stack trace.
        $size = @getimagesize($file->getPathname());

        return $size ? [(int) $size[0], (int) $size[1]] : [null, null];
    }
}
