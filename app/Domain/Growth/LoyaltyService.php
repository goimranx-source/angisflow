<?php

declare(strict_types=1);

namespace App\Domain\Growth;

use App\Domain\Tenancy\TenantContext;
use App\Domain\Shared\ValueObjects\Money;
use App\Models\LoyaltyProgram;
use App\Models\LoyaltyMembership;
use App\Models\LoyaltyTransaction;
use App\Models\LoyaltyReward;
use App\Domain\Sales\Models\Customer;
use App\Domain\Sales\Models\Order;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;

/**
 * Loyalty Service
 *
 * Manages loyalty programs, point transactions, tier calculations,
 * and reward redemption for customer retention and engagement.
 *
 * ── Business Rules ─────────────────────────────────────────────────────────
 *
 * Points are awarded based on configurable earning rules and can be earned
 * through purchases, reviews, referrals, and custom activities. Tiers are
 * calculated automatically based on points earned or spending thresholds.
 * All transactions are immutable for audit purposes.
 */
class LoyaltyService
{
    public function __construct(
        private TenantContext $tenantContext
    ) {}

    // ── Program Management ────────────────────────────────────────────────────

    /**
     * Create a new loyalty program
     *
     * ── Written against the table, not against a remembered one ──────────────
     *
     * This method used to write `status`, `point_name`, `point_name_plural`,
     * `tier_structure`, `referral_program`, `configuration`, `started_at` and
     * `ended_at` — eight fields `loyalty_programs` has never had. With
     * `preventSilentlyDiscardingAttributes()` on, that is not a subtle drift:
     * creating a loyalty program threw outright, so the feature could not be
     * used at all. The real columns below carry the same meanings:
     *
     *   status ('active')   → is_active (boolean)
     *   point_name          → points_name
     *   tier_structure      → tier_levels
     *   referral_program    → referral_config
     *
     * `point_name_plural`, `started_at` and `ended_at` have no column and no
     * reader, so they are gone rather than parked somewhere harmless — a field
     * that is written and never read is a promise the schema does not keep.
     */
    public function createProgram(array $data): LoyaltyProgram
    {
        return DB::transaction(function () use ($data) {
            return LoyaltyProgram::create([
                // account_id and business_id are deliberately absent: the
                // BelongsToAccount / BelongsToBusiness traits fill them from
                // TenantContext on create, and they are not fillable precisely
                // so that a caller cannot post a row into another subscriber's
                // account by naming one.
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'type' => $data['type'] ?? 'points',
                'is_active' => $data['is_active'] ?? true,
                'auto_enroll' => $data['auto_enroll'] ?? false,
                'points_name' => $data['points_name'] ?? 'Points',
                // The welcome bonus lives here rather than in a general-purpose
                // config blob: it is a rule about how points are earned, and
                // enrollCustomer() reads it back from exactly this place.
                'earning_rules' => $data['earning_rules'] ?? [
                    'per_dollar_spent' => 1,
                    'minimum_order_amount' => 0,
                    'excluded_categories' => [],
                    'multiplier_events' => [],
                    'welcome_bonus' => 0,
                ],
                'redemption_rules' => $data['redemption_rules'] ?? [
                    'minimum_redemption' => 100,
                    'point_value' => 0.01, // $0.01 per point
                    'maximum_per_order' => null,
                    'expiration_days' => 365,
                ],
                'tier_levels' => $data['tier_levels'] ?? [],
                'referral_config' => $data['referral_config'] ?? [
                    'enabled' => false,
                    'referrer_points' => 0,
                    'referee_points' => 0,
                    'minimum_order_amount' => 0,
                ],
            ]);
        });
    }

    /**
     * Update program configuration
     */
    public function updateProgram(int $programId, array $data): LoyaltyProgram
    {
        $program = LoyaltyProgram::findOrFail($programId);

        // Cannot modify core rules if program has active members
        if ($program->hasMemberships() && isset($data['earning_rules'])) {
            throw new \InvalidArgumentException('Cannot modify earning rules for program with active members');
        }

        $program->update($data);
        return $program;
    }

    // ── Member Management ─────────────────────────────────────────────────────

