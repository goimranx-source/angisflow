<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Models;

use App\Domain\Shared\Concerns\HasPublicId;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The thing that is actually stocked, priced, scanned and sold.
 *
 * Everything downstream — stock, order lines, barcodes, the till — points here
 * and not at Product. A product is a description; this is the item.
 */
class ProductVariant extends Model
{
    use BelongsToBusiness, HasPublicId, SoftDeletes;

    protected $attributes = [
        'price_minor' => 0, 'cost_minor' => 0, 'unit' => 'pcs',
        'is_default' => false, 'is_active' => true, 'position' => 1,
    ];

    protected $fillable = [
        'public_id', 'account_id', 'business_id', 'product_id',
        'sku', 'barcode', 'name', 'price_minor', 'compare_at_minor', 'cost_minor',
        'currency', 'weight_grams', 'unit', 'pack_quantity',
        'is_default', 'is_active', 'position',
    ];

    protected function casts(): array
    {
        return [
            'price_minor' => 'integer',
            'compare_at_minor' => 'integer',
            'cost_minor' => 'integer',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }

    /**
     * Pictures belonging to this variant rather than to the product.
     *
     * A product with four colours has four pictures, and the one that goes
     * against a line saying "blue" is the blue one. Empty for most variants,
     * which fall back to the product's own — see LinePresentation.
     */
    public function media(): HasMany
    {
        return $this->hasMany(ProductMedia::class, 'product_variant_id')->orderBy('position');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function optionValues(): BelongsToMany
    {
        return $this->belongsToMany(ProductOptionValue::class, 'variant_option_values');
    }

    public function price(): Money
    {
        return new Money($this->price_minor, $this->currency);
    }

    public function cost(): Money
    {
        return new Money($this->cost_minor, $this->currency);
    }

    /**
     * What is made on one unit, before anything else is taken off.
     *
     * Null when there is no cost recorded, rather than the price — a margin of
     * 100% on a product nobody has costed is a number that will be believed.
     */
    public function margin(): ?Money
    {
        return $this->cost_minor === 0 ? null : $this->price()->minus($this->cost());
    }

    public function marginPercent(): ?float
    {
        return $this->cost_minor === 0 || $this->price_minor === 0
            ? null
            : round(($this->price_minor - $this->cost_minor) / $this->price_minor * 100, 2);
    }

    /** "Small / Red", or the product's name when there are no options. */
    public function label(): string
    {
        return $this->name ?: ($this->product?->name ?? $this->sku);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'id' => $this->public_id,
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'name' => $this->name,
            'price' => $this->price()->jsonSerialize(),
            'cost' => $this->cost()->jsonSerialize(),
            'margin_pct' => $this->marginPercent(),
            'unit' => $this->unit,
            'is_default' => $this->is_default,
            'is_active' => $this->is_active,
            'options' => $this->relationLoaded('optionValues')
                ? $this->optionValues->pluck('value')->all()
                : [],
        ];
    }
}
