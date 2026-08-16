<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tenancy\Concerns\BelongsToAccount;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use App\Domain\Shared\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Carbon\Carbon;

/**
 * Marketing Campaign Model
 *
 * Represents multi-channel marketing campaigns with automation,
 * A/B testing, and comprehensive performance tracking.
 */
class MarketingCampaign extends Model
{
    use HasFactory, HasPublicId, BelongsToAccount, BelongsToBusiness, SoftDeletes;

    protected $fillable = [
        'name',
        'description',
        'type',
        'status',
        'configuration',
        'targeting_rules',
        'automation_triggers',
        'personalization_rules',
        'scheduled_at',
        'started_at',
        'ended_at',
        'duration_days',
        'timezone',
        'budget_amount',
        'budget_currency',
        'budget_type',
        'send_limit',
        'send_limit_period',
        'is_ab_test',
        'ab_variants',
        'traffic_split',
        'winning_metric',
        'winning_variant',
        'created_by',
        'tags',
        'metadata',
    ];

    protected $casts = [
        'configuration' => 'array',
        'targeting_rules' => 'array',
        'automation_triggers' => 'array',
        'personalization_rules' => 'array',
        'ab_variants' => 'array',
        'tags' => 'array',
        'metadata' => 'array',
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'traffic_split' => 'decimal:2',
        'is_ab_test' => 'boolean',
        'sent_count' => 'integer',
        'delivered_count' => 'integer',
        'opened_count' => 'integer',
        'clicked_count' => 'integer',
        'converted_count' => 'integer',
        'revenue_generated' => 'integer',
        'unsubscribed_count' => 'integer',
        'complained_count' => 'integer',
        'bounced_count' => 'integer',
    ];

    // ── Relationships ────────────────────────────────────────────────────────

    /**
     * Campaign deliveries (individual messages sent)
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(CampaignDelivery::class, 'campaign_id');
    }

    /**
     * User who created the campaign
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Marketing attribution records
     */
    public function attributions(): HasMany
    {
        return $this->hasMany(MarketingAttribution::class, 'campaign_id');
    }

    // ── Campaign Status Management ───────────────────────────────────────────

    /**
     * Check if campaign is active
     */
    public function isActive(): bool
    {
        return $this->status === 'running';
    }

    /**
     * Check if campaign is scheduled
     */
    public function isScheduled(): bool
    {
        return $this->status === 'scheduled' && $this->scheduled_at > now();
    }

    /**
     * Check if campaign can be started
     */
    public function canBeStarted(): bool
    {
        return in_array($this->status, ['draft', 'scheduled', 'paused']);
    }

    /**
     * Check if campaign can be paused
     */
    public function canBePaused(): bool
    {
        return $this->status === 'running';
    }

    /**
     * Check if campaign can be completed
     */
    public function canBeCompleted(): bool
    {
        return in_array($this->status, ['running', 'paused']);
    }

    /**
     * Start the campaign
     */
    public function start(): void
    {
        if (!$this->canBeStarted()) {
            throw new \InvalidArgumentException('Campaign cannot be started in current status');
        }

        $this->update([
            'status' => 'running',
            'started_at' => now(),
        ]);
    }

    /**
     * Pause the campaign
     */
    public function pause(): void
    {
        if (!$this->canBePaused()) {
            throw new \InvalidArgumentException('Campaign cannot be paused in current status');
        }

        $this->update(['status' => 'paused']);
    }

    /**
     * Complete the campaign
     */
    public function complete(): void
    {
        if (!$this->canBeCompleted()) {
            throw new \InvalidArgumentException('Campaign cannot be completed in current status');
        }

        $this->update([
            'status' => 'completed',
            'ended_at' => now(),
        ]);
    }

    // ── Performance Metrics ──────────────────────────────────────────────────

    /**
     * Calculate delivery rate
     */
    public function getDeliveryRate(): float
    {
        if ($this->sent_count === 0) {
            return 0.0;
        }

        return ($this->delivered_count / $this->sent_count) * 100;
    }

    /**
     * Calculate open rate
     */
    public function getOpenRate(): float
    {
        if ($this->delivered_count === 0) {
            return 0.0;
        }

        return ($this->opened_count / $this->delivered_count) * 100;
    }

    /**
     * Calculate click rate
     */
    public function getClickRate(): float
    {
        if ($this->delivered_count === 0) {
            return 0.0;
        }

        return ($this->clicked_count / $this->delivered_count) * 100;
    }

    /**
     * Calculate conversion rate
     */
    public function getConversionRate(): float
    {
        if ($this->delivered_count === 0) {
            return 0.0;
        }

        return ($this->converted_count / $this->delivered_count) * 100;
    }

    /**
     * Calculate return on investment
     */
    public function getRoi(): float
    {
        if ($this->budget_amount === 0) {
            return 0.0;
        }

        $profit = $this->revenue_generated - $this->budget_amount;
        return ($profit / $this->budget_amount) * 100;
    }

    /**
     * Get performance summary
     */
    public function getPerformanceSummary(): array
    {
        return [
            'sent' => $this->sent_count,
            'delivered' => $this->delivered_count,
            'opened' => $this->opened_count,
            'clicked' => $this->clicked_count,
            'converted' => $this->converted_count,
            'unsubscribed' => $this->unsubscribed_count,
            'bounced' => $this->bounced_count,
            'complained' => $this->complained_count,
            'revenue' => $this->revenue_generated,
            'delivery_rate' => $this->getDeliveryRate(),
            'open_rate' => $this->getOpenRate(),
            'click_rate' => $this->getClickRate(),
            'conversion_rate' => $this->getConversionRate(),
            'roi' => $this->getRoi(),
        ];
    }

