<?php

declare(strict_types=1);

namespace App\Domain\Media\Models;

use App\Domain\Shared\Concerns\HasPublicId;
use App\Domain\Tenancy\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * One file in the library.
 *
 * Uploaded once and used anywhere. The alternative — a file input on every form
 * that wants a picture — leaves the same logo uploaded six times under six
 * names, with nothing saying which of them anything is actually using.
 *
 * The *path* is stored, never a URL. A URL bakes in the disk, the host and the
 * scheme, so moving to a CDN or to S3 would mean rewriting every row; a path
 * plus the configured disk answers the same question and survives the move.
 */
class MediaItem extends Model
{
    use BelongsToAccount, HasPublicId, SoftDeletes;

    protected $fillable = [
        'public_id', 'account_id', 'uploaded_by_user_id',
        'path', 'thumb_path', 'thumb_width', 'thumb_height',
        'original_name', 'mime_type', 'size', 'checksum',
        'width', 'height', 'title', 'alt_text',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'thumb_width' => 'integer',
            'thumb_height' => 'integer',
        ];
    }

    public function scopeImages(Builder $query): Builder
    {
        return $query->where('mime_type', 'like', 'image/%');
    }

    public function scopeDocuments(Builder $query): Builder
    {
        return $query->where('mime_type', 'not like', 'image/%');
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }

    public function url(): string
    {
        return Storage::disk(config('filesystems.default'))->url($this->path);
    }

    /**
     * What a grid should show.
     *
     * Falls back to the original where no derivative was made — a small PNG, an
     * SVG, a file uploaded before thumbnails existed. Correct in every case, and
     * only slow in the ones where the file was small to begin with.
     */
    public function thumbUrl(): string
    {
        return $this->thumb_path
            ? Storage::disk(config('filesystems.default'))->url($this->thumb_path)
            : $this->url();
    }

    /** "2.4 MB" — the only form of a byte count anybody reads. */
    public function readableSize(): string
    {
        $bytes = (float) $this->size;
        $units = ['B', 'KB', 'MB', 'GB'];
        $unit = 0;

        while ($bytes >= 1024 && $unit < count($units) - 1) {
            $bytes /= 1024;
            $unit++;
        }

        return ($unit === 0 ? (string) (int) $bytes : number_format($bytes, $bytes < 10 ? 1 : 0)).' '.$units[$unit];
    }

    /** The shape the API hands to the client. */
    public function toPayload(): array
    {
        return [
            'id' => $this->public_id,
            'url' => $this->url(),
            'thumb_url' => $this->thumbUrl(),
            'name' => $this->title ?: $this->original_name,
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'extension' => strtoupper(pathinfo($this->path, PATHINFO_EXTENSION)),
            'size' => $this->size,
            'readable_size' => $this->readableSize(),
            'width' => $this->width,
            'height' => $this->height,
            'alt_text' => $this->alt_text,
            'is_image' => $this->isImage(),
            'uploaded_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
