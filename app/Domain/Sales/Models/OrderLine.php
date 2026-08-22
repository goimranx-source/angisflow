<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Domain\Catalogue\Models\ProductVariant;
use App\Domain\Shared\ValueObjects\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing on an order.
 *
 * The description and SKU are copied at the moment of ordering rather than read
 * from the catalogue afterwards. A customer bought a particular thing at a
 * particular price; renaming the product or repricing it next month must not
 * rewrite what they were sold. The variant link stays for stock and reporting,
 * and may be null — a one-off line for something not in the catalogue is a
 * normal thing to sell.
 */
class OrderLine extends Model
{
    protected $attributes = [
        'line_no' => 1,
        'quantity_fulfilled' => 0,
        'unit_price_minor' => 0,
        'discount_minor' => 0,
        'tax_rate' => 0,
        'tax_minor' => 0,
        'total_minor' => 0,
        'cost_minor' => 0,
    ];

    protected $fillable = [
        'order_id', 'product_variant_id', 'line_no', 'external_id', 'sku', 'description',
        'quantity', 'quantity_fulfilled', 'unit_price_minor', 'discount_minor',
        'tax_rate', 'tax_minor', 'total_minor', 'cost_minor', 'currency',
        'stock_reservation_id',
    ];

    protected function casts(): array
    {
        return [
            'line_no' => 'integer',
            'unit_price_minor' => 'integer',
            'discount_minor' => 'integer',
            'tax_minor' => 'integer',
            'total_minor' => 'integer',
            'cost_minor' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function total(): Money
    {
        return new Money($this->total_minor, $this->currency);
    }

    public function net(): Money
    {
        return new Money($this->total_minor - $this->tax_minor, $this->currency);
    }

    public function cost(): Money
    {
        return new Money($this->cost_minor, $this->currency);
    }

    public function outstandingQuantity(): float
    {
        return max(0, (float) $this->quantity - (float) $this->quantity_fulfilled);
    }

    public function isFulfilled(): bool
    {
        return $this->outstandingQuantity() <= 0.00005;
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'line_no' => $this->line_no,
            'sku' => $this->sku,
            'description' => $this->description,
            'quantity' => (float) $this->quantity,
            'quantity_fulfilled' => (float) $this->quantity_fulfilled,
            'unit_price' => (new Money($this->unit_price_minor, $this->currency))->jsonSerialize(),
            'tax' => (new Money($this->tax_minor, $this->currency))->jsonSerialize(),
            'total' => $this->total()->jsonSerialize(),
            'cost' => $this->cost()->jsonSerialize(),
        ];
    }
}
