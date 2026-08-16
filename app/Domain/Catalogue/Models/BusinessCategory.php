<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Models;

use App\Domain\Shared\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What kind of business a workspace is.
 *
 * ── Why this is not an industry code ─────────────────────────────────────────
 *
 * The obvious move is to reach for NAICS or SIC. Both are wrong for this job.
 * They exist to classify economies for statistical reporting — NAICS alone
 * defines around a thousand industries — and they answer a question nobody
 * here is asking. What this picker needs to predict is which modules a
 * business will use, and an industry code does not predict that: a dental
 * practice and a hair salon sit in entirely different sections of NAICS and
 * want almost identical software — appointments, staff rota, deposits, repeat
 * customers.
 *
 * So the top level is the operating model — how the business earns and
 * delivers — because that is what actually correlates with the module list.
 * The subcategory underneath it is the word the subscriber would use about
 * themselves, which is what makes the picker feel accurate rather than
 * bureaucratic.
 *
 * ── One table, two levels ────────────────────────────────────────────────────
 *
 * A subcategory is a category with a parent. The alternative is two nearly
 * identical tables that have to be kept in step by hand for ever.
 */
class BusinessCategory extends Model
{
    use HasPublicId;

    protected $fillable = [
        'public_id',
        'parent_id',
        'key',
        'name',
        'icon',
        'summary',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order');
    }

    public function modules(): BelongsToMany
    {
        return $this->belongsToMany(Module::class, 'category_module_presets')
            ->withPivot('enabled_by_default')
            ->withTimestamps();
    }

    /** The eight operating models — everything with no parent. */
    public function scopeTopLevel(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    /**
     * The category whose presets apply.
     *
     * A subcategory may carry its own preset overrides, but usually will not —
     * the operating model is what decides the module list, and the subcategory
     * exists mostly so the picker reads in the subscriber's own language. When
     * it has none of its own, its parent's presets are the answer.
     */
    public function presetSource(): self
    {
        if ($this->parent_id !== null && $this->modules()->count() === 0) {
            return $this->parent;
        }

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'id' => $this->getAttributes()['id'], // Database integer ID for relationships
            'public_id' => $this->public_id, // Public ULID for display
            'key' => $this->key,
            'name' => $this->name,
            'icon' => $this->icon,
            'summary' => $this->summary,
            // Set by whoever built the list, because counting it per row is a
            // query per category. Null means nobody asked.
            'modules' => $this->preset_module_count,
            'children' => $this->relationLoaded('children')
                ? $this->children->map->toPayload()->all()
                : [],
        ];
    }
}
