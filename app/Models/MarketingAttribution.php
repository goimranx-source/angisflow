<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Shared\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Marketing Attribution Model
 *
 * Tracks how marketing campaigns and touchpoints contribute
 * to customer acquisition and revenue generation.
 */
class MarketingAttribution extends Model
{
    use HasFactory, HasPublicId;

    protected $fillable = [
        'customer_id',
        'order_id',
        'campaign_id',
        'attribution_type',
        'attribution_weight',
        'touch_point',
        'channel',
        'source',
        'medium',
        'campaign_name',
        'utm_parameters',
        'attributed_revenue',
        'conversion_value_minor',
        'touch_point_at',
        'conversion_at',
        'customer_journey_position',
        'days_to_conversion',
        'journey_metadata',
    ];

    protected $casts = [
        'attribution_weight' => 'decimal:4',
        'utm_parameters' => 'array',
        'attributed_revenue' => 'integer',
        'conversion_value_minor' => 'integer',
        'touch_point_at' => 'datetime',
        'conversion_at' => 'datetime',
        'customer_journey_position' => 'integer',
        'days_to_conversion' => 'integer',
        'journey_metadata' => 'array',
    ];

    // ── Relationships ────────────────────────────────────────────────────────

    /**
     * Customer who converted
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Order that resulted from this attribution
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Marketing campaign that drove this attribution
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(MarketingCampaign::class, 'campaign_id');
    }

    // ── Attribution Models ───────────────────────────────────────────────────

    /**
     * Calculate attribution weight based on model
     */
    public static function calculateAttributionWeight(
        string $attributionType,
        int $journeyPosition,
        int $totalTouchPoints,
        int $daysToConversion
    ): float {
        return match($attributionType) {
            'first_touch' => $journeyPosition === 1 ? 1.0 : 0.0,
            'last_touch' => $journeyPosition === $totalTouchPoints ? 1.0 : 0.0,
            'linear' => 1.0 / $totalTouchPoints,
            'time_decay' => self::calculateTimeDecayWeight($journeyPosition, $totalTouchPoints, $daysToConversion),
            'position_based' => self::calculatePositionBasedWeight($journeyPosition, $totalTouchPoints),
            default => 1.0,
        };
    }

    /**
     * Calculate time decay attribution weight
     */
    private static function calculateTimeDecayWeight(int $position, int $total, int $daysToConversion): float
    {
        // More recent touchpoints get higher weight
        $decayRate = 0.7; // 30% decay per day
        $daysFromConversion = max(1, $daysToConversion - ($total - $position));
        
        return pow($decayRate, $daysFromConversion - 1);
    }

    /**
     * Calculate position-based attribution weight (40-20-40 model)
     */
    private static function calculatePositionBasedWeight(int $position, int $total): float
    {
        if ($total === 1) {
            return 1.0;
        }

        if ($position === 1) {
            return 0.4; // First touch gets 40%
        }

        if ($position === $total) {
            return 0.4; // Last touch gets 40%
        }

        // Middle touches share remaining 20%
        $middleTouchPoints = max(1, $total - 2);
        return 0.2 / $middleTouchPoints;
    }

    // ── Attribution Analysis ─────────────────────────────────────────────────

    /**
     * Check if this is first touch attribution
     */
    public function isFirstTouch(): bool
    {
        return $this->attribution_type === 'first_touch' || $this->customer_journey_position === 1;
    }

    /**
     * Check if this is last touch attribution
     */
    public function isLastTouch(): bool
    {
        return $this->attribution_type === 'last_touch';
    }

    /**
     * Get attribution model display name
     */
    public function getAttributionModelName(): string
    {
        return match($this->attribution_type) {
            'first_touch' => 'First Touch',
            'last_touch' => 'Last Touch',
            'linear' => 'Linear',
            'time_decay' => 'Time Decay',
            'position_based' => 'Position Based',
            default => ucwords(str_replace('_', ' ', $this->attribution_type)),
        };
    }

    /**
     * Get touchpoint description
     */
    public function getTouchPointDescription(): string
    {
        if ($this->campaign_name) {
            return "{$this->campaign_name} ({$this->channel})";
        }

        return match($this->touch_point) {
            'organic' => 'Organic Search',
            'direct' => 'Direct Traffic',
            'referral' => 'Referral Traffic',
            'social' => 'Social Media',
            'email' => 'Email Marketing',
            'paid_search' => 'Paid Search',
            'display' => 'Display Advertising',
            default => ucwords(str_replace('_', ' ', $this->touch_point)),
        };
    }

    /**
     * Get UTM parameter value
     */
    public function getUtmParameter(string $parameter): ?string
    {
        return $this->utm_parameters[$parameter] ?? null;
    }

    /**
     * Check if has UTM parameters
     */
    public function hasUtmParameters(): bool
    {
        return !empty($this->utm_parameters);
    }

    // ── Revenue Attribution ──────────────────────────────────────────────────

    /**
     * Get attributed revenue with weight applied
     */
    public function getWeightedRevenue(): float
    {
        return ($this->attributed_revenue / 100) * $this->attribution_weight;
    }

    /**
     * Get formatted attributed revenue
     */
    public function getFormattedRevenue(): string
    {
        return '$' . number_format($this->attributed_revenue / 100, 2);
    }

