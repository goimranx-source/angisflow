<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Media\Models\MediaItem;
use App\Domain\Media\Thumbnailer;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Makes thumbnails for whatever was uploaded before thumbnails existed.
 *
 * A schema change does not rewrite history: every row uploaded before this
 * feature shipped has thumb_path null and falls back to serving the original,
 * correctly but slowly. Run once after the deploy that adds thumbnailing, and
 * the fallback stops being needed for anything already in a library.
 *
 *   php artisan media:backfill-thumbnails
 */
class BackfillThumbnails extends Command
{
    protected $signature = 'media:backfill-thumbnails {--chunk=100}';

    protected $description = 'Generate thumbnails for media uploaded before thumbnailing existed.';

    public function handle(TenantContext $tenant): int
    {
        $disk = Storage::disk(config('filesystems.default'));
        $chunkSize = (int) $this->option('chunk');

        $total = MediaItem::query()
            ->withoutGlobalScopes()
            ->images()
            ->whereNull('thumb_path')
            ->count();

        if ($total === 0) {
            $this->info('Nothing to do — every image already has a thumbnail.');

            return self::SUCCESS;
        }

        $this->info("Making thumbnails for {$total} image(s)…");
        $bar = $this->output->createProgressBar($total);
        $made = 0;
        $skipped = 0;

        // withoutGlobalScopes because this runs across every subscriber; the
        // usual tenant scope would only ever see one.
        MediaItem::query()
            ->withoutGlobalScopes()
            ->images()
            ->whereNull('thumb_path')
            ->chunkById($chunkSize, function ($items) use ($disk, &$made, &$skipped, $bar) {
                foreach ($items as $item) {
                    $bar->advance();

                    if (! Thumbnailer::canHandle($item->mime_type) || ! $disk->exists($item->path)) {
                        $skipped++;

                        continue;
                    }

                    try {
                        $result = Thumbnailer::make($disk->path($item->path), $item->mime_type);

                        if ($result === null) {
                            // Already small enough — a valid, common answer.
                            $skipped++;

                            continue;
                        }

                        [$binary, $extension, $width, $height] = $result;

                        $folder = 'media/'.$item->account_id.'/thumbs';
                        $thumbPath = $folder.'/'.pathinfo($item->path, PATHINFO_FILENAME).'.'.$extension;

                        $disk->put($thumbPath, $binary);

                        $item->forceFill([
                            'thumb_path' => $thumbPath,
                            'thumb_width' => $width,
                            'thumb_height' => $height,
                        ])->saveQuietly();

                        $made++;
                    } catch (\Throwable $e) {
                        Log::warning('Thumbnail backfill failed', ['media_id' => $item->id, 'error' => $e->getMessage()]);
                        $skipped++;
                    }
                }
            });

        $bar->finish();
        $this->newLine(2);
        $this->info("{$made} thumbnail(s) made, {$skipped} skipped (already small, or unreadable).");

        return self::SUCCESS;
    }
}
