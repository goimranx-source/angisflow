<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tenancy\Concerns\BelongsToAccount;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use App\Domain\Shared\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Loyalty Program Model
 *
 * Configures customer loyalty programs with points, tiers,
 * and rewards to drive customer retention and engagement.
 */
class LoyaltyProgram extends Model
{
    use HasFactory, HasPublicId, BelongsToAccount, BelongsToBusiness, SoftDeletes;

    protected $fillable = [
        'name',
        'description',
        'type',
        'is_active',
        'auto_enroll',
        'earning_rules',
        'redemption_rules',
        'points_per_currency_unit',
        'points_currency',
        'minimum_redemption_points',
        'tier_levels',
        'tier_auto_upgrade',
        'tier_auto_downgrade',
        'tier_period',
        'points_expire_days',
        'points_transferable',
        'max_points_per_transaction',
        'max_points_per_day',
        'referral_config',
        'referrer_reward_points',
        'referee_reward_points',
        'referral_minimum_purchase',
        'points_name',
        'logo_url',
        'branding_config',
        'terms_and_conditions',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'auto_enroll' => 'boolean',
        'earning_rules' => 'array',
        'redemption_rules' => 'array',
        'tier_levels' => 'array',
        'tier_auto_upgrade' => 'boolean',
        'tier_auto_downgrade' => 'boolean',
        'points_transferable' => 'boolean',
        'referral_config' => 'array',
        'branding_config' => 'array',
        'terms_and_conditions' => 'array',
        'points_per_currency_unit' => 'integer',
        'minimum_redemption_points' => 'integer',
        'points_expire_days' => 'integer',
        'max_points_per_transaction' => 'integer',
        'max_points_per_day' => 'integer',
        'referrer_reward_points' => 'integer',
        'referee_reward_points' => 'integer',
        'referral_minimum_purchase' => 'integer',
        'total_members' => 'integer',
        'active_members' => 'integer',
        'total_points_issued' => 'integer',
        'total_points_redeemed' => 'integer',
        'total_rewards_claimed' => 'integer',
    ];

    // ── Relationships ────────────────────────────────────────────────────────

    /**
     * Customer memberships in this program
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(LoyaltyMembership::class);
    }

    /**
     * Active memberships
     */
    public function activeMemberships(): HasMany
    {
        return $this->memberships()->where('status', 'active');
    }

    /**
     * Available rewards in this program
     */
    public function rewards(): HasMany
    {
        return $this->hasMany(LoyaltyReward::class);
    }

    /**
     * Active rewards
     */
    public function activeRewards(): HasMany
    {
        return $this->rewards()->where('is_active', true);
    }

    // ── Program Configuration ────────────────────────────────────────────────

    /**
     * Calculate points earned for a purchase
     */
    public function calculatePointsEarned(int $purchaseAmount, string $currency = null): int
    {
        if (!$this->is_active) {
            return 0;
        }

        $currency = $currency ?? $this->points_currency;
        
        // Convert to program currency if different
        if ($currency !== $this->points_currency) {
            // TODO: Implement currency conversion
            $purchaseAmount = $this->convertCurrency($purchaseAmount, $currency, $this->points_currency);
        }

        // Base points calculation
        $points = intval($purchaseAmount / 100) * $this->points_per_currency_unit;

        // Apply earning rules multipliers
        $points = $this->applyEarningRules($points, $purchaseAmount);

        // Apply daily/transaction limits
        return $this->applyPointsLimits($points);
    }

    /**
     * Apply earning rules multipliers
     */
    private function applyEarningRules(int $basePoints, int $purchaseAmount): int
    {
        $rules = $this->earning_rules ?? [];
        $multiplier = 1.0;

        foreach ($rules as $rule) {
            if ($this->evaluateEarningRule($rule, $purchaseAmount)) {
                $multiplier *= $rule['multiplier'] ?? 1.0;
            }
        }

        return intval($basePoints * $multiplier);
    }

