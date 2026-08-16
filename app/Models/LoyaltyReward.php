<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tenancy\Concerns\BelongsToAccount;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use App\Domain\Shared\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Loyalty Reward Model
 *
 * Represents rewards available for redemption in loyalty programs,
 * including products, discounts, services, and experiences.
 */
class LoyaltyReward extends Model
{
    use HasFactory, HasPublicId, BelongsToAccount, BelongsToBusiness, SoftDeletes;

    protected $fillable = [
        'loyalty_program_id',
        'name',
        'description',
        'type',
        'is_active',
        'sort_order',
        'points_cost',
        'cash_value',
        'currency',
        'stock_quantity',
        'max_per_customer',
        'max_per_period',
        'max_period_type',
        'eligibility_rules',
        'reward_config',
        'product_id',
        'discount_code',
        'discount_percentage',
        'discount_amount',
        'image_url',
        'images',
        'terms_conditions',
        'redemption_instructions',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'points_cost' => 'integer',
        'cash_value' => 'integer',
        'stock_quantity' => 'integer',
        'max_per_customer' => 'integer',
        'max_per_period' => 'integer',
        'eligibility_rules' => 'array',
        'reward_config' => 'array',
        'discount_percentage' => 'decimal:2',
        'discount_amount' => 'integer',
        'images' => 'array',
        'terms_conditions' => 'array',
        'redemption_instructions' => 'array',
        'redemption_count' => 'integer',
        'total_points_redeemed' => 'integer',
        'last_redeemed_at' => 'datetime',
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
     * Related product (for product rewards)
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    // ── Reward Availability ──────────────────────────────────────────────────

    /**
     * Check if reward is currently available
     */
    public function isAvailable(): bool
    {
        return $this->is_active && 
               $this->hasStock() && 
               $this->loyaltyProgram->is_active;
    }

    /**
     * Check if reward has stock available
     */
    public function hasStock(): bool
    {
        return is_null($this->stock_quantity) || $this->stock_quantity > 0;
    }

    /**
     * Check if customer can redeem this reward
     */
    public function canBeRedeemedBy(LoyaltyMembership $membership): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        // Check points balance
        if ($membership->points_balance < $this->points_cost) {
            return false;
        }

        // Check eligibility rules
        if (!$this->meetsEligibilityRules($membership)) {
            return false;
        }

        // Check per-customer limits
        if ($this->max_per_customer && $this->getCustomerRedemptionCount($membership->customer_id) >= $this->max_per_customer) {
            return false;
        }

        // Check period-based limits
        if ($this->max_per_period && $this->max_period_type && $this->isPeriodLimitReached($membership->customer_id)) {
            return false;
        }

        return true;
    }

