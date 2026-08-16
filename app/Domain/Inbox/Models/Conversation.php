<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Models;

use App\Domain\Sales\Models\Customer;
use App\Domain\Shared\Concerns\HasPublicId;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One thread with one person on one channel.
 *
 * Per channel, not per person: the same customer's WhatsApp and Instagram
 * threads are genuinely different conversations with different histories and
 * different reply windows. They meet on the customer record, which is where
 * somebody actually wants to see them together.
 */
class Conversation extends Model
{
    use BelongsToBusiness, HasPublicId;

    public const OPEN = 'open';

    public const PENDING = 'pending';

    public const SNOOZED = 'snoozed';

    public const CLOSED = 'closed';

    protected $attributes = [
        'status' => self::OPEN,
        'priority' => 'normal',
        'unread_count' => 0,
        'message_count' => 0,
    ];

    protected $fillable = [
        'public_id', 'account_id', 'business_id', 'inbox_channel_id', 'customer_id',
        'contact_handle', 'contact_name', 'external_id', 'subject', 'status', 'priority',
        'assigned_to', 'assigned_at', 'snoozed_until',
        'last_message_at', 'last_message_preview', 'last_message_direction',
        'unread_count', 'message_count', 'customer_last_at', 'first_response_seconds',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'snoozed_until' => 'datetime',
            'last_message_at' => 'datetime',
            'customer_last_at' => 'datetime',
            'closed_at' => 'datetime',
            'unread_count' => 'integer',
            'message_count' => 'integer',
            'first_response_seconds' => 'integer',
        ];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(InboxChannel::class, 'inbox_channel_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->orderBy('occurred_at');
    }

    /**
     * Whether a free-form reply can still be sent.
     *
     * Measured from the customer's last message, never from ours — answering
     * does not buy more time to answer, and a system that thinks it does lets
     * agents type replies that vanish.
     */
    public function canReplyFreely(): bool
    {
        $hours = $this->channel?->reply_window_hours;

        if ($hours === null) {
            return true;
        }

        return $this->customer_last_at !== null
            && $this->customer_last_at->gt(now()->subHours($hours));
    }

    /** How long is left, in minutes, or null where there is no limit. */
    public function replyWindowRemaining(): ?int
    {
        $hours = $this->channel?->reply_window_hours;

        if ($hours === null || $this->customer_last_at === null) {
            return null;
        }

        $closes = $this->customer_last_at->copy()->addHours($hours);

        return $closes->isPast() ? 0 : (int) now()->diffInMinutes($closes);
    }

    /** How long the customer has been waiting for a reply, if they are. */
    public function waitingSeconds(): ?int
    {
        return $this->last_message_direction === 'in' && $this->customer_last_at !== null
            ? (int) $this->customer_last_at->diffInSeconds(now())
            : null;
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::OPEN, self::PENDING], true);
    }

    public function scopeNeedsReply(Builder $q): Builder
    {
        return $q->whereIn('status', [self::OPEN, self::PENDING])
            ->where('last_message_direction', 'in');
    }

    /** Snoozed conversations whose time is up, so they come back. */
    public function scopeDueBack(Builder $q): Builder
    {
        return $q->where('status', self::SNOOZED)
            ->whereNotNull('snoozed_until')
            ->where('snoozed_until', '<=', now());
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'id' => $this->public_id,
            'contact_name' => $this->contact_name ?? $this->contact_handle,
            'contact_handle' => $this->contact_handle,
            'status' => $this->status,
            'priority' => $this->priority,
            'unread_count' => $this->unread_count,
            'message_count' => $this->message_count,
            'last_message_at' => $this->last_message_at?->toIso8601String(),
            'last_message_preview' => $this->last_message_preview,
            'last_message_direction' => $this->last_message_direction,
            'waiting_seconds' => $this->waitingSeconds(),
            'can_reply_freely' => $this->canReplyFreely(),
            'reply_window_minutes_left' => $this->replyWindowRemaining(),
            'first_response_seconds' => $this->first_response_seconds,
            'channel' => $this->relationLoaded('channel') ? $this->channel?->toPayload() : null,
            'customer' => $this->relationLoaded('customer') ? $this->customer?->toPayload() : null,
        ];
    }
}