    /**
     * Evaluate earning rule condition
     */
    private function evaluateEarningRule(array $rule, int $purchaseAmount): bool
    {
        if (!isset($rule['condition'])) {
            return true;
        }

        $condition = $rule['condition'];
        
        return match($condition['type']) {
            'minimum_purchase' => $purchaseAmount >= ($condition['amount'] ?? 0),
            'category' => true, // TODO: Implement category-based rules
            'product' => true, // TODO: Implement product-based rules
            default => true,
        };
    }

    /**
     * Apply points limits
     */
    private function applyPointsLimits(int $points): int
    {
        if ($this->max_points_per_transaction && $points > $this->max_points_per_transaction) {
            $points = $this->max_points_per_transaction;
        }

        // TODO: Implement daily limits check

        return $points;
    }

    /**
     * Check if customer can redeem points
     */
    public function canRedeem(int $points): bool
    {
        return $this->is_active && 
               $points >= $this->minimum_redemption_points;
    }

    // ── Tier System ──────────────────────────────────────────────────────────

    /**
     * Get tier levels configuration
     */
    public function getTierLevels(): array
    {
        return $this->tier_levels ?? [];
    }

    /**
     * Calculate tier for points/spend
     */
    public function calculateTier(int $points, int $spendAmount): ?string
    {
        $tierLevels = $this->getTierLevels();
        
        if (empty($tierLevels)) {
            return null;
        }

        $qualifiedTier = null;

        foreach ($tierLevels as $tierName => $tierConfig) {
            $requirement = $tierConfig['requirement'] ?? [];
            $qualifies = false;

            if (isset($requirement['points']) && $points >= $requirement['points']) {
                $qualifies = true;
            }

            if (isset($requirement['spend']) && $spendAmount >= $requirement['spend']) {
                $qualifies = true;
            }

            if ($qualifies) {
                $qualifiedTier = $tierName;
            }
        }

        return $qualifiedTier;
    }

    /**
     * Get tier benefits
     */
    public function getTierBenefits(string $tier): array
    {
        $tierLevels = $this->getTierLevels();
        return $tierLevels[$tier]['benefits'] ?? [];
    }

    /**
     * Check if tier has specific benefit
     */
    public function tierHasBenefit(string $tier, string $benefit): bool
    {
        $benefits = $this->getTierBenefits($tier);
        return in_array($benefit, $benefits);
    }

    // ── Referral Program ─────────────────────────────────────────────────────

    /**
     * Check if referral program is enabled
     */
    public function hasReferralProgram(): bool
    {
        return !empty($this->referral_config) && 
               ($this->referrer_reward_points > 0 || $this->referee_reward_points > 0);
    }

    /**
     * Get referral rewards
     */
    public function getReferralRewards(): array
    {
        return [
            'referrer_points' => $this->referrer_reward_points ?? 0,
            'referee_points' => $this->referee_reward_points ?? 0,
            'minimum_purchase' => $this->referral_minimum_purchase ?? 0,
            'config' => $this->referral_config ?? [],
        ];
    }

    /**
     * Whether the program is currently accepting activity.
     *
     * `is_active` is the column; this exists because callers were written
     * against a `status` string that this table never had, and asking the
     * question in one place is what stops the next caller inventing a third
     * spelling of it.
     */
    public function isActive(): bool
    {
        return (bool) $this->is_active;
    }

    /**
     * Whether anybody has joined yet.
     *
     * Used to refuse edits that would silently rewrite the terms under people
     * who already joined on the old ones.
     */
    public function hasMemberships(): bool
    {
        return $this->memberships()->exists();
    }

    // ── Enrollment ───────────────────────────────────────────────────────────

