<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Models;

use App\Domain\Shared\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A carrier.
 *
 * A row rather than a class, because most of the world's couriers are ones we
 * will never write code for. account_id null means we ship it for everybody;
 * set means a subscriber added their own — which includes the man with a van,
 * who is a courier and has nowhere else to live.
 */
class Courier extends Model
{
    use HasPublicId;

    protected $attributes = [
        'adapter' => 'generic',
        'supports_cod' => true,
        'supports_pickup_request' => false,
        'is_active' => true,
    ];

    protected $fillable = [
        'public_id', 'account_id', 'name', 'slug', 'adapter', 'country',
        'website', 'tracking_url_template', 'supports_cod',
        'supports_pickup_request', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'supports_cod' => 'boolean',
            'supports_pickup_request' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function connections(): HasMany
    {
        return $this->hasMany(CourierConnection::class);
    }

    /** Where a customer can watch their parcel, if this courier offers such a page. */
    public function trackingUrl(?string $trackingNumber): ?string
    {
        if ($trackingNumber === null || $this->tracking_url_template === null) {
            return null;
        }

        return str_replace('{tracking_number}', urlencode($trackingNumber), $this->tracking_url_template);
    }

    /** The shared ones, plus this account's own. */
    public function scopeAvailableTo(Builder $q, ?int $accountId): Builder
    {
        return $q->where('is_active', true)
            ->where(fn ($w) => $w->whereNull('account_id')->orWhere('account_id', $accountId));
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'country' => $this->country,
            'supports_cod' => $this->supports_cod,
            'is_own' => $this->account_id !== null,
        ];
    }
}
