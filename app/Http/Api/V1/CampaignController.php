<?php

declare(strict_types=1);

namespace App\Http\Api\V1;

use App\Domain\Growth\MarketingService;
use App\Http\Api\ApiResponse;
use App\Http\Api\Endpoint;
use App\Models\MarketingCampaign;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

/**
 * Campaign Management API
 * 
 * Provides CRUD operations for marketing campaigns including
 * A/B testing, automation, and performance analytics.
 */
class CampaignController extends Endpoint
{
    public function __construct(
        private MarketingService $marketingService
    ) {}

    /**
     * List campaigns with filtering and pagination
     */
    public function index(Request $request): JsonResponse
    {
        $query = MarketingCampaign::query()
            ->with(['deliveries'])
            ->latest();

        // Filter by status
        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        // Filter by type
        if ($type = $request->get('type')) {
            $query->where('type', $type);
        }

        // Search by name
        if ($search = $request->get('search')) {
            $query->where('name', 'like', "%{$search}%");
        }

        $campaigns = $query->paginate($request->get('per_page', 20));

        return ApiResponse::item([
            'campaigns' => $campaigns->items(),
            'pagination' => [
                'current_page' => $campaigns->currentPage(),
                'last_page' => $campaigns->lastPage(),
                'per_page' => $campaigns->perPage(),
                'total' => $campaigns->total(),
            ]
        ]);
    }

    /**
     * Get campaign details
     */
    public function show(string $campaignId): JsonResponse
    {
        $campaign = MarketingCampaign::with([
            'deliveries' => function ($query) {
                $query->latest()->limit(100);
            }
        ])->findOrFail($campaignId);

        return ApiResponse::item([
            'campaign' => $campaign,
            'performance' => $this->getCampaignPerformance($campaign),
        ]);
    }

