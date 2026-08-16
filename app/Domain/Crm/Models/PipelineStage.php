<?php

declare(strict_types=1);

namespace App\Domain\Crm\Models;

use App\Domain\Shared\Concerns\HasPublicId;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One step in a subscriber's own sales process.
 *
 * The name is theirs; the outcome is ours. A forecast cannot be built on a word
 * whose meaning varies between accounts, so `won` means won everywhere even
 * when the stage is called "PO received" or "deposit taken".
 */
class PipelineStage extends Model
{
    use BelongsToBusiness, HasPublicId;

    public const OPEN = 'open';
    public const WON = 'won';
    public const LOST = 'lost';

    protected $attributes = ['outcome' => self::OPEN, 'probability' => 0, 'sort_order' => 0, 'is_active' => true];

    protected $fillable = [
        'public_id', 'account_id', 'business_id', 'name', 'slug',
        'outcome', 'probability', 'sort_order', 'colour', 'is_active',
    ];

    protected function casts(): array
    {
        return ['probability' => 'integer', 'sort_order' => 'integer', 'is_active' => 'boolean'];
    }

    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class);
    }

    public function isClosing(): bool
    {
        return $this->outcome !== self::OPEN;
    }

    public function scopeOrdered(Builder $q): Builder
    {
        return $q->where('is_active', true)->orderBy('sort_order');
    }

    /**
     * A starting pipeline for a business that has not built one.
     *
     * Deliberately generic and deliberately editable. An empty pipeline is a
     * feature nobody can start using; a fixed one is a process nobody follows.
     *
     * @return list<array<string, mixed>>
     */
    public static function defaults(): array
    {
        return [
            ['name' => 'New',         'slug' => 'new',         'outcome' => self::OPEN, 'probability' => 10],
            ['name' => 'Qualified',   'slug' => 'qualified',   'outcome' => self::OPEN, 'probability' => 25],
            ['name' => 'Quoted',      'slug' => 'quoted',      'outcome' => self::OPEN, 'probability' => 50],
            ['name' => 'Negotiating', 'slug' => 'negotiating', 'outcome' => self::OPEN, 'probability' => 75],
            ['name' => 'Won',         'slug' => 'won',         'outcome' => self::WON,  'probability' => 100],
            ['name' => 'Lost',        'slug' => 'lost',        'outcome' => self::LOST, 'probability' => 0],
        ];
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'outcome' => $this->outcome,
            'probability' => $this->probability,
            'colour' => $this->colour,
        ];
    }
}
