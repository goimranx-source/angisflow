<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Shared\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Loyalty Transaction Model
 *
 * Tracks all point earning and redemption activities with
 * full audit trail and attribution.
 */
class LoyaltyTransaction extends Model
{
    use HasFactory, HasPublicId;

    protected $fillable = [
        'loyalty_membership_id',
        'customer_id',
        'type',
        'points_amount',
        'balance_after',
        'description',
        'status',
        'source_type',
        'source_id',
        'order_id',
        'campaign_id',
        'reference_code',
        'expires_at',
        'is_expired',
        'expired_at',
        'processed_by_user_id',
        'metadata',
        'notes',
    ];

    protected $casts = [
        'points_amount' => 'integer',
        'balance_after' => 'integer',
        'expires_at' => 'datetime',
        'is_expired' => 'boolean',
        'expired_at' => 'datetime',
        'metadata' => 'array',
        'processed_by_user_id' => 'integer',
    ];

    // ── Relationships ────────────────────────────────────────────────────────

    /**
     * Parent loyalty membership
     */
    public function loyaltyMembership(): BelongsTo
    {
        return $this->belongsTo(LoyaltyMembership::class);
    }

    /**
     * Customer who owns this transaction
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Related order (if transaction is from purchase)
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Related marketing campaign (if applicable)
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(MarketingCampaign::class, 'campaign_id');
    }

    /**
     * User who processed this transaction (for manual adjustments)
     */
    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by_user_id');
    }

    // ── Transaction Status ───────────────────────────────────────────────────

    /**
     * Check if transaction is completed
     */
    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * Check if transaction is pending
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Check if transaction is cancelled
     */
    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    /**
     * Check if transaction has failed
     */
    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    /**
     * Mark transaction as completed
     */
    public function complete(): void
    {
        if ($this->isCompleted()) {
            return;
        }

        $this->update(['status' => 'completed']);
    }

    /**
     * Cancel transaction
     */
    public function cancel(string $reason = null): void
    {
        if (!$this->isPending()) {
            throw new \InvalidArgumentException('Only pending transactions can be cancelled');
        }

        $this->update([
            'status' => 'cancelled',
            'notes' => $reason,
        ]);
    }

    /**
     * Mark transaction as failed
     */
    public function fail(string $reason = null): void
    {
        $this->update([
            'status' => 'failed',
            'notes' => $reason,
        ]);
    }

    // ── Transaction Types ────────────────────────────────────────────────────

    /**
     * Check if this is an earning transaction
     */
    public function isEarning(): bool
    {
        return $this->type === 'earned';
    }

    /**
     * Check if this is a redemption transaction
     */
    public function isRedemption(): bool
    {
        return $this->type === 'redeemed';
    }

    /**
     * Check if this is an expiration transaction
     */
    public function isExpiration(): bool
    {
        return $this->type === 'expired';
    }

    /**
     * Check if this is an adjustment transaction
     */
    public function isAdjustment(): bool
    {
        return $this->type === 'adjusted';
    }

    /**
     * Check if this is a transfer transaction
     */
    public function isTransfer(): bool
    {
        return $this->type === 'transferred';
    }

    /**
     * Check if this is a refund transaction
     */
    public function isRefund(): bool
    {
        return $this->type === 'refunded';
    }

    // ── Expiration Management ────────────────────────────────────────────────

    /**
     * Check if transaction has expiry date
     */
    public function hasExpiry(): bool
    {
        return !is_null($this->expires_at);
    }

    /**
     * Check if transaction is expired
     */
    public function isExpired(): bool
    {
        return $this->is_expired || ($this->hasExpiry() && $this->expires_at->isPast());
    }

    /**
     * Check if transaction is expiring soon
     */
    public function isExpiringSoon(int $days = 30): bool
    {
        return $this->hasExpiry() && 
               !$this->isExpired() && 
               $this->expires_at->diffInDays(now()) <= $days;
    }

    /**
     * Mark transaction as expired
     */
    public function markAsExpired(): void
    {
        if ($this->is_expired) {
            return;
        }

        $this->update([
            'is_expired' => true,
            'expired_at' => now(),
        ]);
    }

    /**
     * Get days until expiry
     */
    public function getDaysUntilExpiry(): ?int
    {
        if (!$this->hasExpiry() || $this->isExpired()) {
            return null;
        }

        return now()->diffInDays($this->expires_at, false);
    }

    // ── Source Attribution ───────────────────────────────────────────────────

    /**
     * Get source attribution details
     */
    public function getSourceAttribution(): array
    {
        return [
            'type' => $this->source_type,
            'id' => $this->source_id,
            'reference' => $this->reference_code,
            'order' => $this->order,
            'campaign' => $this->campaign,
        ];
    }

    /**
     * Check if transaction is from order
     */
    public function isFromOrder(): bool
    {
        return $this->source_type === 'order' || !is_null($this->order_id);
    }

    /**
     * Check if transaction is from campaign
     */
    public function isFromCampaign(): bool
    {
        return $this->source_type === 'campaign' || !is_null($this->campaign_id);
    }

    /**
     * Check if transaction is from referral
     */
    public function isFromReferral(): bool
    {
        return $this->source_type === 'referral';
    }

    /**
     * Check if transaction is from review
     */
    public function isFromReview(): bool
    {
        return $this->source_type === 'review';
    }

    /**
     * Get human-readable source description
     */
    public function getSourceDescription(): string
    {
        if ($this->isFromOrder() && $this->order) {
            return "Order #{$this->order->order_number}";
        }

        if ($this->isFromCampaign() && $this->campaign) {
            return "Campaign: {$this->campaign->name}";
        }

        return match($this->source_type) {
            'referral' => 'Referral Program',
            'review' => 'Product Review',
            'signup' => 'Account Sign-up',
            'birthday' => 'Birthday Bonus',
            'manual' => 'Manual Adjustment',
            default => $this->source_type ?? 'Unknown',
        };
    }

    // ── Display Helpers ──────────────────────────────────────────────────────

    /**
     * Get formatted points amount with sign
     */
    public function getFormattedPointsAmount(): string
    {
        $sign = $this->points_amount >= 0 ? '+' : '';
        return $sign . number_format($this->points_amount);
    }

    /**
     * Get transaction type display name
     */
    public function getTypeDisplayName(): string
    {
        return match($this->type) {
            'earned' => 'Points Earned',
            'redeemed' => 'Points Redeemed',
            'expired' => 'Points Expired',
            'adjusted' => 'Points Adjusted',
            'transferred' => 'Points Transferred',
            'refunded' => 'Points Refunded',
            default => ucfirst($this->type),
        };
    }

    /**
     * Get status display name
     */
    public function getStatusDisplayName(): string
    {
        return match($this->status) {
            'pending' => 'Pending',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
            'failed' => 'Failed',
            default => ucfirst($this->status),
        };
    }

    /**
     * Get CSS class for status display
     */
    public function getStatusCssClass(): string
    {
        return match($this->status) {
            'completed' => 'success',
            'pending' => 'warning',
            'cancelled' => 'secondary',
            'failed' => 'danger',
            default => 'info',
        };
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    /**
     * Scope to completed transactions
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    /**
     * Scope to pending transactions
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope to earning transactions
     */
    public function scopeEarned($query)
    {
        return $query->where('type', 'earned');
    }

    /**
     * Scope to redemption transactions
     */
    public function scopeRedeemed($query)
    {
        return $query->where('type', 'redeemed');
    }

    /**
     * Scope to expired transactions
     */
    public function scopeExpired($query)
    {
        return $query->where('type', 'expired');
    }

    /**
     * Scope to adjustment transactions
     */
    public function scopeAdjusted($query)
    {
        return $query->where('type', 'adjusted');
    }

    /**
     * Scope to transactions with expiry dates
     */
    public function scopeWithExpiry($query)
    {
        return $query->whereNotNull('expires_at');
    }

    /**
     * Scope to transactions expiring soon
     */
    public function scopeExpiringSoon($query, int $days = 30)
    {
        return $query->where('expires_at', '<=', now()->addDays($days))
                    ->where('is_expired', false);
    }

    /**
     * Scope to transactions by source type
     */
    public function scopeFromSource($query, string $sourceType)
    {
        return $query->where('source_type', $sourceType);
    }

    /**
     * Scope to transactions in date range
     */
    public function scopeInDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }

    /**
     * Scope to recent transactions
     */
    public function scopeRecent($query, int $days = 30)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }
}