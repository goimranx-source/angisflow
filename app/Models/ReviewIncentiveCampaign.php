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
 * Review Incentive Campaign Model
 *
 * Manages campaigns to encourage customers to leave reviews
 * through targeted messaging and reward incentives.
 */
class ReviewIncentiveCampaign extends Model
{
    use HasFactory, HasPublicId, BelongsToAccount, BelongsToBusiness, SoftDeletes;

    protected $fillable = [
        'name',
        'description',
        'is_active',
        'trigger_rules',
        'customer_filters',
        'days_after_purchase',
        'max_requests_per_customer',
        'request_interval_days',
        'incentive_type',
        'incentive_amount',
        'incentive_percentage',
        'incentive_description',
        'incentive_requires_approval',
        'email_template',
        'sms_template',
        'push_template',
        'review_page_url',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'trigger_rules' => 'array',
        'customer_filters' => 'array',
        'days_after_purchase' => 'integer',
        'max_requests_per_customer' => 'integer',
        'request_interval_days' => 'integer',
        'incentive_amount' => 'integer',
        'incentive_percentage' => 'decimal:2',
        'incentive_requires_approval' => 'boolean',
        'email_template' => 'array',
        'sms_template' => 'array',
        'push_template' => 'array',
        'requests_sent' => 'integer',
        'reviews_received' => 'integer',
        'incentives_granted' => 'integer',
        'conversion_rate' => 'decimal:2',
    ];

    // ── Relationships ────────────────────────────────────────────────────────

    /**
     * Review requests sent through this campaign
     */
    public function reviewRequests(): HasMany
    {
        return $this->hasMany(ReviewRequest::class);
    }

    /**
     * Recent review requests
     */
    public function recentRequests(): HasMany
    {
        return $this->reviewRequests()
            ->orderBy('created_at', 'desc')
            ->limit(50);
    }

    // ── Campaign Status ──────────────────────────────────────────────────────

    /**
     * Check if campaign is active and can send requests
     */
    public function canSendRequests(): bool
    {
        return $this->is_active;
    }

    /**
     * Activate campaign
     */
    public function activate(): void
    {
        $this->update(['is_active' => true]);
    }

    /**
     * Deactivate campaign
     */
    public function deactivate(): void
    {
        $this->update(['is_active' => false]);
    }

    // ── Trigger Rules Evaluation ─────────────────────────────────────────────

    /**
     * Check if order triggers review request
     */
    public function shouldTriggerForOrder(Order $order): bool
    {
        if (!$this->canSendRequests()) {
            return false;
        }

        // Check trigger rules
        foreach ($this->trigger_rules as $rule) {
            if (!$this->evaluateTriggerRule($order, $rule)) {
                return false;
            }
        }

        // Check customer eligibility
        if (!$this->isCustomerEligible($order->customer)) {
            return false;
        }

        // Check if customer has reached request limits
        if ($this->hasReachedRequestLimit($order->customer)) {
            return false;
        }

        return true;
    }

    /**
     * Evaluate individual trigger rule
     */
    private function evaluateTriggerRule(Order $order, array $rule): bool
    {
        $type = $rule['type'] ?? null;
        
        return match($type) {
            'order_status' => in_array($order->status, $rule['statuses'] ?? []),
            'order_value' => $order->total >= ($rule['minimum_amount'] ?? 0),
            'product_category' => $this->orderContainsCategory($order, $rule['categories'] ?? []),
            'product_type' => $this->orderContainsProductType($order, $rule['types'] ?? []),
            'customer_type' => $this->customerMatchesType($order->customer, $rule['customer_types'] ?? []),
            'days_since_order' => $order->created_at->diffInDays(now()) >= ($rule['days'] ?? 0),
            default => true,
        };
    }

    /**
     * Check if order contains products from specified categories
     */
    private function orderContainsCategory(Order $order, array $categories): bool
    {
        if (empty($categories)) {
            return true;
        }

        return $order->lines()
            ->whereHas('product.category', function ($query) use ($categories) {
                $query->whereIn('name', $categories);
            })
            ->exists();
    }

    /**
     * Check if order contains products of specified types
     */
    private function orderContainsProductType(Order $order, array $types): bool
    {
        if (empty($types)) {
            return true;
        }

        return $order->lines()
            ->whereHas('product', function ($query) use ($types) {
                $query->whereIn('type', $types);
            })
            ->exists();
    }

    /**
     * Check if customer matches specified types
     */
    private function customerMatchesType(Customer $customer, array $types): bool
    {
        if (empty($types)) {
            return true;
        }

        // This could check customer segments, tiers, etc.
        return true; // Simplified for now
    }

