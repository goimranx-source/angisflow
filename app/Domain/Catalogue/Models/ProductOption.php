<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An axis a product varies along — Size, Colour, Material. */
class ProductOption extends Model
{
    protected $attributes = ['position' => 1];

    protected $fillable = ['product_id', 'name', 'position'];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function values(): HasMany
    {
        return $this->hasMany(ProductOptionValue::class)->orderBy('position');
    }
}
