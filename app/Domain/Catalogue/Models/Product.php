<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Models;

use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Shared\Concerns\HasPublicId;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Something the business sells, described.
 *
 * The description only. Nothing here is stocked, priced or scanned — those
 * belong to the variant, always, including for a product that has exactly one.
 * See the migration for why the "only some products have variants" shape was
 * rejected.
 */
class Product extends Model
{
    use BelongsToBusiness, HasPublicId, SoftDeletes;

    public const GOODS = 'goods';

    public const SERVICE = 'service';

    public const DIGITAL = 'digital';

    public const BUNDLE = 'bundle';

    /**
     * Something consumed to make what is sold, rather than sold itself.
     *
     * Flour in a kitchen, fabric in a workshop, brake pads in a garage. It is
     * bought, counted, and costed exactly like goods — so it is stocked, it
     * appears in purchasing, and a bill of materials draws it down.
     *
     * What separates it from goods is the other half: it is never offered for
     * sale. Modelling raw materials as goods is the shortcut that puts flour on
     * the online store and lets a customer add two kilos of it to a basket, so
     * the kind exists to keep the sales catalogue and the consumption catalogue
     * apart while both live in one products table.
     */
    public const RAW_MATERIAL = 'raw_material';

    /** Kinds that are counted. A service has no shelf. */
    public const STOCKED_KINDS = [self::GOODS, self::RAW_MATERIAL];

    /** Kinds a customer can actually buy. */
    public const SELLABLE_KINDS = [self::GOODS, self::SERVICE, self::DIGITAL, self::BUNDLE];

    protected $attributes = [
        'kind' => self::GOODS,
        'is_stocked' => true,
        'is_active' => true,
    ];

    protected $fillable = [
        'public_id', 'account_id', 'business_id', 'category_id',
        'name', 'slug', 'description', 'summary', 'brand', 'kind',
        'revenue_account_id', 'cogs_account_id', 'inventory_account_id',
        'tax_rate', 'is_stocked', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_stocked' => 'boolean', 'is_active' => 'boolean'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('position');
    }

    public function options(): HasMany
    {
        return $this->hasMany(ProductOption::class)->orderBy('position');
    }

    public function media(): HasMany
    {
        return $this->hasMany(ProductMedia::class)->orderBy('position');
    }

    public function revenueAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'revenue_account_id');
    }

    public function cogsAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'cogs_account_id');
    }

    /**
     * The variant to use when nobody has chosen one.
     *
     * A till scanning a product with one variant should not ask which; a
     * product page opens on something. Falls back to the first rather than
     * returning null, because a product with no variants at all is a state
     * ProductCatalogue does not allow to exist.
     */
    public function defaultVariant(): ?ProductVariant
    {
        $variants = $this->relationLoaded('variants') ? $this->variants : $this->variants()->get();

        return $variants->firstWhere('is_default', true) ?? $variants->first();
    }

    /**
     * Whether this is a product somebody would think of as having variants.
     *
     * For display only — a form deciding whether to draw the options editor.
     * No pricing, stock or ordering logic should ask this; they all deal in
     * variants regardless of how many there are.
     */
    public function hasRealOptions(): bool
    {
        return $this->options()->exists();
    }

    public function isStocked(): bool
    {
        return $this->is_stocked && in_array($this->kind, self::STOCKED_KINDS, true);
    }

    /** The cheapest and dearest of the active variants, for a "from £x" line. */
    public function priceRange(): ?array
    {
        $prices = ($this->relationLoaded('variants') ? $this->variants : $this->variants()->get())
            ->where('is_active', true)
            ->pluck('price_minor');

        if ($prices->isEmpty()) {
            return null;
        }

        $currency = $this->variants->first()->currency;

        return [
            'from' => new Money((int) $prices->min(), $currency),
            'to' => new Money((int) $prices->max(), $currency),
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeStocked(Builder $query): Builder
    {
        return $query->where('is_stocked', true)->whereIn('kind', self::STOCKED_KINDS);
    }

    /**
     * What may be put in front of a customer.
     *
     * Every list that a buyer sees — the storefront, the till, a quote — must
     * go through this. Raw materials are stocked and costed but are not for
     * sale, and the only thing keeping them out of the catalogue is this scope.
     */
    public function scopeSellable(Builder $query): Builder
    {
        return $query->whereIn('kind', self::SELLABLE_KINDS);
    }

    public function isSellable(): bool
    {
        return in_array($this->kind, self::SELLABLE_KINDS, true);
    }

    public function isRawMaterial(): bool
    {
        return $this->kind === self::RAW_MATERIAL;
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        $range = $this->priceRange();

        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'summary' => $this->summary,
            'brand' => $this->brand,
            'kind' => $this->kind,
            'is_stocked' => $this->isStocked(),
            'is_active' => $this->is_active,
            'category' => $this->relationLoaded('category') ? $this->category?->toPayload() : null,
            'price_from' => $range ? $range['from']->jsonSerialize() : null,
            'price_to' => $range ? $range['to']->jsonSerialize() : null,
            'variants' => $this->relationLoaded('variants') ? $this->variants->map->toPayload()->all() : [],
        ];
    }
}
