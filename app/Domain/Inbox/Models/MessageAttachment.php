<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Models;

use App\Domain\Media\Models\MediaItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file that came with a message.
 *
 * `remote_url` is a lead, not a home. Most platforms expire theirs within days,
 * so a picture a customer sent is only ours once it has been fetched — which is
 * what is_fetched records, and what stops a support thread from quietly losing
 * its evidence.
 */
class MessageAttachment extends Model
{
    protected $attributes = ['is_fetched' => false];

    protected $fillable = [
        'message_id', 'media_item_id', 'remote_url', 'filename',
        'mime_type', 'bytes', 'is_fetched',
    ];

    protected function casts(): array
    {
        return ['is_fetched' => 'boolean', 'bytes' => 'integer'];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function mediaItem(): BelongsTo
    {
        return $this->belongsTo(MediaItem::class);
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'filename' => $this->filename,
            'mime_type' => $this->mime_type,
            'bytes' => $this->bytes,
            'is_fetched' => $this->is_fetched,
            'url' => $this->relationLoaded('mediaItem') ? $this->mediaItem?->url() : null,
        ];
    }
}
