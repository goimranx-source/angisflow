<?php

declare(strict_types=1);

namespace App\Domain\Production\Models;

use App\Domain\Catalogue\Models\ProductVariant;
use App\Domain\Shared\Concerns\HasPublicId;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A recipe: what goes in, and how much comes out.
 *
 * A plan rather than a record. What a particular run actually consumed lives on
 * the production order, and the two are allowed to differ — the difference is
 * the variance, and it is the number worth looking at.
 */
class BillOfMaterials extends Model
{
    use BelongsToBusiness, HasPublicId;

    protected $table = 'bills_of_materials';

    protected $attributes = [
        'version' => 1,
        'output_quantity' => 1,
        'labour_cost_minor' => 0,
        'overhead_cost_minor' => 0,
        'is_active' => true,
    ];

    protected $fillable = [
        'public_id', 'account_id', 'business_id', 'product_variant_id',
        'name', 'version', 'output_quantity', 'labour_account_id',
        'labour_cost_minor', 'overhead_cost_minor', 'currency', 'notes', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'labour_cost_minor' => 'integer',
            'overhead_cost_minor' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function output(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function components(): HasMany
    {
        return $this->hasMany(BomComponent::class, 'bill_of_materials_id')->orderBy('line_no');
    }

    public function labourCost(): Money
    {
        return new Money($this->labour_cost_minor, $this->currency);
    }

    public function overheadCost(): Money
    {
        return new Money($this->overhead_cost_minor, $this->currency);
    }

    /**
     * What one run should cost, at the components' standard costs.
     *
     * An estimate, explicitly. Real cost comes from the batches actually
     * consumed and is only known once the run has happened — which is why
     * production orders carry their own figures rather than reading these.
     */
    public function estimatedCost(): Money
    {
        $this->loadMissing('components.variant');

        $materials = 0;

        foreach ($this->components as $component) {
            $materials += (int) round(
                $component->variant->cost_minor * $component->quantityWithScrap(),
            );
        }

        return new Money($materials + $this->labour_cost_minor + $this->overhead_cost_minor, $this->currency);
    }

    /** The estimate divided by what one run yields. */
    public function estimatedUnitCost(): Money
    {
        $output = (float) $this->output_quantity;

        return $output <= 0
            ? new Money(0, $this->currency)
            : new Money((int) round($this->estimatedCost()->minor / $output), $this->currency);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'version' => $this->version,
            'output_quantity' => (float) $this->output_quantity,
            'labour' => $this->labourCost()->jsonSerialize(),
            'overhead' => $this->overheadCost()->jsonSerialize(),
            'estimated_cost' => $this->estimatedCost()->jsonSerialize(),
            'estimated_unit_cost' => $this->estimatedUnitCost()->jsonSerialize(),
            'is_active' => $this->is_active,
            'components' => $this->relationLoaded('components')
                ? $this->components->map->toPayload()->all()
                : [],
        ];
    }
}