    /**
     * Check if customer meets eligibility rules
     */
    private function meetsEligibilityRules(LoyaltyMembership $membership): bool
    {
        $rules = $this->eligibility_rules ?? [];

        foreach ($rules as $rule) {
            if (!$this->evaluateEligibilityRule($membership, $rule)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Evaluate individual eligibility rule
     */
    private function evaluateEligibilityRule(LoyaltyMembership $membership, array $rule): bool
    {
        $type = $rule['type'] ?? null;
        
        return match($type) {
            'tier' => $this->checkTierRequirement($membership, $rule),
            'points_balance' => $membership->points_balance >= ($rule['minimum'] ?? 0),
            'membership_age' => $membership->enrolled_at->diffInDays(now()) >= ($rule['days'] ?? 0),
            'total_spent' => $membership->tier_spend_amount >= ($rule['amount'] ?? 0),
            default => true,
        };
    }

    /**
     * Check tier requirement
     */
    private function checkTierRequirement(LoyaltyMembership $membership, array $rule): bool
    {
        $requiredTiers = $rule['tiers'] ?? [];
        return in_array($membership->current_tier, $requiredTiers);
    }

    /**
     * Get customer redemption count for this reward
     */
    private function getCustomerRedemptionCount(int $customerId): int
    {
        return LoyaltyTransaction::where('customer_id', $customerId)
            ->where('source_type', 'reward')
            ->where('source_id', (string)$this->id)
            ->where('type', 'redeemed')
            ->where('status', 'completed')
            ->count();
    }

    /**
     * Check if period limit is reached for customer
     */
    private function isPeriodLimitReached(int $customerId): bool
    {
        $period = $this->getPeriodDateRange();
        
        $periodRedemptions = LoyaltyTransaction::where('customer_id', $customerId)
            ->where('source_type', 'reward')
            ->where('source_id', (string)$this->id)
            ->where('type', 'redeemed')
            ->where('status', 'completed')
            ->whereBetween('created_at', [$period['start'], $period['end']])
            ->count();

        return $periodRedemptions >= $this->max_per_period;
    }

    /**
     * Get date range for period-based limits
     */
    private function getPeriodDateRange(): array
    {
        $now = now();
        
        return match($this->max_period_type) {
            'day' => [
                'start' => $now->startOfDay(),
                'end' => $now->endOfDay(),
            ],
            'week' => [
                'start' => $now->startOfWeek(),
                'end' => $now->endOfWeek(),
            ],
            'month' => [
                'start' => $now->startOfMonth(),
                'end' => $now->endOfMonth(),
            ],
            'year' => [
                'start' => $now->startOfYear(),
                'end' => $now->endOfYear(),
            ],
            default => [
                'start' => $now->startOfDay(),
                'end' => $now->endOfDay(),
            ],
        };
    }

    // ── Reward Redemption ────────────────────────────────────────────────────

    /**
     * Redeem this reward for a customer
     */
    public function redeemFor(LoyaltyMembership $membership): array
    {
        if (!$this->canBeRedeemedBy($membership)) {
            throw new \InvalidArgumentException('Reward cannot be redeemed by this customer');
        }

        // Deduct points
        $transaction = $membership->redeemPoints(
            $this->points_cost,
            "Redeemed reward: {$this->name}",
            'reward',
            (string)$this->id
        );

        // Generate reward fulfillment data
        $fulfillmentData = $this->generateFulfillmentData($membership);

        // Update stock if limited
        if ($this->stock_quantity) {
            $this->decrement('stock_quantity');
        }

        // Update redemption analytics
        $this->increment('redemption_count');
        $this->increment('total_points_redeemed', $this->points_cost);
        $this->update(['last_redeemed_at' => now()]);

        return [
            'transaction' => $transaction,
            'fulfillment' => $fulfillmentData,
            'instructions' => $this->redemption_instructions,
        ];
    }

    /**
     * Generate fulfillment data based on reward type
     */
    private function generateFulfillmentData(LoyaltyMembership $membership): array
    {
        return match($this->type) {
            'product' => $this->generateProductFulfillment($membership),
            'discount' => $this->generateDiscountFulfillment($membership),
            'shipping' => $this->generateShippingFulfillment($membership),
            'cashback' => $this->generateCashbackFulfillment($membership),
            'service' => $this->generateServiceFulfillment($membership),
            'experience' => $this->generateExperienceFulfillment($membership),
            default => ['type' => 'manual', 'message' => 'Please contact support for reward fulfillment'],
        };
    }

    /**
     * Generate product fulfillment data
     */
    private function generateProductFulfillment(LoyaltyMembership $membership): array
    {
        if (!$this->product) {
            return ['type' => 'error', 'message' => 'Product not found'];
        }

        return [
            'type' => 'product',
            'product_id' => $this->product->public_id,
            'product_name' => $this->product->name,
            'sku' => $this->product->sku,
            'message' => 'Product will be added to your next order or shipped separately',
        ];
    }

    /**
     * Generate discount fulfillment data
     */
    private function generateDiscountFulfillment(LoyaltyMembership $membership): array
    {
        $code = $this->discount_code ?? $this->generateDiscountCode($membership);
        
        return [
            'type' => 'discount',
            'code' => $code,
            'percentage' => $this->discount_percentage,
            'amount' => $this->discount_amount,
            'expires_at' => now()->addDays(30)->toISOString(),
            'message' => "Use discount code: {$code}",
        ];
    }

    /**
     * Generate shipping fulfillment data
     */
    private function generateShippingFulfillment(LoyaltyMembership $membership): array
    {
        $code = $this->generateShippingCode($membership);
        
        return [
            'type' => 'shipping',
            'code' => $code,
            'message' => "Free shipping code: {$code}",
        ];
    }

    /**
     * Generate cashback fulfillment data
     */
    private function generateCashbackFulfillment(LoyaltyMembership $membership): array
    {
        return [
            'type' => 'cashback',
            'amount' => $this->cash_value,
            'currency' => $this->currency,
            'message' => 'Cashback will be credited to your account',
        ];
    }

    /**
     * Generate service fulfillment data
     */
    private function generateServiceFulfillment(LoyaltyMembership $membership): array
    {
        return [
            'type' => 'service',
            'config' => $this->reward_config,
            'message' => 'Service booking details have been sent to your email',
        ];
    }

    /**
     * Generate experience fulfillment data
     */
    private function generateExperienceFulfillment(LoyaltyMembership $membership): array
    {
        return [
            'type' => 'experience',
            'config' => $this->reward_config,
            'message' => 'Experience details will be provided separately',
        ];
    }

    /**
     * Generate unique discount code
     */
    private function generateDiscountCode(LoyaltyMembership $membership): string
    {
        return strtoupper(substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 8));
    }

    /**
     * Generate unique shipping code
     */
    private function generateShippingCode(LoyaltyMembership $membership): string
    {
        return 'SHIP' . strtoupper(substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 6));
    }

    // ── Display Helpers ──────────────────────────────────────────────────────

    /**
     * Get formatted points cost
     */
    public function getFormattedPointsCost(): string
    {
        return number_format($this->points_cost) . ' ' . ($this->loyaltyProgram->points_name ?? 'Points');
    }

    /**
     * Get formatted cash value
     */
    public function getFormattedCashValue(): ?string
    {
        if (!$this->cash_value) {
            return null;
        }

        $value = $this->cash_value / 100; // Convert from minor units
        return $this->currency . ' ' . number_format($value, 2);
    }

    /**
     * Get reward type display name
     */
    public function getTypeDisplayName(): string
    {
        return match($this->type) {
            'product' => 'Product',
            'discount' => 'Discount',
            'shipping' => 'Free Shipping',
            'service' => 'Service',
            'experience' => 'Experience',
            'cashback' => 'Cashback',
            default => ucfirst($this->type),
        };
    }

    /**
     * Get stock status display
     */
    public function getStockStatus(): string
    {
        if (is_null($this->stock_quantity)) {
            return 'Unlimited';
        }

        if ($this->stock_quantity === 0) {
            return 'Out of Stock';
        }

        return "{$this->stock_quantity} Available";
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    /**
     * Scope to active rewards
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to rewards with stock available
     */
    public function scopeInStock($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('stock_quantity')
              ->orWhere('stock_quantity', '>', 0);
        });
    }

    /**
     * Scope to available rewards
     */
    public function scopeAvailable($query)
    {
        return $query->active()->inStock();
    }

    /**
     * Scope by reward type
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope by points cost range
     */
    public function scopeInPointsRange($query, int $min, int $max)
    {
        return $query->whereBetween('points_cost', [$min, $max]);
    }

    /**
     * Scope ordered by sort order
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }
}