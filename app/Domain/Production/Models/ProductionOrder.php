<?php

declare(strict_types=1);

namespace App\Domain\Production\Models;

use App\Domain\Catalogue\Models\ProductVariant;
use App\Domain\Shared\Concerns\HasPublicId;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Stock\Models\StockLocation;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One run of a recipe.
 *
 * ── Why there are five states and not two ────────────────────────────────────
 *
 * "Making" and "made" is not enough, because the interesting moments are the
 * ones in between. Releasing an order reserves its components — which is what
 * stops the same flour being promised to two runs and to a customer. Starting it
 * consumes them, which is when they leave stock and the money moves. Completing
 * it receives the output at whatever it turned out to cost.
 *
 * A system that only knows "making" cannot answer whether the materials for
 * tomorrow's run are actually there, which is the question the person planning
 * it is asking.
 */
class ProductionOrder extends Model
{
    use BelongsToBusiness, HasPublicId;

    public const PLANNED = 'planned';

    public const RELEASED = 'released';

    public const STARTED = 'started';

    public const COMPLETED = 'completed';

    public const CANCELLED = 'cancelled';

    protected $attributes = [
        'status' => self::PLANNED,
        'quantity_produced' => 0,
        'quantity_scrapped' => 0,
        'material_cost_minor' => 0,
        'labour_cost_minor' => 0,
        'overhead_cost_minor' => 0,
        'unit_cost_minor' => 0,
    ];

    protected $fillable = [
        'public_id', 'account_id', 'business_id', 'bill_of_materials_id',
        'product_variant_id', 'stock_location_id', 'number', 'status',
        'quantity_planned', 'quantity_produced', 'quantity_scrapped',
        'planned_for', 'started_at', 'completed_at',
        'material_cost_minor', 'labour_cost_minor', 'overhead_cost_minor',
        'unit_cost_minor', 'currency', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'planned_for' => 'date',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'material_cost_minor' => 'integer',
            'labour_cost_minor' => 'integer',
            'overhead_cost_minor' => 'integer',
            'unit_cost_minor' => 'integer',
        ];
    }

    public function bom(): BelongsTo
    {
        return $this->belongsTo(BillOfMaterials::class, 'bill_of_materials_id');
    }

    public function output(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'stock_location_id');
    }

    public function components(): HasMany
    {
        return $this->hasMany(ProductionOrderComponent::class)->orderBy('line_no');
    }

    public function totalCost(): Money
    {
        return new Money(
            $this->material_cost_minor + $this->labour_cost_minor + $this->overhead_cost_minor,
            $this->currency,
        );
    }

    public function unitCost(): Money
    {
        return new Money($this->unit_cost_minor, $this->currency);
    }

    /**
     * How much of what was made was good.
     *
     * Null rather than 100% when nothing has been made yet — a run that has not
     * started has no yield, and reporting a perfect one is a lie that will be
     * averaged into something.
     */
    public function yieldPercent(): ?float
    {
        $made = (float) $this->quantity_produced + (float) $this->quantity_scrapped;

        return $made <= 0 ? null : round((float) $this->quantity_produced / $made * 100, 2);
    }

    public function isOpen(): bool
    {
        return ! in_array($this->status, [self::COMPLETED, self::CANCELLED], true);
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereNotIn('status', [self::COMPLETED, self::CANCELLED]);
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'id' => $this->public_id,
            'number' => $this->number,
            'status' => $this->status,
            'planned' => (float) $this->quantity_planned,
            'produced' => (float) $this->quantity_produced,
            'scrapped' => (float) $this->quantity_scrapped,
            'yield_pct' => $this->yieldPercent(),
            'material_cost' => (new Money($this->material_cost_minor, $this->currency))->jsonSerialize(),
            'total_cost' => $this->totalCost()->jsonSerialize(),
            'unit_cost' => $this->unitCost()->jsonSerialize(),
            'planned_for' => $this->planned_for?->toDateString(),
            'output' => $this->relationLoaded('output') ? $this->output?->toPayload() : null,
            'components' => $this->relationLoaded('components') ? $this->components->map->toPayload()->all() : [],
        ];
    }
}
