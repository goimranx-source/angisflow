<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Models;

use App\Domain\Shared\Concerns\HasPublicId;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Somewhere people message from.
 *
 * The reply window is the field that matters most on this table. Nearly every
 * platform only lets you answer freely within some hours of the customer's last
 * message, and the limit differs — so an agent has to be told before they type
 * a reply that will silently fail to send.
 */
class InboxChannel extends Model
{
    use BelongsToBusiness, HasPublicId;

    public const WHATSAPP = 'whatsapp';
    public const MESSENGER = 'messenger';
    public const INSTAGRAM = 'instagram';
    public const TIKTOK = 'tiktok';
    public const SMS = 'sms';
    public const EMAIL = 'email';
    public const WEBCHAT = 'webchat';

    protected $attributes = ['status' => 'active', 'is_default' => false];

    protected $fillable = [
        'public_id', 'account_id', 'business_id', 'kind', 'name', 'external_id',
        'credential_ref', 'webhook_secret', 'settings', 'status',
        'last_seen_at', 'last_error', 'reply_window_hours', 'is_default',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'last_seen_at' => 'datetime',
            'reply_window_hours' => 'integer',
            'is_default' => 'boolean',
        ];
    }

    protected $hidden = ['webhook_secret', 'credential_ref'];

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    /**
     * Which identity kind a handle on this channel is.
     *
     * A WhatsApp number is a phone number and should find the customer who
     * bought something over the counter last month. Without this mapping every
     * channel creates its own stranger.
     */
    public function identityKind(): string
    {
        return match ($this->kind) {
            self::WHATSAPP => 'whatsapp',
            self::SMS => 'phone',
            self::EMAIL => 'email',
            self::MESSENGER => 'messenger',
            self::INSTAGRAM => 'instagram',
            self::TIKTOK => 'tiktok',
            default => 'external',
        };
    }

    public function isUsable(): bool
    {
        return $this->status === 'active';
    }

    public function scopeUsable(Builder $q): Builder
    {
        return $q->where('status', 'active');
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'id' => $this->public_id,
            'kind' => $this->kind,
            'name' => $this->name,
            'status' => $this->status,
            'reply_window_hours' => $this->reply_window_hours,
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'last_error' => $this->last_error,
        ];
    }
}
