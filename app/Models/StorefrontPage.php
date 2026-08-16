<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Shared\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Storefront Page Model
 *
 * Represents custom pages within a storefront (about us, policies, etc.)
 */
class StorefrontPage extends Model
{
    use HasPublicId, SoftDeletes;

    protected $fillable = [
        'title',
        'slug',
        'type',
        'content',
        'meta_data',
        'excerpt',
        'is_published',
        'show_in_navigation',
        'sort_order',
        'published_at',
        'template',
        'template_data',
    ];

    protected $casts = [
        'meta_data' => 'array',
        'is_published' => 'boolean',
        'show_in_navigation' => 'boolean',
        'published_at' => 'datetime',
        'template_data' => 'array',
    ];

    // ── Relationships ────────────────────────────────────────────────────────

    /**
     * The storefront this page belongs to
     */
    public function storefront(): BelongsTo
    {
        return $this->belongsTo(Storefront::class);
    }

    // ── Business Logic ────────────────────────────────────────────────────────

    /**
     * Get the full URL for this page
     */
    public function getUrl(): string
    {
        $storefrontUrl = $this->storefront->getUrl();
        return "{$storefrontUrl}/pages/{$this->slug}";
    }

    /**
     * Get meta data with defaults
     */
    public function getMetaData(): array
    {
        $defaults = [
            'title' => $this->title,
            'description' => $this->excerpt ?: substr(strip_tags($this->content), 0, 160),
            'keywords' => '',
            'robots' => 'index,follow',
        ];

        return array_merge($defaults, $this->meta_data ?? []);
    }

    /**
     * Get excerpt or generate from content
     */
    public function getExcerpt(int $length = 200): string
    {
        if ($this->excerpt) {
            return $this->excerpt;
        }

        $text = strip_tags($this->content);
        return strlen($text) > $length 
            ? substr($text, 0, $length) . '...'
            : $text;
    }

    /**
     * Check if page should be visible to public
     */
    public function isVisible(): bool
    {
        return $this->is_published && 
               ($this->published_at === null || $this->published_at->isPast());
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    /**
     * Scope to published pages only
     */
    public function scopePublished($query)
    {
        return $query->where('is_published', true)
                    ->where(function ($q) {
                        $q->whereNull('published_at')
                          ->orWhere('published_at', '<=', now());
                    });
    }

    /**
     * Scope to navigation pages
     */
    public function scopeInNavigation($query)
    {
        return $query->where('show_in_navigation', true)
                    ->orderBy('sort_order');
    }

    /**
     * Scope by page type
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }
}