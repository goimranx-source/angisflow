<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Models;

use App\Domain\Billing\Allowance;
use App\Domain\Catalogue\Models\BusinessCategory;
use App\Domain\Shared\Concerns\HasPublicId;
use App\Domain\Tenancy\Concerns\BelongsToAccount;
use App\Support\Navigation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One set of books inside a subscriber's account.
 *
 * Its own profit and loss, its own capital, its own partners, its own currency.
 * Most accounts have exactly one and never think about this; a group that keeps
 * its arms apart has several, and every figure in the tool is read against
 * whichever is currently selected.
 */
class Business extends Model
{
    use BelongsToAccount, HasPublicId, SoftDeletes;

    protected $fillable = [
        'public_id',
        'account_id',
        'workspace_id',
        'business_category_id',
        'name',
        'short_code',
        'logo_media_id',
        'base_currency',
        'country',
        'timezone',
        'address',
        'phone',
        'email',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // The header switcher reads a cached list. These are the only two
        // moments it can become wrong.
        static::saved(function (Business $business) {
            Navigation::forgetBusinesses($business->account_id);
            Allowance::forget($business->account_id, $business->workspace_id);
        });

        static::deleting(function (Business $business) {
            // Soft-delete: append timestamp to short_code to avoid unique constraint
            // violation when creating a new business with the same code later.
            if ($business->short_code !== null) {
                $business->short_code = $business->short_code . '_' . time();
                $business->saveQuietly();
            }
        });

        static::deleted(function (Business $business) {
            Navigation::forgetBusinesses($business->account_id);
            Allowance::forget($business->account_id, $business->workspace_id);
        });
    }

    /**
     * A short code is identity, not a label — it composes every SKU and every
     * order reference. Normalised on the way in so "vb", "VB " and "Vb" cannot
     * become three businesses whose records all claim the same prefix.
     */
    public function setShortCodeAttribute(?string $value): void
    {
        $clean = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $value) ?? '');

        $this->attributes['short_code'] = $clean === '' ? null : $clean;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Which workspace holds this set of books. */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** The business category that determines which modules are available. */
    public function category(): BelongsTo
    {
        return $this->belongsTo(BusinessCategory::class, 'business_category_id');
    }
    
    /** The business categories (many-to-many relationship). */
    public function categories(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(BusinessCategory::class, 'business_business_category');
    }

    /** Business logo media item. */
    public function logoMedia(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Media\Models\MediaItem::class, 'logo_media_id');
    }

    public function label(): string
    {
        return $this->short_code ? "{$this->name} ({$this->short_code})" : $this->name;
    }
}
