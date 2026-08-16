<?php

declare(strict_types=1);

namespace App\Domain\Growth;

use App\Domain\Tenancy\TenantContext;
use App\Models\MarketingCampaign;
use App\Models\CampaignDelivery;
use App\Domain\Sales\Models\Customer;
use App\Domain\Sales\Models\Order;
use App\Models\MarketingAttribution;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * Marketing Service
 *
 * Manages marketing campaigns, attribution tracking, and
 * customer acquisition analytics for growth optimization.
 */
class MarketingService
{
    public function __construct(
        private TenantContext $tenantContext
    ) {}

    // ── Campaign Management ──────────────────────────────────────────────────

    /**
     * Create marketing campaign
     */
    public function createCampaign(array $data): MarketingCampaign
    {
        return DB::transaction(function () use ($data) {
            // account_id / business_id are deliberately not passed here — the
            // model's BelongsToAccount / BelongsToBusiness traits stamp them
            // from TenantContext on creating(), and neither column is in
            // MarketingCampaign's $fillable. Passing them explicitly throws
            // under Model::preventSilentlyDiscardingAttributes().
            $campaign = MarketingCampaign::create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'type' => $data['type'],
                'status' => 'draft',
                'configuration' => $data['configuration'] ?? [],
                'targeting_rules' => $data['targeting_rules'] ?? [],
                'automation_triggers' => $data['automation_triggers'] ?? null,
                'personalization_rules' => $data['personalization_rules'] ?? null,
                'scheduled_at' => $data['scheduled_at'] ?? null,
                'duration_days' => $data['duration_days'] ?? null,
                'timezone' => $data['timezone'] ?? 'UTC',
                'budget_amount' => $data['budget_amount'] ?? null,
                'budget_currency' => $data['budget_currency'] ?? 'USD',
                'budget_type' => $data['budget_type'] ?? 'total',
                'send_limit' => $data['send_limit'] ?? null,
                'send_limit_period' => $data['send_limit_period'] ?? null,
                'is_ab_test' => $data['is_ab_test'] ?? false,
                'ab_variants' => $data['ab_variants'] ?? null,
                'traffic_split' => $data['traffic_split'] ?? 1.0,
                'winning_metric' => $data['winning_metric'] ?? null,
                'created_by' => $data['created_by'] ?? null,
                'tags' => $data['tags'] ?? null,
                'metadata' => $data['metadata'] ?? null,
            ]);

            return $campaign;
        });
    }

    /**
     * Update campaign configuration
     */
    public function updateCampaign(int $campaignId, array $data): MarketingCampaign
    {
        $campaign = MarketingCampaign::findOrFail($campaignId);

        // Prevent changes to running campaigns (except status)
        if ($campaign->isActive() && !isset($data['status'])) {
            throw new \InvalidArgumentException('Cannot modify running campaign');
        }

        $campaign->update($data);
        return $campaign;
    }

    /**
     * Launch campaign
     */
    public function launchCampaign(int $campaignId): MarketingCampaign
    {
        return DB::transaction(function () use ($campaignId) {
            $campaign = MarketingCampaign::findOrFail($campaignId);

            if (!$campaign->canBeStarted()) {
                throw new \InvalidArgumentException('Campaign cannot be started');
            }

            $campaign->start();

            // Process immediate sends if applicable
            if (in_array($campaign->type, ['email', 'sms', 'push'])) {
                $this->processMessagingCampaign($campaign);
            }

            return $campaign;
        });
    }

    /**
     * Process messaging campaign deliveries
     */
    private function processMessagingCampaign(MarketingCampaign $campaign): void
    {
        $targetCustomers = $this->getTargetCustomers($campaign);

        foreach ($targetCustomers as $customer) {
            if ($campaign->isSendLimitReached()) {
                break;
            }

            $this->createCampaignDelivery($campaign, $customer);
        }
    }

    /**
     * Get target customers for campaign
     */
    private function getTargetCustomers(MarketingCampaign $campaign): Collection
    {
        $query = Customer::where('business_id', $campaign->business_id)
            ->where('is_active', true);

        // Apply targeting rules
        if (!empty($campaign->targeting_rules)) {
            foreach ($campaign->targeting_rules as $rule) {
                $query = $this->applyTargetingRule($query, $rule);
            }
        }

        // Apply traffic split for A/B testing
        if ($campaign->is_ab_test && $campaign->traffic_split < 1.0) {
            $query->inRandomOrder()
                  ->limit(intval($query->count() * $campaign->traffic_split));
        }

        return $query->get();
    }

    /**
     * Apply targeting rule to query
     */
    private function applyTargetingRule($query, array $rule)
    {
        $field = $rule['field'] ?? null;
        $operator = $rule['operator'] ?? 'equals';
        $value = $rule['value'] ?? null;

        if (!$field) {
            return $query;
        }

        return match($operator) {
            'equals' => $query->where($field, $value),
            'not_equals' => $query->where($field, '!=', $value),
            'greater_than' => $query->where($field, '>', $value),
            'less_than' => $query->where($field, '<', $value),
            'contains' => $query->where($field, 'like', "%{$value}%"),
            'starts_with' => $query->where($field, 'like', "{$value}%"),
            'in' => $query->whereIn($field, (array)$value),
            'not_in' => $query->whereNotIn($field, (array)$value),
            'between' => $query->whereBetween($field, $value),
            default => $query,
        };
    }

    /**
     * Create campaign delivery
     */
    private function createCampaignDelivery(MarketingCampaign $campaign, Customer $customer): CampaignDelivery
    {
        $variant = $this->selectVariant($campaign);
        $content = $this->personalizeMessage($campaign, $customer, $variant);

        $delivery = $campaign->deliveries()->create([
            'customer_id' => $customer->id,
            'recipient_email' => $customer->email,
            'recipient_phone' => $customer->phone,
            'channel' => $campaign->type,
            'variant' => $variant,
            'message_content' => $content,
            'personalization_data' => $this->getPersonalizationData($customer),
            'status' => 'queued',
        ]);

        // Update campaign stats
        $campaign->increment('sent_count');

        return $delivery;
    }

    /**
     * Select A/B test variant
     */
    private function selectVariant(MarketingCampaign $campaign): ?string
    {
        if (!$campaign->is_ab_test) {
            return null;
        }

        $variants = $campaign->getVariants();
        if (empty($variants)) {
            return null;
        }

        // Simple random selection (could be enhanced with weighted distribution)
        return array_rand($variants);
    }

    /**
     * Personalize message content
     */
    private function personalizeMessage(MarketingCampaign $campaign, Customer $customer, ?string $variant): array
    {
        $template = $campaign->configuration['template'] ?? [];
        $content = $template['content'] ?? '';

        // Apply personalization rules
        $personalizations = $campaign->personalization_rules ?? [];
        foreach ($personalizations as $rule) {
            $content = $this->applyPersonalizationRule($content, $customer, $rule);
        }

        // Apply variant-specific content
        if ($variant && !empty($campaign->ab_variants[$variant])) {
            $variantData = $campaign->ab_variants[$variant];
            $content = array_merge($content, $variantData);
        }

        return [
            'subject' => $this->personalizePlaceholders($template['subject'] ?? '', $customer),
            'content' => $this->personalizePlaceholders($content, $customer),
            'template' => $template['template_id'] ?? null,
        ];
    }

    /**
     * Apply personalization rule
     */
    private function applyPersonalizationRule(string $content, Customer $customer, array $rule): string
    {
        $placeholder = $rule['placeholder'] ?? null;
        $source = $rule['source'] ?? 'customer';
        $field = $rule['field'] ?? null;
        $default = $rule['default'] ?? '';

        if (!$placeholder || !$field) {
            return $content;
        }

        $value = match($source) {
            'customer' => $customer->getAttribute($field) ?? $default,
            'order' => $this->getLastOrderAttribute($customer, $field) ?? $default,
            'analytics' => $this->getCustomerAnalyticAttribute($customer, $field) ?? $default,
            default => $default,
        };

        return str_replace($placeholder, (string)$value, $content);
    }

    /**
     * Replace standard placeholders
     */
    private function personalizePlaceholders(string $content, Customer $customer): string
    {
        $placeholders = [
            '{{first_name}}' => $customer->getFirstName(),
            '{{name}}' => $customer->name,
            '{{email}}' => $customer->email,
            '{{business_name}}' => $this->tenantContext->business()->name,
        ];

        return str_replace(array_keys($placeholders), array_values($placeholders), $content);
    }

    /**
     * Get personalization data for delivery
     */
    private function getPersonalizationData(Customer $customer): array
    {
        return [
            'customer_id' => $customer->id,
            'name' => $customer->name,
            'email' => $customer->email,
            'signup_date' => $customer->created_at,
            'last_order_date' => $customer->last_order_at,
            'total_orders' => $customer->orders()->count(),
            'total_spent' => $customer->getTotalSpent(),
        ];
    }

    // ── Attribution Tracking ─────────────────────────────────────────────────

    /**
     * Track marketing attribution for order
     */
    public function trackAttribution(Order $order, array $touchpoints): void
    {
        if (empty($touchpoints)) {
            return;
        }

        DB::transaction(function () use ($order, $touchpoints) {
            foreach ($touchpoints as $touchpoint) {
                $this->createAttributionRecord($order, $touchpoint);
            }
        });
    }

    /**
     * Create attribution record
     */
    private function createAttributionRecord(Order $order, array $touchpoint): MarketingAttribution
    {
        $attribution = MarketingAttribution::create([
            'customer_id' => $order->customer_id,
            'order_id' => $order->id,
            'campaign_id' => $touchpoint['campaign_id'] ?? null,
            'attribution_type' => $touchpoint['attribution_type'] ?? 'last_touch',
            'attribution_weight' => $touchpoint['attribution_weight'] ?? 1.0,
            'touch_point' => $touchpoint['touch_point'],
            'channel' => $touchpoint['channel'],
            'source' => $touchpoint['source'] ?? null,
            'medium' => $touchpoint['medium'] ?? null,
            'campaign_name' => $touchpoint['campaign_name'] ?? null,
            'utm_parameters' => $touchpoint['utm_parameters'] ?? null,
            // Order carries no `total` attribute — the real column is
            // `total_minor`, already in the same minor-unit shape both of
            // these columns are stored in. `conversion_value_minor` is the
            // order's raw value; `attributed_revenue` is that value weighted
            // by this touchpoint's share of the conversion.
            'attributed_revenue' => (int) round($order->total_minor * ($touchpoint['attribution_weight'] ?? 1.0)),
            'conversion_value_minor' => $order->total_minor,
            'touch_point_at' => $touchpoint['touch_point_at'] ?? now(),
            'conversion_at' => $order->created_at,
            'customer_journey_position' => $touchpoint['journey_position'] ?? 1,
            'days_to_conversion' => $touchpoint['days_to_conversion'] ?? 0,
            'journey_metadata' => $touchpoint['journey_metadata'] ?? null,
        ]);

        return $attribution;
    }

    /**
     * Calculate multi-touch attribution for customer journey
     */
    public function calculateMultiTouchAttribution(Customer $customer, Order $order, array $touchpoints): array
    {
        if (empty($touchpoints)) {
            return [];
        }

        $totalTouchpoints = count($touchpoints);
        $attributions = [];

        foreach ($touchpoints as $index => $touchpoint) {
            $journeyPosition = $index + 1;
            $daysToConversion = $touchpoint['days_to_conversion'] ?? 0;

            // Calculate different attribution models
            $models = [
                'first_touch' => MarketingAttribution::calculateAttributionWeight('first_touch', $journeyPosition, $totalTouchpoints, $daysToConversion),
                'last_touch' => MarketingAttribution::calculateAttributionWeight('last_touch', $journeyPosition, $totalTouchpoints, $daysToConversion),
                'linear' => MarketingAttribution::calculateAttributionWeight('linear', $journeyPosition, $totalTouchpoints, $daysToConversion),
                'time_decay' => MarketingAttribution::calculateAttributionWeight('time_decay', $journeyPosition, $totalTouchpoints, $daysToConversion),
                'position_based' => MarketingAttribution::calculateAttributionWeight('position_based', $journeyPosition, $totalTouchpoints, $daysToConversion),
            ];

            foreach ($models as $model => $weight) {
                $attributions[] = array_merge($touchpoint, [
                    'attribution_type' => $model,
                    'attribution_weight' => $weight,
                    'journey_position' => $journeyPosition,
                    'days_to_conversion' => $daysToConversion,
                ]);
            }
        }

        return $attributions;
    }

    // ── Campaign Analytics ───────────────────────────────────────────────────

    /**
     * Get campaign performance report
     */
    public function getCampaignPerformance(int $campaignId): array
    {
        $campaign = MarketingCampaign::findOrFail($campaignId);
        
        $performance = $campaign->getPerformanceSummary();
        
        // Add channel breakdown
        $channelBreakdown = $campaign->deliveries()
            ->selectRaw('channel, COUNT(*) as count, 
                        SUM(CASE WHEN status IN ("delivered", "opened", "clicked", "converted") THEN 1 ELSE 0 END) as delivered,
                        SUM(CASE WHEN status IN ("opened", "clicked", "converted") THEN 1 ELSE 0 END) as opened,
                        SUM(CASE WHEN status IN ("clicked", "converted") THEN 1 ELSE 0 END) as clicked,
                        SUM(CASE WHEN status = "converted" THEN 1 ELSE 0 END) as converted,
                        SUM(attributed_revenue) as revenue')
            ->groupBy('channel')
            ->get()
            ->map(function ($row) {
                return [
                    'channel' => $row->channel,
                    'sent' => $row->count,
                    'delivered' => $row->delivered,
                    'opened' => $row->opened,
                    'clicked' => $row->clicked,
                    'converted' => $row->converted,
                    'revenue' => $row->revenue,
                    'delivery_rate' => $row->count > 0 ? ($row->delivered / $row->count) * 100 : 0,
                    'open_rate' => $row->delivered > 0 ? ($row->opened / $row->delivered) * 100 : 0,
                    'click_rate' => $row->delivered > 0 ? ($row->clicked / $row->delivered) * 100 : 0,
                    'conversion_rate' => $row->delivered > 0 ? ($row->converted / $row->delivered) * 100 : 0,
                ];
            });

        $performance['channel_breakdown'] = $channelBreakdown;

        // Add A/B test results if applicable
        if ($campaign->is_ab_test) {
            $performance['ab_test_results'] = $this->getAbTestResults($campaign);
        }

        return $performance;
    }

    /**
     * Get A/B test results
     */
    private function getAbTestResults(MarketingCampaign $campaign): array
    {
        if (!$campaign->is_ab_test) {
            return [];
        }

        $results = $campaign->deliveries()
            ->selectRaw('variant, COUNT(*) as sent,
                        SUM(CASE WHEN status IN ("delivered", "opened", "clicked", "converted") THEN 1 ELSE 0 END) as delivered,
                        SUM(CASE WHEN status IN ("opened", "clicked", "converted") THEN 1 ELSE 0 END) as opened,
                        SUM(CASE WHEN status IN ("clicked", "converted") THEN 1 ELSE 0 END) as clicked,
                        SUM(CASE WHEN status = "converted" THEN 1 ELSE 0 END) as converted,
                        SUM(attributed_revenue) as revenue')
            ->whereNotNull('variant')
            ->groupBy('variant')
            ->get()
            ->map(function ($row) {
                return [
                    'variant' => $row->variant,
                    'sent' => $row->sent,
                    'delivered' => $row->delivered,
                    'opened' => $row->opened,
                    'clicked' => $row->clicked,
                    'converted' => $row->converted,
                    'revenue' => $row->revenue,
                    'open_rate' => $row->delivered > 0 ? ($row->opened / $row->delivered) * 100 : 0,
                    'click_rate' => $row->delivered > 0 ? ($row->clicked / $row->delivered) * 100 : 0,
                    'conversion_rate' => $row->delivered > 0 ? ($row->converted / $row->delivered) * 100 : 0,
                ];
            });

        return $results->toArray();
    }

    /**
     * Get attribution report
     */
    public function getAttributionReport(array $filters = []): array
    {
        $query = MarketingAttribution::query();

        // Apply filters
        if (!empty($filters['start_date']) && !empty($filters['end_date'])) {
            $query->whereBetween('conversion_at', [$filters['start_date'], $filters['end_date']]);
        }

        if (!empty($filters['channel'])) {
            $query->where('channel', $filters['channel']);
        }

        if (!empty($filters['attribution_type'])) {
            $query->where('attribution_type', $filters['attribution_type']);
        }

        $attributions = $query->get();

        return MarketingAttribution::getAttributionSummary($attributions);
    }

    // ── Automation ───────────────────────────────────────────────────────────

    /**
     * Process automated campaign triggers
     */
    public function processAutomationTriggers(): void
    {
        $automationCampaigns = MarketingCampaign::where('business_id', $this->tenantContext->business()->id)
            ->whereNotNull('automation_triggers')
            ->where('status', 'running')
            ->get();

        foreach ($automationCampaigns as $campaign) {
            $this->processAutomationCampaign($campaign);
        }
    }

    /**
     * Process individual automation campaign
     */
    private function processAutomationCampaign(MarketingCampaign $campaign): void
    {
        $triggers = $campaign->automation_triggers;

        foreach ($triggers as $trigger) {
            $this->processAutomationTrigger($campaign, $trigger);
        }
    }

    /**
     * Process automation trigger
     */
    private function processAutomationTrigger(MarketingCampaign $campaign, array $trigger): void
    {
        $triggerType = $trigger['type'] ?? null;

        match($triggerType) {
            'order_placed' => $this->processOrderPlacedTrigger($campaign, $trigger),
            'cart_abandoned' => $this->processAbandonedCartTrigger($campaign, $trigger),
            'customer_inactive' => $this->processInactivityTrigger($campaign, $trigger),
            'birthday' => $this->processBirthdayTrigger($campaign, $trigger),
            'anniversary' => $this->processAnniversaryTrigger($campaign, $trigger),
            default => null,
        };
    }

    /**
     * Process order placed trigger
     */
    private function processOrderPlacedTrigger(MarketingCampaign $campaign, array $trigger): void
    {
        $delayHours = $trigger['delay_hours'] ?? 0;
        $targetDate = now()->subHours($delayHours);

        $orders = Order::where('business_id', $campaign->business_id)
            ->where('created_at', '>=', $targetDate->copy()->subHour())
            ->where('created_at', '<=', $targetDate->copy()->addHour())
            ->whereDoesntHave('deliveries', function ($query) use ($campaign) {
                $query->where('campaign_id', $campaign->id);
            })
            ->with('customer')
            ->get();

        foreach ($orders as $order) {
            if ($order->customer && $campaign->matchesTargeting($order->customer)) {
                $this->createCampaignDelivery($campaign, $order->customer);
            }
        }
    }

    /**
     * Process abandoned cart trigger
     */
    private function processAbandonedCartTrigger(MarketingCampaign $campaign, array $trigger): void
    {
        // Implementation would depend on cart storage mechanism
        // This is a placeholder for abandoned cart logic
    }

    /**
     * Process customer inactivity trigger
     */
    private function processInactivityTrigger(MarketingCampaign $campaign, array $trigger): void
    {
        $inactiveDays = $trigger['inactive_days'] ?? 30;
        $cutoffDate = now()->subDays($inactiveDays);

        $inactiveCustomers = Customer::where('business_id', $campaign->business_id)
            ->where('last_order_at', '<=', $cutoffDate)
            ->orWhereNull('last_order_at')
            ->whereDoesntHave('deliveries', function ($query) use ($campaign, $cutoffDate) {
                $query->where('campaign_id', $campaign->id)
                      ->where('sent_at', '>=', $cutoffDate);
            })
            ->get();

        foreach ($inactiveCustomers as $customer) {
            if ($campaign->matchesTargeting($customer)) {
                $this->createCampaignDelivery($campaign, $customer);
            }
        }
    }

    /**
     * Process birthday trigger
     */
    private function processBirthdayTrigger(MarketingCampaign $campaign, array $trigger): void
    {
        $today = now();
        
        $birthdayCustomers = Customer::where('business_id', $campaign->business_id)
            ->whereRaw('MONTH(date_of_birth) = ? AND DAY(date_of_birth) = ?', [$today->month, $today->day])
            ->whereDoesntHave('deliveries', function ($query) use ($campaign, $today) {
                $query->where('campaign_id', $campaign->id)
                      ->whereDate('sent_at', $today);
            })
            ->get();

        foreach ($birthdayCustomers as $customer) {
            if ($campaign->matchesTargeting($customer)) {
                $this->createCampaignDelivery($campaign, $customer);
            }
        }
    }

    /**
     * Process anniversary trigger
     */
    private function processAnniversaryTrigger(MarketingCampaign $campaign, array $trigger): void
    {
        $today = now();
        
        $anniversaryCustomers = Customer::where('business_id', $campaign->business_id)
            ->whereRaw('MONTH(created_at) = ? AND DAY(created_at) = ?', [$today->month, $today->day])
            ->where('created_at', '<=', $today->subYear())
            ->whereDoesntHave('deliveries', function ($query) use ($campaign, $today) {
                $query->where('campaign_id', $campaign->id)
                      ->whereDate('sent_at', $today);
            })
            ->get();

        foreach ($anniversaryCustomers as $customer) {
            if ($campaign->matchesTargeting($customer)) {
                $this->createCampaignDelivery($campaign, $customer);
            }
        }
    }

    // ── Utility Methods ──────────────────────────────────────────────────────

    /**
     * Get last order attribute
     */
    private function getLastOrderAttribute(Customer $customer, string $field): mixed
    {
        $lastOrder = $customer->orders()->latest()->first();
        return $lastOrder?->getAttribute($field);
    }

    /**
     * Get customer analytic attribute
     */
    private function getCustomerAnalyticAttribute(Customer $customer, string $field): mixed
    {
        $analytics = $customer->ltvAnalytics()->latest('calculation_date')->first();
        return $analytics?->getAttribute($field);
    }
}