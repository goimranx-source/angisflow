<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Shared\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Review Request Model
 *
 * Tracks individual review requests sent to customers
 * with engagement metrics and incentive tracking.
 */
class ReviewRequest extends Model
{
    use HasFactory, HasPublicId;

    protected $fillable = [
        'review_incentive_campaign_id',
        'customer_id',
        'order_id',
        'product_id',
        'request_type',
        'status',
        'message_content',
        'review_token',
        'sent_at',
        'opened_at',
        'clicked_at',
        'reviewed_at',
        'expires_at',
        'incentive_offered',
        'incentive_claimed',
        'incentive_amount',
        'incentive_granted_at',
        'product_review_id',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'opened_at' => 'datetime',
        'clicked_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'expires_at' => 'datetime',
        'incentive_granted_at' => 'datetime',
        'incentive_offered' => 'boolean',
        'incentive_claimed' => 'boolean',
        'incentive_amount' => 'integer',
    ];

    // ── Relationships ────────────────────────────────────────────────────────

    /**
     * Parent review incentive campaign
     */
    public function reviewIncentiveCampaign(): BelongsTo
    {
        return $this->belongsTo(ReviewIncentiveCampaign::class);
    }

    /**
     * Target customer
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Related order
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Related product (if product-specific request)
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Resulting product review
     */
    public function productReview(): BelongsTo
    {
        return $this->belongsTo(ProductReview::class);
    }

    // ── Status Management ────────────────────────────────────────────────────

    /**
     * Check if request was opened
     */
    public function wasOpened(): bool
    {
        return !is_null($this->opened_at) || in_array($this->status, ['opened', 'clicked', 'reviewed']);
    }

    /**
     * Check if request was clicked
     */
    public function wasClicked(): bool
    {
        return !is_null($this->clicked_at) || in_array($this->status, ['clicked', 'reviewed']);
    }

    /**
     * Check if review was completed
     */
    public function wasReviewed(): bool
    {
        return $this->status === 'reviewed' || !is_null($this->product_review_id);
    }

    /**
     * Check if request was declined
     */
    public function wasDeclined(): bool
    {
        return $this->status === 'declined';
    }

    /**
     * Check if request is expired
     */
    public function isExpired(): bool
    {
        return $this->status === 'expired' || $this->expires_at->isPast();
    }

    /**
     * Check if request is still valid
     */
    public function isValid(): bool
    {
        return !$this->isExpired() && 
               !$this->wasDeclined() && 
               !$this->wasReviewed();
    }

    // ── Engagement Tracking ──────────────────────────────────────────────────

    /**
     * Mark request as opened
     */
    public function markAsOpened(): void
    {
        if ($this->wasOpened()) {
            return;
        }

        $this->update([
            'status' => 'opened',
            'opened_at' => now(),
        ]);
    }

    /**
     * Mark request as clicked
     */
    public function markAsClicked(): void
    {
        if ($this->wasClicked()) {
            return;
        }

        $updates = [
            'status' => 'clicked',
            'clicked_at' => now(),
        ];

        // Also mark as opened if not already
        if (!$this->wasOpened()) {
            $updates['opened_at'] = now();
        }

        $this->update($updates);
    }

    /**
     * Mark request as reviewed and link to review
     */
    public function markAsReviewed(ProductReview $review): void
    {
        if ($this->wasReviewed()) {
            return;
        }

        $updates = [
            'status' => 'reviewed',
            'reviewed_at' => now(),
            'product_review_id' => $review->id,
        ];

        // Also mark as opened/clicked if not already
        if (!$this->wasOpened()) {
            $updates['opened_at'] = now();
        }
        if (!$this->wasClicked()) {
            $updates['clicked_at'] = now();
        }

        $this->update($updates);

        // Process incentive if offered
        if ($this->incentive_offered && !$this->incentive_claimed) {
            $this->processIncentive($review);
        }
    }

    /**
     * Mark request as declined
     */
    public function markAsDeclined(): void
    {
        if (!$this->isValid()) {
            return;
        }

        $this->update(['status' => 'declined']);
    }

    /**
     * Mark request as expired
     */
    public function markAsExpired(): void
    {
        if ($this->isExpired()) {
            return;
        }

        $this->update(['status' => 'expired']);
    }

    // ── Incentive Processing ─────────────────────────────────────────────────

    /**
     * Process incentive for completed review
     */
    private function processIncentive(ProductReview $review): bool
    {
        if (!$this->incentive_offered || $this->incentive_claimed) {
            return false;
        }

        $campaign = $this->reviewIncentiveCampaign;
        if (!$campaign || !$campaign->hasIncentive()) {
            return false;
        }

        // Check if incentive requires approval
        if ($campaign->incentive_requires_approval && !$review->is_approved) {
            return false; // Will be processed when review is approved
        }

        $success = $campaign->grantIncentive($this->customer, $review);

        if ($success) {
            $this->update([
                'incentive_claimed' => true,
                'incentive_granted_at' => now(),
            ]);
        }

        return $success;
    }

    /**
     * Retry incentive processing (for approved reviews)
     */
    public function retryIncentiveProcessing(): bool
    {
        if (!$this->productReview || $this->incentive_claimed) {
            return false;
        }

        return $this->processIncentive($this->productReview);
    }

