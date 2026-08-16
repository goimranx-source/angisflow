<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Models;

use App\Domain\Shared\Concerns\HasPublicId;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One thing somebody said. */
class Message extends Model
{
    use BelongsToBusiness, HasPublicId;

    public const IN = 'in';
    public const OUT = 'out';

    protected $attributes = [
        'kind' => 'text', 'delivery_status' => 'sent',
        'is_automated' => false, 'is_internal' => false,
    ];

    protected $fillable = [
        'public_id', 'account_id', 'business_id', 'conversation_id',
        'direction', 'kind', 'body', 'external_id', 'external_reply_to',
        'sent_by', 'is_automated', 'is_internal',
        'delivery_status', 'failure_reason',
        'occurred_at', 'delivered_at', 'read_at', 'payload',
    ];

    protected function casts(): array
    {
        return [
            'is_automated' => 'boolean',
            'is_internal' => 'boolean',
            'occurred_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(MessageAttachment::class);
    }

    public function isInbound(): bool
    {
        return $this->direction === self::IN;
    }

    /**
     * Whether this counts as a human answering.
     *
     * An automated reply and an internal note both look like outbound traffic
     * and neither is a person responding to a customer. Counting them makes
     * every response-time figure a fiction — and a good-looking one, which is
     * worse than a bad one.
     */
    public function isHumanReply(): bool
    {
        return $this->direction === self::OUT && ! $this->is_automated && ! $this->is_internal;
    }

    public function scopeVisible(Builder $q): Builder
    {
        return $q->where('is_internal', false);
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'id' => $this->public_id,
            'direction' => $this->direction,
            'kind' => $this->kind,
            'body' => $this->body,
            'is_automated' => $this->is_automated,
            'is_internal' => $this->is_internal,
            'delivery_status' => $this->delivery_status,
            'failure_reason' => $this->failure_reason,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'attachments' => $this->relationLoaded('attachments')
                ? $this->attachments->map->toPayload()->all()
                : [],
        ];
    }
}
