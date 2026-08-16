<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Models;

use App\Domain\Shared\Concerns\HasPublicId;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One visitor's chat session.
 *
 * The token is stored hashed, for the same reason a password is: a leaked
 * backup of this table must not hand somebody every live conversation on the
 * platform.
 */
class WebchatSession extends Model
{
    use BelongsToBusiness, HasPublicId;

    protected $fillable = [
        'public_id', 'account_id', 'business_id', 'webchat_widget_id', 'conversation_id',
        'token_hash', 'visitor_key', 'name', 'email', 'phone',
        'page_url', 'page_title', 'referrer', 'user_agent', 'ip_address', 'country',
        'started_at', 'last_seen_at', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    protected $hidden = ['token_hash'];

    public function widget(): BelongsTo
    {
        return $this->belongsTo(WebchatWidget::class, 'webchat_widget_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public function isLive(): bool
    {
        return $this->expires_at === null || $this->expires_at->isFuture();
    }
}
