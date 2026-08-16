<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Models;

use App\Domain\Media\Models\MediaItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A picture, of the product or of one particular variant of it. */
class ProductMedia extends Model
{
    protected $table = 'product_media';

    protected $attributes = ['position' => 1];

    protected $fillable = ['product_id', 'product_variant_id', 'media_item_id', 'alt', 'position'];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function mediaItem(): BelongsTo
    {
        return $this->belongsTo(MediaItem::class);
    }
}