    /**
     * Create new campaign
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'type' => ['required', 'string', Rule::in(['email', 'sms', 'push', 'social', 'paid_ads'])],
            'configuration' => 'nullable|array',
            'targeting_rules' => 'nullable|array',
            'automation_triggers' => 'nullable|array',
            'personalization_rules' => 'nullable|array',
            'scheduled_at' => 'nullable|date|after:now',
            'duration_days' => 'nullable|integer|min:1|max:365',
            'timezone' => 'nullable|string|max:50',
            'budget_amount' => 'nullable|numeric|min:0',
            'budget_currency' => 'nullable|string|size:3',
            'budget_type' => ['nullable', 'string', Rule::in(['total', 'daily', 'monthly'])],
            'send_limit' => 'nullable|integer|min:1',
            'send_limit_period' => ['nullable', 'string', Rule::in(['hour', 'day', 'week', 'month'])],
            'is_ab_test' => 'boolean',
            'ab_variants' => 'nullable|array',
            'traffic_split' => 'nullable|numeric|min:0|max:1',
            'winning_metric' => ['nullable', 'string', Rule::in(['opens', 'clicks', 'conversions', 'revenue'])],
            'tags' => 'nullable|array',
            'metadata' => 'nullable|array',
        ]);

        $validated['created_by'] = auth()->id();

        $campaign = $this->marketingService->createCampaign($validated);

        return ApiResponse::item([
            'campaign' => $campaign,
            'message' => 'Campaign created successfully',
        ], 201);
    }

    /**
     * Update campaign
     */
    public function update(Request $request, string $campaignId): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string|max:1000',
            'configuration' => 'sometimes|array',
            'targeting_rules' => 'sometimes|array',
            'automation_triggers' => 'sometimes|array',
            'personalization_rules' => 'sometimes|array',
            'scheduled_at' => 'sometimes|date|after:now',
            'duration_days' => 'sometimes|integer|min:1|max:365',
            'timezone' => 'sometimes|string|max:50',
            'budget_amount' => 'sometimes|numeric|min:0',
            'budget_currency' => 'sometimes|string|size:3',
            'budget_type' => ['sometimes', 'string', Rule::in(['total', 'daily', 'monthly'])],
            'send_limit' => 'sometimes|integer|min:1',
            'send_limit_period' => ['sometimes', 'string', Rule::in(['hour', 'day', 'week', 'month'])],
            'ab_variants' => 'sometimes|array',
            'traffic_split' => 'sometimes|numeric|min:0|max:1',
            'winning_metric' => ['sometimes', 'string', Rule::in(['opens', 'clicks', 'conversions', 'revenue'])],
            'status' => ['sometimes', 'string', Rule::in(['draft', 'scheduled', 'running', 'paused', 'completed', 'cancelled'])],
            'tags' => 'sometimes|array',
            'metadata' => 'sometimes|array',
        ]);

        $campaign = $this->marketingService->updateCampaign((int) $campaignId, $validated);

        return ApiResponse::item([
            'campaign' => $campaign,
            'message' => 'Campaign updated successfully',
        ]);
    }

    /**
     * Launch campaign
     */
    public function launch(string $campaignId): JsonResponse
    {
        $campaign = $this->marketingService->launchCampaign((int) $campaignId);

        return ApiResponse::item([
            'campaign' => $campaign,
            'message' => 'Campaign launched successfully',
        ]);
    }

    /**
     * Pause campaign
     */
    public function pause(string $campaignId): JsonResponse
    {
        $campaign = MarketingCampaign::findOrFail($campaignId);
        
        if (!$campaign->canBePaused()) {
            return response()->json(['error' => 'Campaign cannot be paused in current state'], 400);
        }

        $campaign = $this->marketingService->updateCampaign((int) $campaignId, ['status' => 'paused']);

        return ApiResponse::item([
            'campaign' => $campaign,
            'message' => 'Campaign paused successfully',
        ]);
    }

    /**
     * Resume campaign
     */
    public function resume(string $campaignId): JsonResponse
    {
        $campaign = MarketingCampaign::findOrFail($campaignId);
        
        if ($campaign->status !== 'paused') {
            return response()->json(['error' => 'Only paused campaigns can be resumed'], 400);
        }

        $campaign = $this->marketingService->updateCampaign((int) $campaignId, ['status' => 'running']);

        return ApiResponse::item([
            'campaign' => $campaign,
            'message' => 'Campaign resumed successfully',
        ]);
    }

    /**
     * Delete campaign
     */
    public function destroy(string $campaignId): JsonResponse
    {
        $campaign = MarketingCampaign::findOrFail($campaignId);

        if ($campaign->status === 'running') {
            return response()->json(['error' => 'Cannot delete running campaign. Pause it first.'], 400);
        }

        $campaign->delete();

        return ApiResponse::item([
            'message' => 'Campaign deleted successfully',
        ]);
    }

    /**
     * Get campaign performance analytics
     */
    public function analytics(string $campaignId): JsonResponse
    {
        $campaign = MarketingCampaign::with(['deliveries'])->findOrFail($campaignId);
        
        $analytics = [
            'overview' => $this->getCampaignPerformance($campaign),
            'timeline' => $this->getCampaignTimeline($campaign),
            'demographics' => $this->getCampaignDemographics($campaign),
            'attribution' => $this->getCampaignAttribution($campaign),
        ];

        if ($campaign->is_ab_test) {
            $analytics['ab_testing'] = $this->getAbTestResults($campaign);
        }

        return ApiResponse::item(['analytics' => $analytics]);
    }

    /**
     * Duplicate campaign
     */
    public function duplicate(string $campaignId): JsonResponse
    {
        $original = MarketingCampaign::findOrFail($campaignId);
        
        $data = $original->toArray();
        unset($data['id'], $data['public_id'], $data['created_at'], $data['updated_at']);
        
        $data['name'] = $data['name'] . ' (Copy)';
        $data['status'] = 'draft';
        $data['created_by'] = auth()->id();

        $campaign = $this->marketingService->createCampaign($data);

        return ApiResponse::item([
            'campaign' => $campaign,
            'message' => 'Campaign duplicated successfully',
        ], 201);
    }

    // ── Helper Methods ────────────────────────────────────────────────────────

    /**
     * Get campaign performance metrics
     */
    private function getCampaignPerformance(MarketingCampaign $campaign): array
    {
        $deliveries = $campaign->deliveries;
        $total = $deliveries->count();

        if ($total === 0) {
            return [
                'total_sent' => 0,
                'total_opened' => 0,
                'total_clicked' => 0,
                'total_converted' => 0,
                'open_rate' => 0,
                'click_rate' => 0,
                'conversion_rate' => 0,
                'revenue_attributed' => 0,
                'cost_per_acquisition' => 0,
                'return_on_ad_spend' => 0,
            ];
        }

        $opened = $deliveries->where('opened_at', '!=', null)->count();
        $clicked = $deliveries->where('clicked_at', '!=', null)->count();
        $converted = $deliveries->where('converted_at', '!=', null)->count();
        $revenue = $deliveries->sum('revenue_attributed') ?: 0;
        $spend = $campaign->budget_spent ?: 0;

        return [
            'total_sent' => $total,
            'total_opened' => $opened,
            'total_clicked' => $clicked,
            'total_converted' => $converted,
            'open_rate' => round(($opened / $total) * 100, 2),
            'click_rate' => round(($clicked / $total) * 100, 2),
            'conversion_rate' => round(($converted / $total) * 100, 2),
            'revenue_attributed' => $revenue,
            'cost_per_acquisition' => $converted > 0 ? round($spend / $converted, 2) : 0,
            'return_on_ad_spend' => $spend > 0 ? round(($revenue / $spend) * 100, 2) : 0,
        ];
    }

    /**
     * Get campaign timeline data
     */
    private function getCampaignTimeline(MarketingCampaign $campaign): array
    {
        // Implementation would return daily/hourly performance data
        return [
            'daily_sends' => [],
            'daily_opens' => [],
            'daily_clicks' => [],
            'daily_conversions' => [],
        ];
    }

    /**
     * Get campaign demographics
     */
    private function getCampaignDemographics(MarketingCampaign $campaign): array
    {
        // Implementation would return audience breakdown
        return [
            'age_groups' => [],
            'locations' => [],
            'customer_segments' => [],
        ];
    }

    /**
     * Get campaign attribution data
     */
    private function getCampaignAttribution(MarketingCampaign $campaign): array
    {
        // Implementation would return attribution analysis
        return [
            'first_touch' => 0,
            'last_touch' => 0,
            'assisted_conversions' => 0,
            'attribution_models' => [],
        ];
    }

    /**
     * Get A/B test results
     */
    private function getAbTestResults(MarketingCampaign $campaign): array
    {
        // Implementation would return A/B test analysis
        return [
            'variants' => [],
            'statistical_significance' => false,
            'winning_variant' => null,
            'confidence_level' => 0,
        ];
    }
}
