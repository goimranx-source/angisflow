<?php

declare(strict_types=1);

namespace App\Domain\Stock\Models;

use App\Domain\Catalogue\Models\ProductVariant;
use App\Domain\Shared\Concerns\HasPublicId;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A quantity that arrived together, at one cost.
 *
 * The unit of costing and the unit of recall. The same shirt bought twice cost
 * two different amounts, and "which customers got lot 4471" is a question only
 * a batch can answer.
 */
class StockBatch extends Model
{
    use BelongsToBusiness, HasPublicId;

    protected $attributes = [
        'quantity_received' => 0,
        'quantity_remaining' => 0,
        'unit_cost_minor' => 0,
        'is_active' => true,
    ];

    protected $fillable = [
        'public_id', 'account_id', 'business_id', 'product_variant_id',
        'batch_number', 'supplier_lot', 'received_on', 'expires_on',
        'quantity_received', 'quantity_remaining', 'unit_cost_minor', 'currency',
        'supplier_id', 'bill_id', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'received_on' => 'date',
            'expires_on' => 'date',
            'unit_cost_minor' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function unitCost(): Money
    {
        return new Money($this->unit_cost_minor, $this->currency);
    }

    /** What remains in this batch is worth this much. */
    public function remainingValue(): Money
    {
        return new Money(
            (int) round($this->unit_cost_minor * (float) $this->quantity_remaining),
            $this->currency,
        );
    }

    public function isExpired(?string $asAt = null): bool
    {
        return $this->expires_on !== null
            && $this->expires_on->toDateString() < ($asAt ?? now()->toDateString());
    }

    public function scopeAvailable(Builder $q): Builder
    {
        return $q->where('is_active', true)->where('quantity_remaining', '>', 0);
    }

    /**
     * First-expiring, then oldest.
     *
     * FEFO rather than plain FIFO, because for anything perishable the two
     * differ and when they differ FIFO is the one that throws stock away.
     * Batches with no expiry sort last, so dated stock always goes first.
     */
    public function scopeFefo(Builder $q): Builder
    {
        return $q->orderByRaw('CASE WHEN expires_on IS NULL THEN 1 ELSE 0 END')
            ->orderBy('expires_on')
            ->orderBy('received_on')
            ->orderBy('id');
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'id' => $this->public_id,
            'batch_number' => $this->batch_number,
            'supplier_lot' => $this->supplier_lot,
            'received_on' => $this->received_on?->toDateString(),
            'expires_on' => $this->expires_on?->toDateString(),
            'remaining' => (float) $this->quantity_remaining,
            'unit_cost' => $this->unitCost()->jsonSerialize(),
            'value' => $this->remainingValue()->jsonSerialize(),
            'is_expired' => $this->isExpired(),
        ];
    }
}
