<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Shared\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Carbon\Carbon;

/**
 * Loyalty Membership Model
 *
 * Represents a customer's membership in a loyalty program
 * with points balance, tier status, and participation tracking.
 */
class LoyaltyMembership extends Model
{
    use HasFactory, HasPublicId;

    protected $fillable = [
        'loyalty_program_id',
        'customer_id',
        'status',
        'enrolled_at',
        'last_activity_at',
        'points_balance',
        'points_lifetime_earned',
        'points_lifetime_redeemed',
        'points_pending',
        'points_last_earned_at',
        'points_last_redeemed_at',
        'current_tier',
        'tier_points',
        'tier_spend_amount',
        'tier_achieved_at',
        'tier_expires_at',
        'tier_benefits_used',
        'referral_code',
        'referrals_made',
        'referrals_successful',
        'referral_points_earned',
        'referred_by_customer_id',
        'notification_preferences',
        'preferences',
        'tags',
    ];

    protected $casts = [
        'enrolled_at' => 'datetime',
        'last_activity_at' => 'datetime',
        'points_last_earned_at' => 'datetime',
        'points_last_redeemed_at' => 'datetime',
        'tier_achieved_at' => 'datetime',
        'tier_expires_at' => 'datetime',
        'tier_benefits_used' => 'array',
        'notification_preferences' => 'array',
        'preferences' => 'array',
        'tags' => 'array',
        'points_balance' => 'integer',
        'points_lifetime_earned' => 'integer',
        'points_lifetime_redeemed' => 'integer',
        'points_pending' => 'integer',
        'tier_points' => 'integer',
        'tier_spend_amount' => 'integer',
        'referrals_made' => 'integer',
        'referrals_successful' => 'integer',
        'referral_points_earned' => 'integer',
    ];

    // ── Relationships ────────────────────────────────────────────────────────

    /**
     * Parent loyalty program
     */
    public function loyaltyProgram(): BelongsTo
    {
        return $this->belongsTo(LoyaltyProgram::class);
    }

