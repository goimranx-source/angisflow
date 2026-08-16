<?php

declare(strict_types=1);

namespace App\Domain\Stock\Models;

use App\Domain\Shared\Concerns\HasPublicId;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A claim on stock that has not moved yet.
 *
 * Kept after release rather than deleted, so "why was this not available on
 * Tuesday" has an answer.
 */
class StockReservation extends Model
{
    use BelongsToBusiness, HasPublicId;

    public const HELD = 'held';

    public const RELEASED = 'released';

    public const CONSUMED = 'consumed';

    protected $attributes = ['status' => self::HELD];

    protected $fillable = [
        'public_id', 'account_id', 'business_id', 'product_variant_id',
        'stock_location_id', 'quantity', 'status', 'subject_type', 'subject_id',
        'expires_at', 'released_at', 'created_by',
    ];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'released_at' => 'datetime'];
    }

    public function scopeHeld(Builder $q): Builder
    {
        return $q->where('status', self::HELD);
    }

    /** Held, but past the moment it was promised until. */
    public function scopeStale(Builder $q): Builder
    {
        return $q->held()->whereNotNull('expires_at')->where('expires_at', '<', now());
    }
}
