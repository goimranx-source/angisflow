<?php

declare(strict_types=1);

namespace App\Domain\Helpdesk\Models;

use App\Domain\Shared\Concerns\HasPublicId;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What a ticket is about, and what was promised about it.
 *
 * The promise belongs here rather than on the ticket because different
 * questions deserve different answers: "where is my order" is not "my payment
 * failed", and one SLA for both is either too slow for the second or
 * unachievable for the first.
 */
class HelpdeskCategory extends Model
{
    use BelongsToBusiness, HasPublicId;

    protected $attributes = ['sort_order' => 0, 'is_active' => true];

    protected $fillable = [
        'public_id', 'account_id', 'business_id', 'parent_id', 'name', 'slug',
        'description', 'response_minutes', 'resolution_minutes', 'sort_order', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'response_minutes' => 'integer',
            'resolution_minutes' => 'integer',
            'is_active' => 'boolean',
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

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function articles(): HasMany
    {
        return $this->hasMany(KbArticle::class);
    }

    /**
     * The promise, falling back to the parent's.
     *
     * A subcategory usually inherits — somebody who splits "Delivery" into
     * "Late" and "Damaged" rarely wants to restate the SLA for each, and
     * forcing them to means the ones they forget have none at all.
     */
    public function responseMinutes(): ?int
    {
        return $this->response_minutes ?? $this->parent?->response_minutes;
    }

    public function resolutionMinutes(): ?int
    {
        return $this->resolution_minutes ?? $this->parent?->resolution_minutes;
    }

    public function scopeOrdered(Builder $q): Builder
    {
        return $q->where('is_active', true)->orderBy('sort_order');
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'response_minutes' => $this->responseMinutes(),
            'resolution_minutes' => $this->resolutionMinutes(),
        ];
    }
}