    /**
     * Member customer
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Customer who referred this member
     */
    public function referredBy(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'referred_by_customer_id');
    }

    /**
     * Point transactions for this membership
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(LoyaltyTransaction::class);
    }

    /**
     * Recent transactions
     */
    public function recentTransactions(): HasMany
    {
        return $this->transactions()
            ->orderBy('created_at', 'desc')
            ->limit(10);
    }

    // ── Membership Status ────────────────────────────────────────────────────

    /**
     * Check if membership is active
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Activate membership
     */
    public function activate(): void
    {
        if ($this->isActive()) {
            return;
        }

        $this->update([
            'status' => 'active',
            'last_activity_at' => now(),
        ]);
    }

    /**
     * Suspend membership
     */
    public function suspend(): void
    {
        $this->update(['status' => 'suspended']);
    }

    /**
     * Cancel membership
     */
    public function cancel(): void
    {
        $this->update(['status' => 'cancelled']);
    }

    /**
     * Update activity timestamp
     */
    public function recordActivity(): void
    {
        $this->update(['last_activity_at' => now()]);
    }

    // ── Points Management ─────────────────────────────────────────────────────

    /**
     * Add points to balance
     */
    public function addPoints(
        int $points, 
        string $description, 
        string $sourceType = null, 
        string $sourceId = null,
        ?Carbon $expiresAt = null
    ): LoyaltyTransaction {
        if (!$this->isActive()) {
            throw new \InvalidArgumentException('Cannot add points to inactive membership');
        }

        $transaction = $this->transactions()->create([
            'customer_id' => $this->customer_id,
            'type' => 'earned',
            'points_amount' => $points,
            'balance_after' => $this->points_balance + $points,
            'description' => $description,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'expires_at' => $expiresAt,
        ]);

        $this->update([
            'points_balance' => $this->points_balance + $points,
            'points_lifetime_earned' => $this->points_lifetime_earned + $points,
            'points_last_earned_at' => now(),
            'last_activity_at' => now(),
        ]);

        // Update program analytics
        $this->loyaltyProgram->increment('total_points_issued', $points);

        return $transaction;
    }

    /**
     * Redeem points from balance
     */
    public function redeemPoints(
        int $points, 
        string $description, 
        string $sourceType = null, 
        string $sourceId = null
    ): LoyaltyTransaction {
        if (!$this->isActive()) {
            throw new \InvalidArgumentException('Cannot redeem points from inactive membership');
        }

        if ($points > $this->points_balance) {
            throw new \InvalidArgumentException('Insufficient points balance');
        }

        if (!$this->loyaltyProgram->canRedeem($points)) {
            throw new \InvalidArgumentException('Points amount below minimum redemption threshold');
        }

        $transaction = $this->transactions()->create([
            'customer_id' => $this->customer_id,
            'type' => 'redeemed',
            'points_amount' => -$points, // Negative for redemption
            'balance_after' => $this->points_balance - $points,
            'description' => $description,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
        ]);

        $this->update([
            'points_balance' => $this->points_balance - $points,
            'points_lifetime_redeemed' => $this->points_lifetime_redeemed + $points,
            'points_last_redeemed_at' => now(),
            'last_activity_at' => now(),
        ]);

        // Update program analytics
        $this->loyaltyProgram->increment('total_points_redeemed', $points);
        $this->loyaltyProgram->increment('total_rewards_claimed');

        return $transaction;
    }

    /**
     * Adjust points balance (admin only)
     */
    public function adjustPoints(
        int $pointsChange, 
        string $reason, 
        ?int $processedByUserId = null
    ): LoyaltyTransaction {
        $newBalance = $this->points_balance + $pointsChange;
        
        if ($newBalance < 0) {
            throw new \InvalidArgumentException('Adjustment would result in negative balance');
        }

        $transaction = $this->transactions()->create([
            'customer_id' => $this->customer_id,
            'type' => 'adjusted',
            'points_amount' => $pointsChange,
            'balance_after' => $newBalance,
            'description' => $reason,
            'processed_by_user_id' => $processedByUserId,
        ]);

        $this->update([
            'points_balance' => $newBalance,
            'last_activity_at' => now(),
        ]);

        if ($pointsChange > 0) {
            $this->increment('points_lifetime_earned', $pointsChange);
        }

        return $transaction;
    }

    /**
     * Expire points
     */
    public function expirePoints(): int
    {
        if (!$this->loyaltyProgram->points_expire_days) {
            return 0; // No expiration policy
        }

        $expiryDate = now()->subDays($this->loyaltyProgram->points_expire_days);
        
        $expiredTransactions = $this->transactions()
            ->where('type', 'earned')
            ->where('expires_at', '<=', $expiryDate)
            ->where('is_expired', false)
            ->get();

        $totalExpired = 0;

        foreach ($expiredTransactions as $transaction) {
            $transaction->update([
                'is_expired' => true,
                'expired_at' => now(),
            ]);

            // Create expiration transaction
            $this->transactions()->create([
                'customer_id' => $this->customer_id,
                'type' => 'expired',
                'points_amount' => -$transaction->points_amount,
                'balance_after' => $this->points_balance - $transaction->points_amount,
                'description' => "Points expired (earned on {$transaction->created_at->format('Y-m-d')})",
            ]);

            $totalExpired += $transaction->points_amount;
        }

        if ($totalExpired > 0) {
            $this->decrement('points_balance', $totalExpired);
        }

        return $totalExpired;
    }

    // ── Tier Management ──────────────────────────────────────────────────────

    /**
     * Update tier status based on current metrics
     */
    public function updateTier(): ?string
    {
        $program = $this->loyaltyProgram;
        $newTier = $program->calculateTier($this->tier_points, $this->tier_spend_amount);

        if ($newTier && $newTier !== $this->current_tier) {
            $this->upgradeTier($newTier);
        }

        return $newTier;
    }

    /**
     * Upgrade to new tier
     */
    public function upgradeTier(string $tier): void
    {
        $this->update([
            'current_tier' => $tier,
            'tier_achieved_at' => now(),
            'tier_expires_at' => $this->calculateTierExpiry(),
            'tier_benefits_used' => [], // Reset benefits usage
        ]);
    }

    /**
     * Calculate tier expiry date
     */
    private function calculateTierExpiry(): ?Carbon
    {
        if (!$this->loyaltyProgram->tier_auto_downgrade) {
            return null; // No expiry
        }

        return match($this->loyaltyProgram->tier_period) {
            'monthly' => now()->addMonth(),
            'quarterly' => now()->addMonths(3),
            'yearly' => now()->addYear(),
            'lifetime' => null,
            default => now()->addYear(),
        };
    }

    /**
     * Check if has tier benefit
     */
    public function hasTierBenefit(string $benefit): bool
    {
        if (!$this->current_tier) {
            return false;
        }

        return $this->loyaltyProgram->tierHasBenefit($this->current_tier, $benefit);
    }

    /**
     * Use tier benefit
     */
    public function useTierBenefit(string $benefit): bool
    {
        if (!$this->hasTierBenefit($benefit)) {
            return false;
        }

        $used = $this->tier_benefits_used ?? [];
        $used[] = [
            'benefit' => $benefit,
            'used_at' => now()->toISOString(),
        ];

        $this->update(['tier_benefits_used' => $used]);
        return true;
    }

    // ── Referral System ──────────────────────────────────────────────────────

    /**
     * Process referral for new customer
     */
    public function processReferral(Customer $referredCustomer, int $purchaseAmount = 0): bool
    {
        $program = $this->loyaltyProgram;
        
        if (!$program->hasReferralProgram()) {
            return false;
        }

        $rewards = $program->getReferralRewards();
        
        // Check minimum purchase requirement
        if ($rewards['minimum_purchase'] > 0 && $purchaseAmount < $rewards['minimum_purchase']) {
            return false;
        }

        // Award points to referrer
        if ($rewards['referrer_points'] > 0) {
            $this->addPoints(
                $rewards['referrer_points'],
                "Referral bonus for {$referredCustomer->name}",
                'referral',
                (string)$referredCustomer->id
            );

            $this->increment('referral_points_earned', $rewards['referrer_points']);
        }

        // Track referral
        $this->increment('referrals_made');
        if ($purchaseAmount >= ($rewards['minimum_purchase'] ?? 0)) {
            $this->increment('referrals_successful');
        }

        return true;
    }

    /**
     * Generate new referral code
     */
    public function regenerateReferralCode(): string
    {
        $newCode = $this->loyaltyProgram->generateReferralCode();
        $this->update(['referral_code' => $newCode]);
        return $newCode;
    }

    // ── Analytics ─────────────────────────────────────────────────────────────

    /**
     * Get membership activity summary
     */
    public function getActivitySummary(): array
    {
        return [
            'enrollment_date' => $this->enrolled_at,
            'days_since_enrollment' => $this->enrolled_at->diffInDays(now()),
            'last_activity' => $this->last_activity_at,
            'days_since_activity' => $this->last_activity_at?->diffInDays(now()),
            'points_balance' => $this->points_balance,
            'points_earned' => $this->points_lifetime_earned,
            'points_redeemed' => $this->points_lifetime_redeemed,
            'redemption_rate' => $this->points_lifetime_earned > 0 
                ? ($this->points_lifetime_redeemed / $this->points_lifetime_earned) * 100 
                : 0,
            'current_tier' => $this->current_tier,
            'referrals_made' => $this->referrals_made,
            'referrals_successful' => $this->referrals_successful,
        ];
    }

    /**
     * Get points expiry timeline
     */
    public function getPointsExpiryTimeline(): array
    {
        if (!$this->loyaltyProgram->points_expire_days) {
            return [];
        }

        return $this->transactions()
            ->where('type', 'earned')
            ->where('is_expired', false)
            ->whereNotNull('expires_at')
            ->orderBy('expires_at')
            ->get()
            ->map(function ($transaction) {
                return [
                    'points' => $transaction->points_amount,
                    'earned_date' => $transaction->created_at,
                    'expires_date' => $transaction->expires_at,
                    'days_until_expiry' => now()->diffInDays($transaction->expires_at, false),
                ];
            })
            ->toArray();
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    /**
     * Scope to active memberships
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope to memberships with recent activity
     */
    public function scopeRecentlyActive($query, int $days = 30)
    {
        return $query->where('last_activity_at', '>=', now()->subDays($days));
    }

    /**
     * Scope to memberships by tier
     */
    public function scopeInTier($query, string $tier)
    {
        return $query->where('current_tier', $tier);
    }

    /**
     * Scope to memberships with points balance
     */
    public function scopeWithPointsBalance($query, int $minimumPoints = 1)
    {
        return $query->where('points_balance', '>=', $minimumPoints);
    }

    /**
     * Scope to memberships needing tier update
     */
    public function scopeNeedingTierUpdate($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('tier_expires_at')
              ->orWhere('tier_expires_at', '<=', now());
        });
    }
}