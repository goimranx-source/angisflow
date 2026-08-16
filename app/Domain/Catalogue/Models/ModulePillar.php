<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One of the twelve groups the sidebar draws.
 *
 * No public id on this one, deliberately: a pillar is never addressed by a URL
 * or returned as an entity of its own. It is a heading, and the client
 * identifies it by its key — the same string the seed uses — so there is
 * nothing for a ULID to protect.
 */
class ModulePillar extends Model
{
    protected $fillable = [
        'key',
        'label',
        'icon',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function modules(): HasMany
    {
        return $this->hasMany(Module::class, 'pillar_id')->orderBy('sort_order');
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('label');
    }
}
