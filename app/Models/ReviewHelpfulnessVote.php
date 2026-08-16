<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Review Helpfulness Vote Model
 *
 * Tracks customer votes on review helpfulness to prevent gaming
 * and provide useful feedback sorting.
 */
class ReviewHelpfulnessVote extends Model
{
    protected $fillable = [
        'review_id',
        'customer_id',
        'is_helpful',
        'ip_address',
    ];

    protected $casts = [
        'is_helpful' => 'boolean',
    ];

    // ── Relationships ────────────────────────────────────────────────────────

    /**
     * The review this vote is for
     */
    public function review(): BelongsTo
    {
        return $this->belongsTo(ProductReview::class, 'review_id');
    }

    /**
     * The customer who voted
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    /**
     * Scope to helpful votes
     */
    public function scopeHelpful($query)
    {
        return $query->where('is_helpful', true);
    }

    /**
     * Scope to not helpful votes
     */
    public function scopeNotHelpful($query)
    {
        return $query->where('is_helpful', false);
    }
}