    /**
     * Enroll customer in loyalty program
     */
    public function enrollCustomer(int $programId, int $customerId, array $data = []): LoyaltyMembership
    {
        $program = LoyaltyProgram::findOrFail($programId);
        $customer = Customer::findOrFail($customerId);

        if (!$program->isActive()) {
            throw new \InvalidArgumentException('Cannot enroll in inactive program');
        }

        // Check if already enrolled
        $existing = LoyaltyMembership::where('loyalty_program_id', $programId)
            ->where('customer_id', $customerId)
            ->first();

        if ($existing) {
            throw new \InvalidArgumentException('Customer already enrolled in program');
        }

        /*
         * A referral arrives as somebody else's code, but it is stored as the
         * customer it belongs to.
         *
         * Keeping the code would mean every later question about a referral
         * ("who introduced this customer, and are they still a customer?")
         * re-resolves a string that its owner is free to regenerate — see
         * regenerateReferralCode(). Resolving it once, here, is what makes the
         * link survive that.
         *
         * An unrecognised code enrols the customer anyway rather than refusing:
         * a mistyped referral is not a reason to turn away a signup, and a
         * silent null is honest about what we could not establish.
         */
        $referredByCustomerId = null;

        if (! empty($data['referred_by_code'])) {
            $referredByCustomerId = LoyaltyMembership::query()
                ->where('referral_code', $data['referred_by_code'])
                ->value('customer_id');
        }

        return DB::transaction(function () use ($program, $customer, $data, $referredByCustomerId) {
            $membership = LoyaltyMembership::create([
                'loyalty_program_id' => $program->id,
                'customer_id' => $customer->id,
                'status' => 'active',
                'points_balance' => 0,
                'points_lifetime_earned' => 0,
                'points_lifetime_redeemed' => 0,
                'current_tier' => $data['current_tier'] ?? null,
                'tier_points' => 0,
                'referral_code' => $this->generateReferralCode($customer),
                'referred_by_customer_id' => $referredByCustomerId,
                'enrolled_at' => now(),
                // No 'metadata' here: loyalty_memberships has no such column,
                // and writing one silently discarded the caller's value. The
                // membership's own shaped fields (preferences, tags,
                // notification_preferences) are where per-member data belongs.
            ]);

            // Award welcome bonus if configured. Read from earning_rules —
            // `configuration` is not a column on this table, so the old read
            // was always null and the bonus was never once awarded.
            $welcomeBonus = (int) ($program->earning_rules['welcome_bonus'] ?? 0);

            if ($welcomeBonus > 0) {
                $this->awardPoints(
                    $membership->id,
                    $welcomeBonus,
                    'welcome_bonus',
                    null,
                    'Welcome bonus'
                );
            }

            return $membership;
        });
    }

    /**
     * Update membership status or tier
     */
    public function updateMembership(int $membershipId, array $data): LoyaltyMembership
    {
        $membership = LoyaltyMembership::findOrFail($membershipId);
        $membership->update($data);

        return $membership;
    }

    // ── Points Management ─────────────────────────────────────────────────────

    /**
     * Award points to a member
     */
    public function awardPoints(
        int $membershipId,
        int $points,
        string $sourceType,
        ?int $sourceId = null,
        ?string $description = null,
        array $metadata = []
    ): LoyaltyTransaction {
        $membership = LoyaltyMembership::findOrFail($membershipId);

        return DB::transaction(function () use ($membership, $points, $sourceType, $sourceId, $description, $metadata) {
            // Create transaction record
            $transaction = LoyaltyTransaction::create([
                'loyalty_membership_id' => $membership->id,
                // Denormalised on purpose: the column is NOT NULL, and a points
                // ledger that cannot answer "whose points?" without a join is not
                // much of a ledger.
                'customer_id' => $membership->customer_id,
                'type' => 'earned',
                'points_amount' => $points,
                'balance_after' => $membership->points_balance + $points,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'description' => $description ?? "Points earned from {$sourceType}",
                'expires_at' => $this->calculateExpirationDate($membership->loyaltyProgram, $points),
                'processed_by_user_id' => auth()->id(),
                'metadata' => $metadata,
            ]);

            // Update membership balance and lifetime earnings
            $membership->increment('points_balance', $points);
            $membership->increment('points_lifetime_earned', $points);

            // Check for tier advancement
            $this->evaluateTierProgression($membership);

            return $transaction;
        });
    }

