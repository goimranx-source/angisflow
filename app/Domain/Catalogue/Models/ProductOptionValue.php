<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** One position on an axis — Small, Red, Cotton. */
class ProductOptionValue extends Model
{
    protected $attributes = ['position' => 1];

    protected $fillable = ['product_option_id', 'value', 'position'];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    public function option(): BelongsTo
    {
        return $this->belongsTo(ProductOption::class, 'product_option_id');
    }

    public function variants(): BelongsToMany
    {
        return $this->belongsToMany(ProductVariant::class, 'variant_option_values');
    }
}