    /**
     * Get formatted weighted revenue
     */
    public function getFormattedWeightedRevenue(): string
    {
        return '$' . number_format($this->getWeightedRevenue(), 2);
    }

    // ── Journey Analysis ─────────────────────────────────────────────────────

    /**
     * Get customer journey insights
     */
    public function getJourneyInsights(): array
    {
        $insights = [];

        // Journey length insights
        if ($this->days_to_conversion) {
            if ($this->days_to_conversion <= 1) {
                $insights[] = 'Quick conversion - high intent traffic';
            } elseif ($this->days_to_conversion <= 7) {
                $insights[] = 'Short consideration period - effective messaging';
            } elseif ($this->days_to_conversion <= 30) {
                $insights[] = 'Standard consideration period';
            } else {
                $insights[] = 'Long consideration period - nurturing opportunity';
            }
        }

        // Journey position insights
        if ($this->customer_journey_position === 1 && $this->days_to_conversion <= 1) {
            $insights[] = 'Single-touch conversion - high-quality traffic source';
        } elseif ($this->customer_journey_position > 3) {
            $insights[] = 'Multi-touch journey - complex buying process';
        }

        // Channel insights
        $channelInsights = match($this->channel) {
            'email' => 'Email drove conversion - good for nurturing campaigns',
            'organic' => 'Organic search conversion - strong SEO performance',
            'paid_search' => 'Paid search conversion - keyword strategy effective',
            'social' => 'Social media conversion - community engagement pays off',
            'direct' => 'Direct traffic conversion - strong brand awareness',
            default => null,
        };

        if ($channelInsights) {
            $insights[] = $channelInsights;
        }

        return $insights;
    }

    /**
     * Calculate return on ad spend (ROAS)
     */
    public function calculateRoas(float $adSpend): float
    {
        if ($adSpend <= 0) {
            return 0;
        }

        return $this->getWeightedRevenue() / $adSpend;
    }

    // ── Aggregation Helpers ──────────────────────────────────────────────────

    /**
     * Get attribution summary for a collection of records
     */
    public static function getAttributionSummary($attributions): array
    {
        if ($attributions->isEmpty()) {
            return [];
        }

        return [
            'total_touchpoints' => $attributions->count(),
            'total_revenue' => $attributions->sum('attributed_revenue'),
            'weighted_revenue' => $attributions->sum(function ($attr) {
                return $attr->attributed_revenue * $attr->attribution_weight;
            }),
            'average_days_to_conversion' => $attributions->avg('days_to_conversion'),
            'channels' => $attributions->groupBy('channel')->map->count()->toArray(),
            'sources' => $attributions->groupBy('source')->map->count()->toArray(),
            'attribution_models' => $attributions->groupBy('attribution_type')->map->count()->toArray(),
        ];
    }

    // ── Display Helpers ──────────────────────────────────────────────────────

    /**
     * Get formatted attribution weight as percentage
     */
    public function getFormattedWeight(): string
    {
        return number_format($this->attribution_weight * 100, 1) . '%';
    }

    /**
     * Get days to conversion display
     */
    public function getDaysToConversionDisplay(): string
    {
        if (!$this->days_to_conversion) {
            return 'Same day';
        }

        if ($this->days_to_conversion === 1) {
            return '1 day';
        }

        return $this->days_to_conversion . ' days';
    }

    /**
     * Get journey position display
     */
    public function getJourneyPositionDisplay(): string
    {
        $ordinal = match($this->customer_journey_position) {
            1 => '1st',
            2 => '2nd',
            3 => '3rd',
            default => $this->customer_journey_position . 'th',
        };

        return $ordinal . ' touchpoint';
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    /**
     * Scope by attribution model
     */
    public function scopeByModel($query, string $model)
    {
        return $query->where('attribution_type', $model);
    }

    /**
     * Scope by channel
     */
    public function scopeByChannel($query, string $channel)
    {
        return $query->where('channel', $channel);
    }

    /**
     * Scope by source
     */
    public function scopeBySource($query, string $source)
    {
        return $query->where('source', $source);
    }

    /**
     * Scope to first touch attributions
     */
    public function scopeFirstTouch($query)
    {
        return $query->where(function ($q) {
            $q->where('attribution_type', 'first_touch')
              ->orWhere('customer_journey_position', 1);
        });
    }

    /**
     * Scope to last touch attributions
     */
    public function scopeLastTouch($query)
    {
        return $query->where('attribution_type', 'last_touch');
    }

    /**
     * Scope by conversion date range
     */
    public function scopeConvertedBetween($query, $startDate, $endDate)
    {
        return $query->whereBetween('conversion_at', [$startDate, $endDate]);
    }

    /**
     * Scope to conversions with revenue
     */
    public function scopeWithRevenue($query)
    {
        return $query->where('attributed_revenue', '>', 0);
    }

    /**
     * Scope by campaign
     */
    public function scopeByCampaign($query, int $campaignId)
    {
        return $query->where('campaign_id', $campaignId);
    }

    /**
     * Scope by days to conversion range
     */
    public function scopeWithConversionTime($query, int $minDays, int $maxDays)
    {
        return $query->whereBetween('days_to_conversion', [$minDays, $maxDays]);
    }
}