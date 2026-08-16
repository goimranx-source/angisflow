<?php

declare(strict_types=1);

namespace App\Domain\Production\Models;

use App\Domain\Catalogue\Models\ProductVariant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One thing a recipe needs, and how much of it. */
class BomComponent extends Model
{
    protected $attributes = ['line_no' => 1, 'scrap_percent' => 0, 'is_optional' => false];

    protected $fillable = [
        'bill_of_materials_id', 'product_variant_id', 'line_no',
        'quantity', 'scrap_percent', 'is_optional', 'notes',
    ];

    protected function casts(): array
    {
        return ['line_no' => 'integer', 'is_optional' => 'boolean'];
    }

    public function bom(): BelongsTo
    {
        return $this->belongsTo(BillOfMaterials::class, 'bill_of_materials_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /**
     * How much has to be issued to end up with the quantity the recipe needs.
     *
     * Scrap is what is lost in the making, so it is added on rather than taken
     * off: a step that wastes 10% of what it touches needs 100/90 of the
     * finished requirement, not 110% of it. The difference is small at 10% and
     * not small at 40%, and getting it the wrong way round means every run
     * comes up short at the last step.
     */
    public function quantityWithScrap(): float
    {
        $scrap = (float) $this->scrap_percent;

        if ($scrap <= 0 || $scrap >= 100) {
            return (float) $this->quantity;
        }

        return round((float) $this->quantity / (1 - $scrap / 100), 4);
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'line_no' => $this->line_no,
            'quantity' => (float) $this->quantity,
            'quantity_with_scrap' => $this->quantityWithScrap(),
            'scrap_percent' => (float) $this->scrap_percent,
            'is_optional' => $this->is_optional,
            'variant' => $this->relationLoaded('variant') ? $this->variant?->toPayload() : null,
        ];
    }
}