    /**
     * Enroll a customer in the program
     */
    public function enrollCustomer(Customer $customer): LoyaltyMembership
    {
        // Check if already enrolled
        $existing = $this->memberships()
            ->where('customer_id', $customer->id)
            ->first();

        if ($existing) {
            if ($existing->status === 'active') {
                return $existing; // Already enrolled and active
            }
            
            // Reactivate if inactive
            $existing->update(['status' => 'active']);
            return $existing;
        }

        // Create new membership
        $membership = $this->memberships()->create([
            'customer_id' => $customer->id,
            'status' => 'active',
            'enrolled_at' => now(),
            'referral_code' => $this->generateReferralCode(),
        ]);

        // Update program stats
        $this->increment('total_members');
        $this->increment('active_members');

        return $membership;
    }

    /**
     * Generate unique referral code
     */
    private function generateReferralCode(): string
    {
        do {
            $code = strtoupper(substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 8));
        } while (LoyaltyMembership::where('referral_code', $code)->exists());

        return $code;
    }

    // ── Analytics ─────────────────────────────────────────────────────────────

    /**
     * Update program analytics
     */
    public function updateAnalytics(): void
    {
        $this->update([
            'total_members' => $this->memberships()->count(),
            'active_members' => $this->activeMemberships()->count(),
            'total_points_issued' => $this->calculateTotalPointsIssued(),
            'total_points_redeemed' => $this->calculateTotalPointsRedeemed(),
            'total_rewards_claimed' => $this->calculateTotalRewardsClaimed(),
        ]);
    }

    /**
     * Calculate total points issued
     */
    private function calculateTotalPointsIssued(): int
    {
        return LoyaltyTransaction::whereHas('loyaltyMembership', function ($query) {
            $query->where('loyalty_program_id', $this->id);
        })
        ->where('type', 'earned')
        ->sum('points_amount');
    }

    /**
     * Calculate total points redeemed
     */
    private function calculateTotalPointsRedeemed(): int
    {
        return abs(LoyaltyTransaction::whereHas('loyaltyMembership', function ($query) {
            $query->where('loyalty_program_id', $this->id);
        })
        ->where('type', 'redeemed')
        ->sum('points_amount'));
    }

    /**
     * Calculate total rewards claimed
     */
    private function calculateTotalRewardsClaimed(): int
    {
        return LoyaltyTransaction::whereHas('loyaltyMembership', function ($query) {
            $query->where('loyalty_program_id', $this->id);
        })
        ->where('type', 'redeemed')
        ->count();
    }

    /**
     * Get program performance metrics
     */
    public function getPerformanceMetrics(): array
    {
        return [
            'total_members' => $this->total_members,
            'active_members' => $this->active_members,
            'active_rate' => $this->total_members > 0 ? ($this->active_members / $this->total_members) * 100 : 0,
            'total_points_issued' => $this->total_points_issued,
            'total_points_redeemed' => $this->total_points_redeemed,
            'redemption_rate' => $this->total_points_issued > 0 ? ($this->total_points_redeemed / $this->total_points_issued) * 100 : 0,
            'total_rewards_claimed' => $this->total_rewards_claimed,
            'average_points_per_member' => $this->active_members > 0 ? $this->total_points_issued / $this->active_members : 0,
        ];
    }

    // ── Utility Methods ──────────────────────────────────────────────────────

    /**
     * Convert currency (placeholder for actual implementation)
     */
    private function convertCurrency(int $amount, string $from, string $to): int
    {
        // TODO: Implement actual currency conversion using exchange rates
        return $amount; // For now, return same amount
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    /**
     * Scope to active programs
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to programs with auto-enrollment
     */
    public function scopeAutoEnroll($query)
    {
        return $query->where('auto_enroll', true);
    }

    /**
     * Scope by program type
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope to programs with referral features
     */
    public function scopeWithReferrals($query)
    {
        return $query->where(function ($q) {
            $q->where('referrer_reward_points', '>', 0)
              ->orWhere('referee_reward_points', '>', 0);
        });
    }
}