    /**
     * Check if customer is eligible based on filters
     */
    private function isCustomerEligible(Customer $customer): bool
    {
        $filters = $this->customer_filters ?? [];

        foreach ($filters as $filter) {
            if (!$this->evaluateCustomerFilter($customer, $filter)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Evaluate customer filter
     */
    private function evaluateCustomerFilter(Customer $customer, array $filter): bool
    {
        $field = $filter['field'] ?? null;
        $operator = $filter['operator'] ?? 'equals';
        $value = $filter['value'] ?? null;

        $customerValue = $customer->getAttribute($field);

        return match($operator) {
            'equals' => $customerValue == $value,
            'not_equals' => $customerValue != $value,
            'greater_than' => $customerValue > $value,
            'less_than' => $customerValue < $value,
            'contains' => str_contains((string)$customerValue, (string)$value),
            'in' => in_array($customerValue, (array)$value),
            'not_in' => !in_array($customerValue, (array)$value),
            default => true,
        };
    }

    /**
     * Check if customer has reached request limits
     */
    private function hasReachedRequestLimit(Customer $customer): bool
    {
        $totalRequests = $this->reviewRequests()
            ->where('customer_id', $customer->id)
            ->count();

        if ($totalRequests >= $this->max_requests_per_customer) {
            return true;
        }

        // Check interval-based limits
        if ($this->request_interval_days > 0) {
            $recentRequests = $this->reviewRequests()
                ->where('customer_id', $customer->id)
                ->where('sent_at', '>=', now()->subDays($this->request_interval_days))
                ->count();

            if ($recentRequests > 0) {
                return true;
            }
        }

        return false;
    }

    // ── Review Request Creation ──────────────────────────────────────────────

    /**
     * Create review request for order
     */
    public function createReviewRequest(Order $order, ?Product $product = null): ReviewRequest
    {
        if (!$this->shouldTriggerForOrder($order)) {
            throw new \InvalidArgumentException('Order does not trigger review request');
        }

        $template = $this->getMessageTemplate('email');
        $content = $this->personalizeMessage($template, $order, $product);

        $request = $this->reviewRequests()->create([
            'customer_id' => $order->customer_id,
            'order_id' => $order->id,
            'product_id' => $product?->id,
            'request_type' => 'email',
            'message_content' => $content,
            'review_token' => $this->generateReviewToken(),
            'sent_at' => now(),
            'expires_at' => now()->addDays(30),
            'incentive_offered' => $this->hasIncentive(),
            'incentive_amount' => $this->incentive_amount,
        ]);

        // Update campaign stats
        $this->increment('requests_sent');

        return $request;
    }

    /**
     * Get message template for channel
     */
    private function getMessageTemplate(string $channel): array
    {
        return match($channel) {
            'email' => $this->email_template ?? $this->getDefaultEmailTemplate(),
            'sms' => $this->sms_template ?? $this->getDefaultSmsTemplate(),
            'push' => $this->push_template ?? $this->getDefaultPushTemplate(),
            default => $this->getDefaultEmailTemplate(),
        };
    }

    /**
     * Personalize message content with order/customer data
     */
    private function personalizeMessage(array $template, Order $order, ?Product $product = null): string
    {
        $content = $template['content'] ?? $template['body'] ?? '';
        
        $replacements = [
            '{{customer_name}}' => $order->customer->name,
            '{{order_number}}' => $order->order_number,
            '{{product_name}}' => $product?->name ?? 'your recent purchase',
            '{{business_name}}' => $order->business->name,
            '{{incentive_description}}' => $this->incentive_description ?? '',
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $content);
    }

    /**
     * Generate unique review token
     */
    private function generateReviewToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    // ── Incentive Management ─────────────────────────────────────────────────

    /**
     * Check if campaign has incentives
     */
    public function hasIncentive(): bool
    {
        return $this->incentive_type !== 'none' && 
               ($this->incentive_amount > 0 || $this->incentive_percentage > 0);
    }

    /**
     * Get incentive details
     */
    public function getIncentiveDetails(): array
    {
        if (!$this->hasIncentive()) {
            return [];
        }

        return [
            'type' => $this->incentive_type,
            'amount' => $this->incentive_amount,
            'percentage' => $this->incentive_percentage,
            'description' => $this->incentive_description,
            'requires_approval' => $this->incentive_requires_approval,
        ];
    }

    /**
     * Grant incentive to customer for review
     */
    public function grantIncentive(Customer $customer, ProductReview $review): bool
    {
        if (!$this->hasIncentive()) {
            return false;
        }

        $success = match($this->incentive_type) {
            'points' => $this->grantPointsIncentive($customer, $review),
            'discount' => $this->grantDiscountIncentive($customer, $review),
            'cashback' => $this->grantCashbackIncentive($customer, $review),
            default => false,
        };

        if ($success) {
            $this->increment('incentives_granted');
        }

        return $success;
    }

    /**
     * Grant points incentive
     */
    private function grantPointsIncentive(Customer $customer, ProductReview $review): bool
    {
        // Find customer's loyalty membership
        $membership = LoyaltyMembership::where('customer_id', $customer->id)
            ->whereHas('loyaltyProgram', function ($query) {
                $query->where('business_id', $this->business_id)
                      ->where('is_active', true);
            })
            ->first();

        if (!$membership) {
            return false;
        }

        $membership->addPoints(
            $this->incentive_amount,
            "Review incentive for product review",
            'review',
            (string)$review->id
        );

        return true;
    }

    /**
     * Grant discount incentive
     */
    private function grantDiscountIncentive(Customer $customer, ProductReview $review): bool
    {
        // TODO: Create discount coupon for customer
        return true;
    }

    /**
     * Grant cashback incentive
     */
    private function grantCashbackIncentive(Customer $customer, ProductReview $review): bool
    {
        // TODO: Credit cashback to customer account
        return true;
    }

    // ── Performance Analytics ────────────────────────────────────────────────

    /**
     * Update campaign performance metrics
     */
    public function updatePerformanceMetrics(): void
    {
        $totalRequests = $this->reviewRequests()->count();
        $totalReviews = $this->reviewRequests()
            ->whereNotNull('product_review_id')
            ->count();

        $conversionRate = $totalRequests > 0 ? ($totalReviews / $totalRequests) * 100 : 0;

        $this->update([
            'requests_sent' => $totalRequests,
            'reviews_received' => $totalReviews,
            'conversion_rate' => round($conversionRate, 2),
        ]);
    }

    /**
     * Get campaign performance summary
     */
    public function getPerformanceSummary(): array
    {
        return [
            'requests_sent' => $this->requests_sent,
            'reviews_received' => $this->reviews_received,
            'incentives_granted' => $this->incentives_granted,
            'conversion_rate' => $this->conversion_rate,
            'click_through_rate' => $this->calculateClickThroughRate(),
            'average_rating' => $this->calculateAverageRating(),
        ];
    }

    /**
     * Calculate click-through rate
     */
    private function calculateClickThroughRate(): float
    {
        $totalRequests = $this->reviewRequests()->count();
        $clickedRequests = $this->reviewRequests()
            ->whereNotNull('clicked_at')
            ->count();

        return $totalRequests > 0 ? ($clickedRequests / $totalRequests) * 100 : 0;
    }

    /**
     * Calculate average rating from campaign reviews
     */
    private function calculateAverageRating(): float
    {
        return $this->reviewRequests()
            ->whereNotNull('product_review_id')
            ->join('product_reviews', 'review_requests.product_review_id', '=', 'product_reviews.id')
            ->avg('product_reviews.rating') ?? 0;
    }

    // ── Default Templates ────────────────────────────────────────────────────

    /**
     * Get default email template
     */
    private function getDefaultEmailTemplate(): array
    {
        return [
            'subject' => 'How was your recent purchase?',
            'content' => "Hi {{customer_name}},\n\nWe hope you're enjoying your recent purchase from {{business_name}}! We'd love to hear about your experience.\n\nWould you mind taking a moment to leave a review? {{incentive_description}}\n\nThank you for your feedback!",
        ];
    }

    /**
     * Get default SMS template
     */
    private function getDefaultSmsTemplate(): array
    {
        return [
            'content' => "Hi {{customer_name}}! How was your recent purchase from {{business_name}}? We'd love a quick review. {{incentive_description}}",
        ];
    }

    /**
     * Get default push notification template
     */
    private function getDefaultPushTemplate(): array
    {
        return [
            'title' => 'Leave a Review',
            'content' => "How was your recent purchase? Share your experience and {{incentive_description}}",
        ];
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    /**
     * Scope to active campaigns
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope by incentive type
     */
    public function scopeWithIncentiveType($query, string $type)
    {
        return $query->where('incentive_type', $type);
    }

    /**
     * Scope to campaigns with incentives
     */
    public function scopeWithIncentives($query)
    {
        return $query->where('incentive_type', '!=', 'none')
                    ->where(function ($q) {
                        $q->where('incentive_amount', '>', 0)
                          ->orWhere('incentive_percentage', '>', 0);
                    });
    }
}