<?php

declare(strict_types=1);

namespace App\Domain\Billing\Models;

use App\Domain\Shared\Concerns\HasPublicId;
use App\Domain\Tenancy\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a subscriber is currently on, and until when.
 *
 * Separate from the account's own `status` on purpose. The account status is
 * what the application asks ("may this person work today?") and is read on
 * every single request; the subscription is the commercial record behind it,
 * read when somebody opens billing. Keeping them apart means the hot path never
 * touches this table, and the future admin panel can rewrite a subscription
 * without the app having to reason about payment states it does not care about.
 */
class Subscription extends Model
{
    use BelongsToAccount, HasPublicId;

    protected $fillable = [
        'public_id',
        'account_id',
        'plan_id',
        'status',
        'quantity',
        'started_at',
        'trial_ends_at',
        'current_period_start',
        'current_period_end',
        'cancel_at_period_end',
        'cancelled_at',
        'ended_at',
        'provider',
        'provider_subscription_id',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'started_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'cancelled_at' => 'datetime',
            'ended_at' => 'datetime',
            'cancel_at_period_end' => 'boolean',
            'meta' => 'array',
        ];
    }

    public const STATUS_TRIALING = 'trialing';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAST_DUE = 'past_due';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function isLive(): bool
    {
        return in_array($this->status, [
            self::STATUS_TRIALING,
            self::STATUS_ACTIVE,
            self::STATUS_PAST_DUE,
        ], true);
    }
}
