<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Customer LTV Analytics Model
 *
 * Tracks customer lifetime value metrics and predictive analytics
 * for marketing optimization and customer segmentation.
 */
class CustomerLtvAnalytics extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'business_id',
        'total_orders',
        'total_spent',
        'average_order_value',
        'purchase_frequency',
        'days_since_first_order',
        'days_since_last_order',
        'predicted_ltv',
        'churn_probability',
        'predicted_next_order_days',
        'predicted_next_order_value',
        'value_segment',
        'loyalty_segment',
        'lifecycle_stage',
        'behavioral_tags',
        'calculation_date',
        'last_updated',
    ];

    protected $casts = [
        'total_orders' => 'integer',
        'total_spent' => 'integer',
        'average_order_value' => 'integer',
        'purchase_frequency' => 'decimal:2',
        'days_since_first_order' => 'integer',
        'days_since_last_order' => 'integer',
        'predicted_ltv' => 'integer',
        'churn_probability' => 'decimal:4',
        'predicted_next_order_days' => 'integer',
        'predicted_next_order_value' => 'integer',
        'behavioral_tags' => 'array',
        'calculation_date' => 'date',
        'last_updated' => 'datetime',
    ];

    // ── Relationships ────────────────────────────────────────────────────────

    /**
     * Customer this analytics record belongs to
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Business this analytics record belongs to
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    // ── Value Calculations ───────────────────────────────────────────────────

    /**
     * Calculate current customer lifetime value
     */
    public function calculateCurrentLtv(): float
    {
        if ($this->total_orders === 0) {
            return 0.0;
        }

        // Simple LTV = Average Order Value × Purchase Frequency × Gross Margin × Lifespan
        // For simplicity, assuming 20% margin and customer lifespan based on frequency
        $margin = 0.20;
        $estimatedLifespanMonths = $this->estimateCustomerLifespan();
        
        return $this->average_order_value * $this->purchase_frequency * $margin * $estimatedLifespanMonths;
    }

    /**
     * Estimate customer lifespan in months
     */
    private function estimateCustomerLifespan(): float
    {
        if ($this->purchase_frequency <= 0) {
            return 12.0; // Default 1 year for inactive customers
        }

        // Higher frequency customers tend to stay longer
        // This is a simplified model
        $baseLifespan = 12.0;
        $frequencyBonus = min($this->purchase_frequency * 2, 24); // Max 2 years bonus
        
        return $baseLifespan + $frequencyBonus;
    }

    /**
     * Calculate predicted next order value based on trend
     */
    public function calculatePredictedNextOrderValue(): int
    {
        if ($this->total_orders < 2) {
            return $this->average_order_value;
        }

        // Apply trending adjustments based on recent behavior
        $trendMultiplier = 1.0;
        
        if ($this->days_since_last_order < 30) {
            $trendMultiplier = 1.1; // Recently active customers might spend more
        } elseif ($this->days_since_last_order > 90) {
            $trendMultiplier = 0.9; // Inactive customers might spend less
        }

        return intval($this->average_order_value * $trendMultiplier);
    }

    // ── Segmentation ──────────────────────────────────────────────────────────

    /**
     * Determine value segment based on spending
     */
    public function determineValueSegment(): string
    {
        $totalSpent = $this->total_spent / 100; // Convert from minor units

        if ($totalSpent >= 10000) {
            return 'VIP';
        } elseif ($totalSpent >= 2500) {
            return 'High Value';
        } elseif ($totalSpent >= 500) {
            return 'Medium Value';
        } else {
            return 'Low Value';
        }
    }

    /**
     * Determine loyalty segment using RFM analysis
     */
    public function determineLoyaltySegment(): string
    {
        $recency = $this->days_since_last_order;
        $frequency = $this->total_orders;
        $monetary = $this->total_spent / 100;

        // Simplified RFM scoring (1-5 scale)
        $recencyScore = $this->scoreRecency($recency);
        $frequencyScore = $this->scoreFrequency($frequency);
        $monetaryScore = $this->scoreMonetary($monetary);

        $totalScore = $recencyScore + $frequencyScore + $monetaryScore;

        return match(true) {
            $totalScore >= 13 => 'Champions',
            $totalScore >= 10 => 'Loyal Customers',
            $totalScore >= 8 => 'Potential Loyalists',
            $totalScore >= 6 => 'New Customers',
            $totalScore >= 4 => 'At Risk',
            default => 'Inactive',
        };
    }

    /**
     * Score recency (1-5, where 5 is most recent)
     */
    private function scoreRecency(int $days): int
    {
        return match(true) {
            $days <= 30 => 5,
            $days <= 60 => 4,
            $days <= 90 => 3,
            $days <= 180 => 2,
            default => 1,
        };
    }

    /**
     * Score frequency (1-5, where 5 is most frequent)
     */
    private function scoreFrequency(int $orders): int
    {
        return match(true) {
            $orders >= 20 => 5,
            $orders >= 10 => 4,
            $orders >= 5 => 3,
            $orders >= 2 => 2,
            default => 1,
        };
    }

    /**
     * Score monetary value (1-5, where 5 is highest spend)
     */
    private function scoreMonetary(float $spent): int
    {
        return match(true) {
            $spent >= 5000 => 5,
            $spent >= 1000 => 4,
            $spent >= 500 => 3,
            $spent >= 100 => 2,
            default => 1,
        };
    }

    /**
     * Determine lifecycle stage
     */
    public function determineLifecycleStage(): string
    {
        $daysSinceFirst = $this->days_since_first_order;
        $daysSinceLast = $this->days_since_last_order;
        $totalOrders = $this->total_orders;

        if ($totalOrders === 1 && $daysSinceFirst <= 30) {
            return 'New';
        } elseif ($totalOrders === 1 && $daysSinceFirst > 30) {
            return 'One-Time';
        } elseif ($totalOrders >= 2 && $daysSinceLast <= 60) {
            return 'Active';
        } elseif ($totalOrders >= 2 && $daysSinceLast <= 180) {
            return 'Dormant';
        } else {
            return 'Churned';
        }
    }

    // ── Churn Prediction ──────────────────────────────────────────────────────

    /**
     * Calculate churn probability using simple logistic model
     */
    public function calculateChurnProbability(): float
    {
        // Simplified churn probability based on recency and frequency
        $recencyWeight = 0.6;
        $frequencyWeight = 0.4;

        // Normalize recency (0-1, where 1 is most likely to churn)
        $normalizedRecency = min($this->days_since_last_order / 365, 1.0);
        
        // Normalize frequency (0-1, where 0 is most likely to churn)
        $normalizedFrequency = 1.0 - min($this->purchase_frequency / 12, 1.0);

        $churnScore = ($recencyWeight * $normalizedRecency) + ($frequencyWeight * $normalizedFrequency);

        // Apply sigmoid function to get probability
        return 1 / (1 + exp(-5 * ($churnScore - 0.5)));
    }

    /**
     * Get churn risk level
     */
    public function getChurnRiskLevel(): string
    {
        return match(true) {
            $this->churn_probability >= 0.8 => 'Critical',
            $this->churn_probability >= 0.6 => 'High',
            $this->churn_probability >= 0.4 => 'Medium',
            $this->churn_probability >= 0.2 => 'Low',
            default => 'Minimal',
        };
    }

    // ── Behavioral Analysis ──────────────────────────────────────────────────

    /**
     * Add behavioral tag
     */
    public function addBehavioralTag(string $tag): void
    {
        $tags = $this->behavioral_tags ?? [];
        if (!in_array($tag, $tags)) {
            $tags[] = $tag;
            $this->update(['behavioral_tags' => $tags]);
        }
    }

    /**
     * Remove behavioral tag
     */
    public function removeBehavioralTag(string $tag): void
    {
        $tags = $this->behavioral_tags ?? [];
        $filtered = array_filter($tags, fn($t) => $t !== $tag);
        $this->update(['behavioral_tags' => array_values($filtered)]);
    }

    /**
     * Check if has behavioral tag
     */
    public function hasBehavioralTag(string $tag): bool
    {
        return in_array($tag, $this->behavioral_tags ?? []);
    }

    /**
     * Generate behavioral insights
     */
    public function generateBehavioralInsights(): array
    {
        $insights = [];

        // Purchase pattern insights
        if ($this->purchase_frequency > 6) {
            $insights[] = 'High frequency buyer - excellent for subscription offers';
        }

        if ($this->average_order_value > 20000) { // $200+
            $insights[] = 'High value transactions - good candidate for premium products';
        }

        // Churn risk insights
        $churnRisk = $this->getChurnRiskLevel();
        if (in_array($churnRisk, ['High', 'Critical'])) {
            $insights[] = "Churn risk: {$churnRisk} - consider retention campaign";
        }

        // Lifecycle insights
        $stage = $this->lifecycle_stage;
        if ($stage === 'One-Time') {
            $insights[] = 'One-time customer - target with re-engagement campaign';
        } elseif ($stage === 'Dormant') {
            $insights[] = 'Dormant customer - winback campaign recommended';
        }

        return $insights;
    }

    // ── Display Helpers ──────────────────────────────────────────────────────

    /**
     * Get formatted total spent
     */
    public function getFormattedTotalSpent(): string
    {
        return '$' . number_format($this->total_spent / 100, 2);
    }

    /**
     * Get formatted average order value
     */
    public function getFormattedAverageOrderValue(): string
    {
        return '$' . number_format($this->average_order_value / 100, 2);
    }

    /**
     * Get formatted predicted LTV
     */
    public function getFormattedPredictedLtv(): string
    {
        return '$' . number_format($this->predicted_ltv / 100, 2);
    }

    /**
     * Get formatted purchase frequency
     */
    public function getFormattedPurchaseFrequency(): string
    {
        return number_format($this->purchase_frequency, 1) . ' orders/month';
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    /**
     * Scope by value segment
     */
    public function scopeInValueSegment($query, string $segment)
    {
        return $query->where('value_segment', $segment);
    }

    /**
     * Scope by loyalty segment
     */
    public function scopeInLoyaltySegment($query, string $segment)
    {
        return $query->where('loyalty_segment', $segment);
    }

    /**
     * Scope by lifecycle stage
     */
    public function scopeInLifecycleStage($query, string $stage)
    {
        return $query->where('lifecycle_stage', $stage);
    }

    /**
     * Scope by churn risk
     */
    public function scopeWithChurnRisk($query, string $risk)
    {
        $ranges = [
            'Critical' => [0.8, 1.0],
            'High' => [0.6, 0.8],
            'Medium' => [0.4, 0.6],
            'Low' => [0.2, 0.4],
            'Minimal' => [0.0, 0.2],
        ];

        if (isset($ranges[$risk])) {
            [$min, $max] = $ranges[$risk];
            return $query->whereBetween('churn_probability', [$min, $max]);
        }

        return $query;
    }

    /**
     * Scope to high-value customers
     */
    public function scopeHighValue($query, int $minimumSpent = 100000) // $1000+
    {
        return $query->where('total_spent', '>=', $minimumSpent);
    }

    /**
     * Scope to recent calculations
     */
    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('calculation_date', '>=', now()->subDays($days));
    }

    /**
     * Scope to customers needing attention (at risk or dormant)
     */
    public function scopeNeedingAttention($query)
    {
        return $query->where(function ($q) {
            $q->where('churn_probability', '>=', 0.6)
              ->orWhereIn('lifecycle_stage', ['Dormant', 'One-Time']);
        });
    }
}