<?php

declare(strict_types=1);

namespace App\Domain\Production\Models;

use App\Domain\Catalogue\Models\ProductVariant;
use App\Domain\Shared\ValueObjects\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one run was supposed to use, and what it did.
 *
 * Copied from the recipe when the order is released and never re-read from it
 * after — the record of what the plan was at the time, which is the only thing
 * a variance can be measured against.
 */
class ProductionOrderComponent extends Model
{
    protected $attributes = [
        'line_no' => 1, 'quantity_consumed' => 0, 'scrap_percent' => 0,
        'is_optional' => false, 'cost_minor' => 0,
    ];

    protected $fillable = [
        'production_order_id', 'product_variant_id', 'line_no',
        'quantity_planned', 'quantity_consumed', 'scrap_percent', 'is_optional',
        'cost_minor', 'currency', 'stock_reservation_id',
    ];

    protected function casts(): array
    {
        return ['line_no' => 'integer', 'is_optional' => 'boolean', 'cost_minor' => 'integer'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class, 'production_order_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function cost(): Money
    {
        return new Money($this->cost_minor, $this->currency);
    }

    /** Used more than planned is positive; under is negative. */
    public function variance(): float
    {
        return round((float) $this->quantity_consumed - (float) $this->quantity_planned, 4);
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'line_no' => $this->line_no,
            'planned' => (float) $this->quantity_planned,
            'consumed' => (float) $this->quantity_consumed,
            'variance' => $this->variance(),
            'cost' => $this->cost()->jsonSerialize(),
            'variant' => $this->relationLoaded('variant') ? $this->variant?->toPayload() : null,
        ];
    }
}