    /**
     * Redeem points for a reward or discount
     */
    public function redeemPoints(
        int $membershipId,
        int $points,
        string $rewardType,
        ?int $rewardId = null,
        ?string $description = null,
        array $metadata = []
    ): LoyaltyTransaction {
        $membership = LoyaltyMembership::findOrFail($membershipId);

        if ($membership->points_balance < $points) {
            throw new \InvalidArgumentException('Insufficient points balance');
        }

        // Validate redemption rules
        $program = $membership->loyaltyProgram;
        $redemptionRules = $program->redemption_rules;

        if ($points < ($redemptionRules['minimum_redemption'] ?? 0)) {
            throw new \InvalidArgumentException('Points below minimum redemption threshold');
        }

        return DB::transaction(function () use ($membership, $points, $rewardType, $rewardId, $description, $metadata) {
            // Create transaction record
            $transaction = LoyaltyTransaction::create([
                'loyalty_membership_id' => $membership->id,
                // Denormalised on purpose: the column is NOT NULL, and a points
                // ledger that cannot answer "whose points?" without a join is not
                // much of a ledger.
                'customer_id' => $membership->customer_id,
                'type' => 'redeemed',
                'points_amount' => -$points,
                'balance_after' => $membership->points_balance - $points,
                'source_type' => $rewardType,
                'source_id' => $rewardId,
                'description' => $description ?? "Points redeemed for {$rewardType}",
                'processed_by_user_id' => auth()->id(),
                'metadata' => $metadata,
            ]);

            // Update membership balance and lifetime redemptions
            $membership->decrement('points_balance', $points);
            $membership->increment('points_lifetime_redeemed', $points);

            return $transaction;
        });
    }

    /**
     * Adjust points balance (admin action)
     */
    public function adjustPoints(
        int $membershipId,
        int $points,
        string $reason,
        ?int $processedBy = null,
        array $metadata = []
    ): LoyaltyTransaction {
        $membership = LoyaltyMembership::findOrFail($membershipId);

        return DB::transaction(function () use ($membership, $points, $reason, $processedBy, $metadata) {
            $type = $points > 0 ? 'adjustment_credit' : 'adjustment_debit';

            $transaction = LoyaltyTransaction::create([
                'loyalty_membership_id' => $membership->id,
                // Denormalised on purpose: the column is NOT NULL, and a points
                // ledger that cannot answer "whose points?" without a join is not
                // much of a ledger.
                'customer_id' => $membership->customer_id,
                'type' => $type,
                'points_amount' => $points,
                'balance_after' => $membership->points_balance + $points,
                'source_type' => 'admin_adjustment',
                'source_id' => null,
                'description' => $reason,
                'processed_by_user_id' => $processedBy ?? auth()->id(),
                'metadata' => $metadata,
            ]);

            // Update membership balance
            if ($points > 0) {
                $membership->increment('points_balance', $points);
                $membership->increment('points_lifetime_earned', $points);
            } else {
                $membership->decrement('points_balance', abs($points));
                $membership->increment('points_lifetime_redeemed', abs($points));
            }

            return $transaction;
        });
    }

    // ── Order Integration ─────────────────────────────────────────────────────

    /**
     * Process points earning from an order
     */
    public function processOrderEarning(Order $order): ?LoyaltyTransaction
    {
        $customer = $order->customer;
        if (!$customer) {
            return null;
        }

        // Find active loyalty membership
        $membership = $this->getActiveMembership($customer->id);
        if (!$membership) {
            return null;
        }

        $program = $membership->loyaltyProgram;
        $earningRules = $program->earning_rules;

        // Calculate points based on earning rules
        $orderAmount = $order->total->toDecimal();
        $pointsEarned = $this->calculateOrderPoints($orderAmount, $earningRules, $order);

        if ($pointsEarned <= 0) {
            return null;
        }

        return $this->awardPoints(
            $membership->id,
            $pointsEarned,
            'order',
            $order->id,
            "Points earned from order #{$order->number}",
            [
                'order_number' => $order->number,
                'order_amount' => $orderAmount,
                'earning_rate' => $earningRules['per_dollar_spent'] ?? 1,
            ]
        );
    }

    // ── Tier Management ───────────────────────────────────────────────────────

