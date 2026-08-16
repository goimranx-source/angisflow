<?php

declare(strict_types=1);

namespace App\Domain\Stock\Models;

use App\Domain\Shared\Concerns\HasPublicId;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Somewhere stock can be — a warehouse, a shop floor, a van, a quarantine bay. */
class StockLocation extends Model
{
    use BelongsToBusiness, HasPublicId;

    protected $attributes = [
        'kind' => 'warehouse', 'is_sellable' => true, 'is_default' => false, 'is_active' => true,
    ];

    protected $fillable = [
        'public_id', 'account_id', 'business_id', 'name', 'code', 'kind',
        'address', 'country', 'is_sellable', 'is_default', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_sellable' => 'boolean', 'is_default' => 'boolean', 'is_active' => 'boolean'];
    }

    public function levels(): HasMany
    {
        return $this->hasMany(StockLevel::class);
    }

    public function scopeSellable(Builder $q): Builder
    {
        return $q->where('is_sellable', true)->where('is_active', true);
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'id' => $this->public_id, 'name' => $this->name, 'code' => $this->code,
            'kind' => $this->kind, 'is_sellable' => $this->is_sellable, 'is_default' => $this->is_default,
        ];
    }
}
