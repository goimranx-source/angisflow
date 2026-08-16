<?php

declare(strict_types=1);

namespace App\Domain\Billing\Models;

use App\Domain\Shared\Concerns\HasPublicId;
use App\Domain\Shared\ValueObjects\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What a subscriber can buy.
 *
 * Platform-wide rather than per-account — plans are the product, not a
 * subscriber's data — which is why this is the one model here with no
 * account_id and no tenant scope.
 *
 * Limits live as a JSON map rather than a column each. A new limit is then a
 * deploy of the code that enforces it and nothing else; a column per limit
 * means an ALTER TABLE on a table every request touches, every time pricing
 * changes. Pricing changes more often than anyone expects.
 */
class Plan extends Model
{
    use HasPublicId;

    protected $fillable = [
        'public_id',
        'code',
        'name',
        'description',
        'price_minor',
        'currency',
        'interval',
        'trial_days',
        'limits',
        'features',
        'is_public',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price_minor' => 'integer',
            'trial_days' => 'integer',
            'limits' => 'array',
            'features' => 'array',
            'is_public' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public const INTERVAL_MONTHLY = 'monthly';

    public const INTERVAL_YEARLY = 'yearly';

    /**
     * Limits every plan is assumed to answer, with the value meaning
     * "unlimited" spelled out rather than left as a magic zero.
     */
    public const UNLIMITED = -1;

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function price(): Money
    {
        return Money::of($this->price_minor, $this->currency);
    }

    public function limit(string $key, int $default = self::UNLIMITED): int
    {
        $value = $this->limits[$key] ?? $default;

        return is_numeric($value) ? (int) $value : $default;
    }

    public function allows(string $feature): bool
    {
        return in_array($feature, $this->features ?? [], true);
    }
}