    // ── Review Token Management ──────────────────────────────────────────────

    /**
     * Validate review token
     */
    public function validateToken(string $token): bool
    {
        return hash_equals($this->review_token, $token) && $this->isValid();
    }

    /**
     * Generate new review token
     */
    public function regenerateToken(): string
    {
        $newToken = bin2hex(random_bytes(32));
        $this->update(['review_token' => $newToken]);
        return $newToken;
    }

    /**
     * Get review link with token
     */
    public function getReviewLink(): string
    {
        $baseUrl = $this->reviewIncentiveCampaign->review_page_url 
                ?? config('app.url') . '/review';
        
        return $baseUrl . '?token=' . $this->review_token;
    }

    // ── Analytics ─────────────────────────────────────────────────────────────

    /**
     * Get engagement timeline
     */
    public function getEngagementTimeline(): array
    {
        $timeline = [
            [
                'event' => 'sent',
                'timestamp' => $this->sent_at,
                'description' => 'Review request sent',
            ],
        ];

        if ($this->opened_at) {
            $timeline[] = [
                'event' => 'opened',
                'timestamp' => $this->opened_at,
                'description' => 'Request opened by customer',
            ];
        }

        if ($this->clicked_at) {
            $timeline[] = [
                'event' => 'clicked',
                'timestamp' => $this->clicked_at,
                'description' => 'Review link clicked',
            ];
        }

        if ($this->reviewed_at) {
            $timeline[] = [
                'event' => 'reviewed',
                'timestamp' => $this->reviewed_at,
                'description' => 'Review submitted',
            ];
        }

        if ($this->incentive_granted_at) {
            $timeline[] = [
                'event' => 'incentive_granted',
                'timestamp' => $this->incentive_granted_at,
                'description' => 'Incentive processed',
            ];
        }

        return $timeline;
    }

    /**
     * Get time-to-action metrics
     */
    public function getTimeToActionMetrics(): array
    {
        $metrics = [];

        if ($this->sent_at && $this->opened_at) {
            $metrics['time_to_open'] = $this->sent_at->diffInMinutes($this->opened_at);
        }

        if ($this->opened_at && $this->clicked_at) {
            $metrics['time_to_click'] = $this->opened_at->diffInMinutes($this->clicked_at);
        }

        if ($this->clicked_at && $this->reviewed_at) {
            $metrics['time_to_review'] = $this->clicked_at->diffInMinutes($this->reviewed_at);
        }

        if ($this->sent_at && $this->reviewed_at) {
            $metrics['total_time_to_review'] = $this->sent_at->diffInMinutes($this->reviewed_at);
        }

        return $metrics;
    }

    // ── Display Helpers ──────────────────────────────────────────────────────

    /**
     * Get status display name
     */
    public function getStatusDisplayName(): string
    {
        return match($this->status) {
            'sent' => 'Sent',
            'opened' => 'Opened',
            'clicked' => 'Clicked',
            'reviewed' => 'Review Completed',
            'declined' => 'Declined',
            'expired' => 'Expired',
            default => ucfirst($this->status),
        };
    }

    /**
     * Get status CSS class
     */
    public function getStatusCssClass(): string
    {
        return match($this->status) {
            'reviewed' => 'success',
            'clicked' => 'info',
            'opened' => 'primary',
            'sent' => 'secondary',
            'declined' => 'warning',
            'expired' => 'danger',
            default => 'secondary',
        };
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    /**
     * Scope to requests by status
     */
    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to sent requests
     */
    public function scopeSent($query)
    {
        return $query->where('status', 'sent');
    }

    /**
     * Scope to opened requests
     */
    public function scopeOpened($query)
    {
        return $query->whereIn('status', ['opened', 'clicked', 'reviewed']);
    }

    /**
     * Scope to clicked requests
     */
    public function scopeClicked($query)
    {
        return $query->whereIn('status', ['clicked', 'reviewed']);
    }

    /**
     * Scope to completed reviews
     */
    public function scopeReviewed($query)
    {
        return $query->where('status', 'reviewed')
                    ->whereNotNull('product_review_id');
    }

    /**
     * Scope to expired requests
     */
    public function scopeExpired($query)
    {
        return $query->where(function ($q) {
            $q->where('status', 'expired')
              ->orWhere('expires_at', '<', now());
        });
    }

    /**
     * Scope to valid (non-expired, non-declined) requests
     */
    public function scopeValid($query)
    {
        return $query->whereNotIn('status', ['expired', 'declined', 'reviewed'])
                    ->where('expires_at', '>', now());
    }

    /**
     * Scope to requests with incentives
     */
    public function scopeWithIncentives($query)
    {
        return $query->where('incentive_offered', true);
    }

    /**
     * Scope to requests with unclaimed incentives
     */
    public function scopeWithUnclaimedIncentives($query)
    {
        return $query->where('incentive_offered', true)
                    ->where('incentive_claimed', false);
    }

    /**
     * Scope by request type
     */
    public function scopeByType($query, string $type)
    {
        return $query->where('request_type', $type);
    }
}