    /**
     * Evaluate and update member tier
     */
    public function evaluateTierProgression(LoyaltyMembership $membership): ?string
    {
        $program = $membership->loyaltyProgram;
        $tierStructure = $program->tier_structure;

        if (!($tierStructure['enabled'] ?? false) || empty($tierStructure['tiers'])) {
            return null;
        }

        $currentPoints = $membership->points_lifetime_earned;
        $newTier = null;
        $progress = 0;

        // Find appropriate tier based on points
        foreach ($tierStructure['tiers'] as $tier) {
            if ($currentPoints >= ($tier['threshold'] ?? 0)) {
                $newTier = $tier['name'];
                
                // Calculate progress to next tier
                $nextTierThreshold = $this->getNextTierThreshold($tierStructure['tiers'], $tier['name']);
                if ($nextTierThreshold) {
                    $progress = min(100, (($currentPoints - $tier['threshold']) / ($nextTierThreshold - $tier['threshold'])) * 100);
                } else {
                    $progress = 100; // Highest tier
                }
            }
        }

        // Update membership if tier changed
        if ($newTier && $newTier !== $membership->current_tier) {
            $membership->update([
                'current_tier' => $newTier,
                'tier_points' => $progress,
                'tier_updated_at' => now(),
            ]);

            // Award tier bonus if configured
            $this->processTierBonus($membership, $newTier, $tierStructure['tiers']);
        } else {
            $membership->update(['tier_points' => $progress]);
        }

        return $newTier;
    }

    // ── Rewards Management ────────────────────────────────────────────────────

    /**
     * Create a loyalty reward
     */
    public function createReward(array $data): LoyaltyReward
    {
        return LoyaltyReward::create([
            'account_id' => $this->tenantContext->account()->id,
            'business_id' => $this->tenantContext->business()->id,
            'loyalty_program_id' => $data['loyalty_program_id'] ?? null,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'type' => $data['type'],
            'points_required' => $data['points_required'],
            'monetary_value' => $data['monetary_value'] ?? null,
            'currency' => $data['currency'] ?? $this->tenantContext->business()->base_currency,
            'stock_quantity' => $data['stock_quantity'] ?? null,
            'stock_remaining' => $data['stock_quantity'] ?? null,
            'per_customer_limit' => $data['per_customer_limit'] ?? null,
            'eligibility_rules' => $data['eligibility_rules'] ?? [],
            'configuration' => $data['configuration'] ?? [],
            'is_active' => $data['is_active'] ?? true,
            'available_from' => isset($data['available_from']) ? Carbon::parse($data['available_from']) : now(),
            'available_until' => isset($data['available_until']) ? Carbon::parse($data['available_until']) : null,
        ]);
    }

    /**
     * Process reward redemption
     */
    public function redeemReward(int $membershipId, int $rewardId): array
    {
        $membership = LoyaltyMembership::findOrFail($membershipId);
        $reward = LoyaltyReward::findOrFail($rewardId);

        return DB::transaction(function () use ($membership, $reward) {
            // Validate reward availability
            if (!$reward->isAvailable()) {
                throw new \InvalidArgumentException('Reward is not available');
            }

            if (!$reward->hasStock()) {
                throw new \InvalidArgumentException('Reward is out of stock');
            }

            // Check customer eligibility
            if (!$this->isEligibleForReward($membership, $reward)) {
                throw new \InvalidArgumentException('Customer not eligible for this reward');
            }

            // Redeem points
            $transaction = $this->redeemPoints(
                $membership->id,
                $reward->points_required,
                'reward',
                $reward->id,
                "Redeemed for: {$reward->name}",
                [
                    'reward_name' => $reward->name,
                    'reward_type' => $reward->type,
                    'monetary_value' => $reward->monetary_value,
                ]
            );

            // Update reward stock
            if ($reward->stock_quantity !== null) {
                $reward->decrement('stock_remaining');
            }

            // Generate fulfillment data based on reward type
            $fulfillment = $this->generateRewardFulfillment($reward, $membership);

            return [
                'transaction' => $transaction,
                'reward' => $reward,
                'fulfillment' => $fulfillment,
            ];
        });
    }

    // ── Helper Methods ────────────────────────────────────────────────────────

    /**
     * Get active membership for customer
     */
    private function getActiveMembership(int $customerId): ?LoyaltyMembership
    {
        return LoyaltyMembership::where('customer_id', $customerId)
            ->whereHas('program', function ($query) {
                $query->where('status', 'active');
            })
            ->where('status', 'active')
            ->first();
    }

    /**
     * Calculate points earned from order
     */
    private function calculateOrderPoints(float $orderAmount, array $earningRules, Order $order): int
    {
        $baseRate = $earningRules['per_dollar_spent'] ?? 1;
        $minimumAmount = $earningRules['minimum_order_amount'] ?? 0;

        if ($orderAmount < $minimumAmount) {
            return 0;
        }

        // Apply base calculation
        $points = (int) floor($orderAmount * $baseRate);

        // Apply multipliers if configured
        if (isset($earningRules['multiplier_events'])) {
            foreach ($earningRules['multiplier_events'] as $event) {
                if ($this->orderMatchesEvent($order, $event)) {
                    $points *= $event['multiplier'] ?? 1;
                }
            }
        }

        return $points;
    }

