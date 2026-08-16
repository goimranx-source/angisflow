<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tenancy\Concerns\BelongsToAccount;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use App\Domain\Shared\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Product Review Model
 *
 * Manages customer reviews and ratings for products with moderation
 * capabilities and helpfulness voting.
 */
class ProductReview extends Model
{
    use BelongsToAccount, BelongsToBusiness, HasPublicId, SoftDeletes;

    protected $fillable = [
        'product_id',
        'variant_id',
        'customer_id',
        'title',
        'review_text',
        'rating',
        'is_verified_purchase',
        'order_public_id',
        'purchased_at',
        'status',
        'moderation_notes',
        'moderated_by',
        'moderated_at',
        'customer_name',
        'show_customer_name',
        'customer_location',
        'helpful_votes',
        'total_votes',
        'images',
        'videos',
    ];

    protected $casts = [
        'is_verified_purchase' => 'boolean',
        'purchased_at' => 'datetime',
        'moderated_at' => 'datetime',
        'show_customer_name' => 'boolean',
        'images' => 'array',
        'videos' => 'array',
    ];

    public const STATUSES = [
        'pending' => 'Pending Review',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'hidden' => 'Hidden',
    ];

    // ── Relationships ────────────────────────────────────────────────────────

    /**
     * The product being reviewed
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * The product variant being reviewed (if applicable)
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    /**
     * The customer who wrote the review
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * The staff member who moderated the review
     */
    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderated_by');
    }

    /**
     * Helpfulness votes for this review
     */
    public function helpfulnessVotes(): HasMany
    {
        return $this->hasMany(ReviewHelpfulnessVote::class, 'review_id');
    }

    // ── Business Logic ────────────────────────────────────────────────────────

    /**
     * Get status label
     */
    public function getStatusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    /**
     * Check if review is visible to public
     */
    public function isVisible(): bool
    {
        return $this->status === 'approved';
    }

    /**
     * Check if review is pending moderation
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Get helpfulness percentage
     */
    public function getHelpfulnessPercentage(): float
    {
        if ($this->total_votes === 0) {
            return 0;
        }

        return ($this->helpful_votes / $this->total_votes) * 100;
    }

    /**
     * Get star rating display
     */
    public function getStarRating(): string
    {
        return str_repeat('★', $this->rating) . str_repeat('☆', 5 - $this->rating);
    }

    /**
     * Approve the review
     */
    public function approve(int $moderatorId, string $notes = null): void
    {
        $this->update([
            'status' => 'approved',
            'moderated_by' => $moderatorId,
            'moderated_at' => now(),
            'moderation_notes' => $notes,
        ]);
        
        // Update product average rating
        $this->updateProductRating();
    }

    /**
     * Reject the review
     */
    public function reject(int $moderatorId, string $reason): void
    {
        $this->update([
            'status' => 'rejected',
            'moderated_by' => $moderatorId,
            'moderated_at' => now(),
            'moderation_notes' => $reason,
        ]);
    }

    /**
     * Hide the review
     */
    public function hide(int $moderatorId, string $reason): void
    {
        $this->update([
            'status' => 'hidden',
            'moderated_by' => $moderatorId,
            'moderated_at' => now(),
            'moderation_notes' => $reason,
        ]);
        
        // Update product average rating since this review is now hidden
        $this->updateProductRating();
    }

    /**
     * Record helpfulness vote
     */
    public function recordHelpfulnessVote(int $customerId, bool $isHelpful): bool
    {
        // Check if customer already voted
        $existingVote = $this->helpfulnessVotes()
            ->where('customer_id', $customerId)
            ->first();

        if ($existingVote) {
            // Update existing vote
            $oldVote = $existingVote->is_helpful;
            $existingVote->update(['is_helpful' => $isHelpful]);
            
            // Update vote counts
            if ($oldVote !== $isHelpful) {
                if ($isHelpful) {
                    $this->increment('helpful_votes');
                } else {
                    $this->decrement('helpful_votes');
                }
            }
        } else {
            // Create new vote
            ReviewHelpfulnessVote::create([
                'review_id' => $this->id,
                'customer_id' => $customerId,
                'is_helpful' => $isHelpful,
                'ip_address' => request()->ip(),
            ]);
            
            // Update vote counts
            $this->increment('total_votes');
            if ($isHelpful) {
                $this->increment('helpful_votes');
            }
        }

        return true;
    }

    /**
     * Check if customer can vote on this review
     */
    public function canCustomerVote(int $customerId): bool
    {
        // Can't vote on own review
        if ($this->customer_id === $customerId) {
            return false;
        }

        // Can't vote on non-approved reviews
        if (!$this->isVisible()) {
            return false;
        }

        return true;
    }

    /**
     * Update product average rating
     */
    private function updateProductRating(): void
    {
        $product = $this->product;
        
        $approvedReviews = $product->reviews()
            ->where('status', 'approved')
            ->get();

        if ($approvedReviews->isEmpty()) {
            $product->update([
                'average_rating' => null,
                'review_count' => 0,
            ]);
        } else {
            $averageRating = $approvedReviews->avg('rating');
            $reviewCount = $approvedReviews->count();
            
            $product->update([
                'average_rating' => round($averageRating, 2),
                'review_count' => $reviewCount,
            ]);
        }
    }

    /**
     * Get display name for customer
     */
    public function getDisplayCustomerName(): string
    {
        if (!$this->show_customer_name) {
            return 'Anonymous';
        }

        return $this->customer_name;
    }

    /**
     * Get review summary
     */
    public function getSummary(int $length = 100): string
    {
        $text = strip_tags($this->review_text ?? '');
        return strlen($text) > $length 
            ? substr($text, 0, $length) . '...'
            : $text;
    }

    /**
     * Check if review has media attachments
     */
    public function hasMedia(): bool
    {
        return !empty($this->images) || !empty($this->videos);
    }

    /**
     * Get media count
     */
    public function getMediaCount(): int
    {
        return count($this->images ?? []) + count($this->videos ?? []);
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    /**
     * Scope to approved reviews
     */
    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    /**
     * Scope to pending reviews
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope to verified purchase reviews
     */
    public function scopeVerifiedPurchase($query)
    {
        return $query->where('is_verified_purchase', true);
    }

    /**
     * Scope by rating
     */
    public function scopeWithRating($query, int $rating)
    {
        return $query->where('rating', $rating);
    }

    /**
     * Scope by minimum rating
     */
    public function scopeWithMinimumRating($query, int $minRating)
    {
        return $query->where('rating', '>=', $minRating);
    }

    /**
     * Scope with media attachments
     */
    public function scopeWithMedia($query)
    {
        return $query->where(function ($q) {
            $q->whereJsonLength('images', '>', 0)
              ->orWhereJsonLength('videos', '>', 0);
        });
    }

    /**
     * Scope by helpfulness
     */
    public function scopeMostHelpful($query)
    {
        return $query->orderByDesc('helpful_votes');
    }

    /**
     * Scope by recent reviews
     */
    public function scopeRecent($query)
    {
        return $query->orderByDesc('created_at');
    }
}