<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Models;

use App\Domain\Billing\Allowance;
use App\Domain\Catalogue\Models\BusinessCategory;
use App\Domain\Catalogue\Models\Module;
use App\Domain\Shared\Concerns\HasPublicId;
use App\Domain\Tenancy\Concerns\BelongsToAccount;
use App\Support\Navigation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * What a subscription is sold in.
 *
 * The account is who pays; a business is one set of books; a workspace is the
 * container between them, and it is what the plan counts. A subscriber on a
 * plan allowing two workspaces of five businesses each can keep two unrelated
 * operations properly apart without buying a second account.
 */
class Workspace extends Model
{
    use BelongsToAccount, HasPublicId, SoftDeletes;

    protected $fillable = [
        'public_id',
        'account_id',
        'business_category_id',
        'name',
        'slug',
        'icon',
        'is_active',
        'base_currency',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // The create button reads a cached count. These are the only two
        // moments it can become wrong — dropped here rather than by whoever
        // did the writing, so the rule cannot be forgotten at a call site.
        static::saved(function (Workspace $w) {
            Allowance::forget($w->account_id);
            Navigation::forgetBusinesses($w->account_id);
        });

        static::deleted(function (Workspace $w) {
            Allowance::forget($w->account_id);
            Navigation::forgetBusinesses($w->account_id);
        });
    }

    public function businesses(): HasMany
    {
        return $this->hasMany(Business::class);
    }

    /**
     * What kind of business this is.
     *
     * Nullable and staying that way. It is read once, at creation, to decide
     * which modules to switch on — after that the workspace's own module rows
     * are the truth, and the category is only a label. Workspaces made before
     * categories existed have none, and are not broken for it.
     */
    public function businessCategory(): BelongsTo
    {
        return $this->belongsTo(BusinessCategory::class, 'business_category_id');
    }

    public function modules(): BelongsToMany
    {
        return $this->belongsToMany(Module::class, 'workspace_modules')
            ->withPivot(['is_enabled', 'changed_at', 'changed_by'])
            ->withTimestamps();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * A slug unique within the account.
     *
     * Scoped to the account rather than globally, so one subscriber naming a
     * workspace "retail" cannot stop another from doing the same — a global
     * uniqueness rule leaks the existence of other subscribers' data through
     * which names are refused.
     */
    public static function uniqueSlug(int $accountId, string $name): string
    {
        $base = Str::slug($name) ?: 'workspace';
        $slug = $base;
        $suffix = 2;

        while (self::withoutGlobalScopes()
            ->withTrashed()
            ->where('account_id', $accountId)
            ->where('slug', $slug)
            ->exists()
        ) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'icon' => $this->icon,
            'is_active' => $this->is_active,
        ];
    }
}