    // ── A/B Testing ──────────────────────────────────────────────────────────

    /**
     * Get A/B test variants
     */
    public function getVariants(): array
    {
        return $this->is_ab_test ? ($this->ab_variants ?? []) : [];
    }

    /**
     * Check if A/B test has a winner
     */
    public function hasWinner(): bool
    {
        return $this->is_ab_test && !empty($this->winning_variant);
    }

    /**
     * Get winning variant performance
     */
    public function getWinningVariantPerformance(): ?array
    {
        if (!$this->hasWinner()) {
            return null;
        }

        // Get performance data for winning variant
        $deliveries = $this->deliveries()
            ->where('variant', $this->winning_variant)
            ->selectRaw('
                COUNT(*) as sent,
                SUM(CASE WHEN status = "delivered" THEN 1 ELSE 0 END) as delivered,
                SUM(CASE WHEN status = "opened" THEN 1 ELSE 0 END) as opened,
                SUM(CASE WHEN status = "clicked" THEN 1 ELSE 0 END) as clicked,
                SUM(CASE WHEN status = "converted" THEN 1 ELSE 0 END) as converted,
                SUM(attributed_revenue) as revenue
            ')
            ->first();

        return $deliveries ? $deliveries->toArray() : null;
    }

    // ── Budget and Limits ────────────────────────────────────────────────────

    /**
     * Check if campaign is within budget
     */
    public function isWithinBudget(): bool
    {
        if (!$this->budget_amount) {
            return true; // No budget limit
        }

        $spentAmount = $this->calculateSpentAmount();
        return $spentAmount <= $this->budget_amount;
    }

    /**
     * Calculate amount spent on campaign
     */
    public function calculateSpentAmount(): int
    {
        // This would integrate with actual spend tracking
        // For now, use a simple calculation based on sends
        $costPerSend = $this->getCostPerSend();
        return $this->sent_count * $costPerSend;
    }

    /**
     * Get cost per send for this campaign type
     */
    private function getCostPerSend(): int
    {
        // Default costs in minor units (cents)
        $costs = [
            'email' => 5, // $0.05
            'sms' => 50, // $0.50
            'push' => 1, // $0.01
            'social' => 100, // $1.00
            'paid_ads' => 200, // $2.00
        ];

        return $costs[$this->type] ?? 10;
    }

    /**
     * Check if send limit is reached
     */
    public function isSendLimitReached(): bool
    {
        if (!$this->send_limit || !$this->send_limit_period) {
            return false;
        }

        $period = $this->getSendLimitPeriod();
        $sendCount = $this->deliveries()
            ->where('sent_at', '>=', $period['start'])
            ->where('sent_at', '<=', $period['end'])
            ->count();

        return $sendCount >= $this->send_limit;
    }

    /**
     * Get current send limit period
     */
    private function getSendLimitPeriod(): array
    {
        $now = Carbon::now($this->timezone);
        
        return match($this->send_limit_period) {
            'hour' => [
                'start' => $now->startOfHour(),
                'end' => $now->endOfHour(),
            ],
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
            default => [
                'start' => $now->startOfDay(),
                'end' => $now->endOfDay(),
            ],
        };
    }

    // ── Targeting and Personalization ────────────────────────────────────────

    /**
     * Check if customer matches targeting rules
     */
    public function matchesTargeting(Customer $customer): bool
    {
        $rules = $this->targeting_rules;
        
        if (empty($rules)) {
            return true; // No targeting rules = target everyone
        }

        // Implement targeting logic based on customer attributes
        foreach ($rules as $rule) {
            if (!$this->evaluateTargetingRule($customer, $rule)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Evaluate individual targeting rule
     */
    private function evaluateTargetingRule(Customer $customer, array $rule): bool
    {
        $field = $rule['field'];
        $operator = $rule['operator'];
        $value = $rule['value'];

        $customerValue = $customer->getAttribute($field);

        return match($operator) {
            'equals' => $customerValue == $value,
            'not_equals' => $customerValue != $value,
            'contains' => str_contains((string)$customerValue, (string)$value),
            'starts_with' => str_starts_with((string)$customerValue, (string)$value),
            'greater_than' => $customerValue > $value,
            'less_than' => $customerValue < $value,
            'in' => in_array($customerValue, (array)$value),
            'not_in' => !in_array($customerValue, (array)$value),
            default => false,
        };
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    /**
     * Scope to active campaigns
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'running');
    }

    /**
     * Scope to scheduled campaigns
     */
    public function scopeScheduled($query)
    {
        return $query->where('status', 'scheduled')
                    ->where('scheduled_at', '>', now());
    }

    /**
     * Scope to campaigns ready to start
     */
    public function scopeReadyToStart($query)
    {
        return $query->where('status', 'scheduled')
                    ->where('scheduled_at', '<=', now());
    }

    /**
     * Scope by campaign type
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope by date range
     */
    public function scopeInDateRange($query, Carbon $start, Carbon $end)
    {
        return $query->whereBetween('created_at', [$start, $end]);
    }
}