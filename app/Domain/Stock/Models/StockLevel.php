<?php

declare(strict_types=1);

namespace App\Domain\Stock\Models;

use App\Domain\Catalogue\Models\ProductVariant;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How much of one variant is in one place.
 *
 * on_hand is a cache of the movements and can be rebuilt from them.
 * reserved cannot — see the migration.
 */
class StockLevel extends Model
{
    // Tenant-scoped like everything else. Without it account_id is never
    // stamped, and a table with a NOT NULL tenant key simply refuses the
    // insert — which is the right failure, but only once somebody hits it.
    use BelongsToBusiness;

    protected $attributes = ['on_hand' => 0, 'reserved' => 0];

    protected $fillable = [
        'account_id', 'business_id', 'product_variant_id', 'stock_location_id',
        'on_hand', 'reserved', 'reorder_level', 'reorder_quantity', 'counted_at',
    ];

    protected function casts(): array
    {
        return ['counted_at' => 'datetime'];
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'stock_location_id');
    }

    /**
     * What can still be promised to somebody.
     *
     * Never negative. Over-reserving past what is on hand is possible — a
     * backorder, an oversell — and reporting available as a negative number
     * invites a caller to add it to another location's positive one and
     * conclude there is enough.
     */
    public function available(): float
    {
        return max(0, (float) $this->on_hand - (float) $this->reserved);
    }

    public function needsReorder(): bool
    {
        return $this->reorder_level !== null && $this->available() <= (float) $this->reorder_level;
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'on_hand' => (float) $this->on_hand,
            'reserved' => (float) $this->reserved,
            'available' => $this->available(),
            'reorder_level' => $this->reorder_level === null ? null : (float) $this->reorder_level,
            'needs_reorder' => $this->needsReorder(),
            'location' => $this->relationLoaded('location') ? $this->location?->toPayload() : null,
        ];
    }
}