    /**
     * Calculate point expiration date
     */
    private function calculateExpirationDate(LoyaltyProgram $program, int $points): ?Carbon
    {
        $expirationDays = $program->redemption_rules['expiration_days'] ?? null;
        
        if ($expirationDays === null) {
            return null;
        }

        return now()->addDays($expirationDays);
    }

    /**
     * Generate unique referral code
     */
    private function generateReferralCode(Customer $customer): string
    {
        $base = strtoupper(substr($customer->name, 0, 3) . Str::random(5));
        
        // Ensure uniqueness
        while (LoyaltyMembership::where('referral_code', $base)->exists()) {
            $base = strtoupper(substr($customer->name, 0, 3) . Str::random(5));
        }

        return $base;
    }

    /**
     * Get next tier threshold
     */
    private function getNextTierThreshold(array $tiers, string $currentTier): ?int
    {
        $found = false;
        foreach ($tiers as $tier) {
            if ($found) {
                return $tier['threshold'] ?? null;
            }
            if ($tier['name'] === $currentTier) {
                $found = true;
            }
        }
        return null;
    }

    /**
     * Process tier advancement bonus
     */
    private function processTierBonus(LoyaltyMembership $membership, string $newTier, array $tiers): void
    {
        foreach ($tiers as $tier) {
            if ($tier['name'] === $newTier && isset($tier['bonus_points']) && $tier['bonus_points'] > 0) {
                $this->awardPoints(
                    $membership->id,
                    $tier['bonus_points'],
                    'tier_bonus',
                    null,
                    "Tier advancement bonus for reaching {$newTier}",
                    ['tier' => $newTier]
                );
                break;
            }
        }
    }

    /**
     * Check if customer is eligible for reward
     */
    private function isEligibleForReward(LoyaltyMembership $membership, LoyaltyReward $reward): bool
    {
        // Check points requirement
        if ($membership->points_balance < $reward->points_required) {
            return false;
        }

        // Check per-customer limit
        if ($reward->per_customer_limit !== null) {
            $redeemed = LoyaltyTransaction::where('loyalty_membership_id', $membership->id)
                ->where('source_type', 'reward')
                ->where('source_id', $reward->id)
                ->where('type', 'redeemed')
                ->count();

            if ($redeemed >= $reward->per_customer_limit) {
                return false;
            }
        }

        // Check eligibility rules
        return $this->evaluateEligibilityRules($membership, $reward->eligibility_rules);
    }

    /**
     * Evaluate eligibility rules
     */
    private function evaluateEligibilityRules(LoyaltyMembership $membership, array $rules): bool
    {
        if (empty($rules)) {
            return true;
        }

        // Add rule evaluation logic as needed
        // For now, return true (all eligible)
        return true;
    }

    /**
     * Generate reward fulfillment data
     */
    private function generateRewardFulfillment(LoyaltyReward $reward, LoyaltyMembership $membership): array
    {
        $fulfillment = [
            'reward_id' => $reward->id,
            'loyalty_membership_id' => $membership->id,
            'customer_id' => $membership->customer_id,
            'type' => $reward->type,
            'status' => 'pending',
            'created_at' => now()->toISOString(),
        ];

        switch ($reward->type) {
            case 'discount':
                $fulfillment['discount_code'] = $this->generateDiscountCode();
                $fulfillment['discount_value'] = $reward->configuration['discount_value'] ?? 10;
                $fulfillment['expires_at'] = now()->addDays(30)->toISOString();
                break;

            case 'product':
                $fulfillment['product_id'] = $reward->configuration['product_id'] ?? null;
                $fulfillment['fulfillment_method'] = 'manual';
                break;

            case 'service':
                $fulfillment['service_code'] = $reward->configuration['service_code'] ?? null;
                $fulfillment['booking_required'] = true;
                break;
        }

        return $fulfillment;
    }

    /**
     * Generate discount code
     */
    private function generateDiscountCode(): string
    {
        return 'LOYALTY-' . strtoupper(Str::random(8));
    }

    /**
     * Check if order matches multiplier event
     */
    private function orderMatchesEvent(Order $order, array $event): bool
    {
        // Simple implementation - extend as needed
        return true;
    }
}