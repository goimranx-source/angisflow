<?php

declare(strict_types=1);

namespace App\Domain\Crm\Models;

use App\Domain\Catalogue\Models\ProductVariant;
use App\Domain\Shared\ValueObjects\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One thing being offered, at a price that was true when it was sent. */
class QuoteLine extends Model
{
    protected $attributes = [
        'line_no' => 1, 'quantity' => 1, 'unit_price_minor' => 0,
        'discount_minor' => 0, 'tax_rate' => 0, 'tax_minor' => 0, 'total_minor' => 0,
    ];

    protected $fillable = [
        'quote_id', 'product_variant_id', 'line_no', 'description', 'quantity',
        'unit_price_minor', 'discount_minor', 'tax_rate', 'tax_minor', 'total_minor', 'currency',
    ];

    protected function casts(): array
    {
        return [
            'line_no' => 'integer', 'unit_price_minor' => 'integer',
            'discount_minor' => 'integer', 'tax_minor' => 'integer', 'total_minor' => 'integer',
        ];
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function total(): Money
    {
        return new Money($this->total_minor, $this->currency);
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'line_no' => $this->line_no,
            'description' => $this->description,
            'quantity' => (float) $this->quantity,
            'unit_price' => (new Money($this->unit_price_minor, $this->currency))->jsonSerialize(),
            'total' => $this->total()->jsonSerialize(),
        ];
    }
}
