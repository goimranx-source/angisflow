<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Models;

use App\Domain\Shared\Concerns\HasPublicId;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * How the catalogue is filed.
 *
 * A tree, because retail categories are one — Menswear > Shirts > Formal — and
 * flattening it means either one long list nobody can scan or a made-up
 * two-level limit that some business immediately needs three of.
 */
class ProductCategory extends Model
{
    use BelongsToBusiness, HasPublicId;

    protected $attributes = ['is_active' => true, 'sort_order' => 0];

    protected $fillable = [
        'public_id', 'account_id', 'business_id', 'parent_id',
        'name', 'slug', 'description', 'sort_order', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'category_id');
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'children' => $this->relationLoaded('children') ? $this->children->map->toPayload()->all() : [],
        ];
    }
}
