<?php

declare(strict_types=1);

namespace App\Domain\Returns\Models;

use App\Domain\Catalogue\Models\ProductVariant;
use App\Domain\Sales\Models\OrderLine;
use App\Domain\Shared\ValueObjects\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing coming back, and what state it is in.
 *
 * Condition is per line because a three-item return where one is resaleable,
 * one is damaged and one never arrived is entirely ordinary. A single condition
 * for the whole return means either writing off good stock or putting broken
 * stock back on the shelf — and both are found later by a customer.
 */
class GoodsReturnLine extends Model
{
    public const RESALEABLE = 'resaleable';
    public const DAMAGED = 'damaged';
    public const MISSING = 'missing';

    protected $attributes = [
        'line_no' => 1, 'condition' => self::RESALEABLE,
        'unit_price_minor' => 0, 'refund_minor' => 0, 'cost_minor' => 0,
    ];

    protected $fillable = [
        'goods_return_id', 'order_line_id', 'product_variant_id', 'line_no',
        'description', 'quantity', 'condition', 'unit_price_minor',
        'refund_minor', 'cost_minor', 'currency', 'note',
    ];

    protected function casts(): array
    {
        return [
            'line_no' => 'integer',
            'unit_price_minor' => 'integer',
            'refund_minor' => 'integer',
            'cost_minor' => 'integer',
        ];
    }

    public function return(): BelongsTo
    {
        return $this->belongsTo(GoodsReturn::class, 'goods_return_id');
    }

    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(OrderLine::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /** Whether these units go back into stock at all. */
    public function goesBackToStock(): bool
    {
        return $this->condition !== self::MISSING;
    }

    /** Whether they can be sold again, or only quarantined. */
    public function isResaleable(): bool
    {
        return $this->condition === self::RESALEABLE;
    }

    public function refund(): Money
    {
        return new Money($this->refund_minor, $this->currency);
    }

    public function cost(): Money
    {
        return new Money($this->cost_minor, $this->currency);
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'line_no' => $this->line_no,
            'description' => $this->description,
            'quantity' => (float) $this->quantity,
            'condition' => $this->condition,
            'refund' => $this->refund()->jsonSerialize(),
            'cost' => $this->cost()->jsonSerialize(),
            'note' => $this->note,
        ];
    }
}
