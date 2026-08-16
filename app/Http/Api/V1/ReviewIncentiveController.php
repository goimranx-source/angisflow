<?php

declare(strict_types=1);

namespace App\Http\Api\V1;

use App\Domain\Growth\ReviewIncentiveService;
use App\Http\Api\ApiResponse;
use App\Http\Api\Endpoint;
use App\Models\ReviewIncentiveCampaign;
use App\Models\ReviewRequest;
use App\Domain\Sales\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

/**
 * Review Incentive API
 * 
 * Manages automated review request campaigns with incentives
 * to increase review collection and customer engagement.
 */
class ReviewIncentiveController extends Endpoint
{
    public function __construct(
        private ReviewIncentiveService $reviewIncentiveService
    ) {}

    // ── Campaign Management ────────────────────────────────────────────────────

    /**
     * List review campaigns
     */
    public function campaigns(Request $request): JsonResponse
    {
        $query = ReviewIncentiveCampaign::query()->latest();

        if ($status = $request->get('status')) {
            $query->where('status', $status);
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
    public function showCampaign(string $campaignId): JsonResponse
    {
        $campaign = ReviewIncentiveCampaign::withCount('requests')->findOrFail($campaignId);

        return ApiResponse::item([
            'campaign' => $campaign,
            'performance' => $this->getCampaignPerformance($campaign),
        ]);
    }

    /**
     * Create review campaign
     */
    public function createCampaign(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'status' => ['sometimes', 'string', Rule::in(['active', 'paused', 'ended'])],
            'trigger_rules' => 'required|array',
            'delivery_channels' => 'required|array',
            'message_templates' => 'required|array',
            'incentive_configuration' => 'required|array',
            'targeting_rules' => 'nullable|array',
            'frequency_limits' => 'nullable|array',
            'ab_testing' => 'nullable|array',
            'started_at' => 'nullable|date',
            'ended_at' => 'nullable|date|after:started_at',
        ]);

        $campaign = $this->reviewIncentiveService->createCampaign($validated);

        return ApiResponse::item([
            'campaign' => $campaign,
            'message' => 'Review campaign created successfully',
        ], 201);
    }

    /**
     * Update campaign
     */
    public function updateCampaign(Request $request, string $campaignId): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string|max:1000',
            'status' => ['sometimes', 'string', Rule::in(['active', 'paused', 'ended'])],
            'trigger_rules' => 'sometimes|array',
            'delivery_channels' => 'sometimes|array',
            'message_templates' => 'sometimes|array',
            'incentive_configuration' => 'sometimes|array',
            'targeting_rules' => 'sometimes|array',
            'frequency_limits' => 'sometimes|array',
            'ab_testing' => 'sometimes|array',
            'ended_at' => 'nullable|date|after:started_at',
        ]);

        try {
            $campaign = $this->reviewIncentiveService->updateCampaign((int) $campaignId, $validated);

            return ApiResponse::item([
                'campaign' => $campaign,
                'message' => 'Campaign updated successfully',
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Toggle campaign status
     */
    public function toggleCampaign(Request $request, string $campaignId): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(['active', 'paused', 'ended'])],
        ]);

        $campaign = $this->reviewIncentiveService->toggleCampaign((int) $campaignId, $validated['status']);

        return ApiResponse::item([
            'campaign' => $campaign,
            'message' => 'Campaign status updated successfully',
        ]);
    }

    /**
     * Delete campaign
     */
    public function destroyCampaign(string $campaignId): JsonResponse
    {
        $campaign = ReviewIncentiveCampaign::findOrFail($campaignId);

        if ($campaign->status === 'active') {
            return response()->json(['error' => 'Cannot delete active campaign. Pause or end it first.'], 400);
        }

        $campaign->delete();

        return ApiResponse::item([
            'message' => 'Campaign deleted successfully',
        ]);
    }

    // ── Review Request Management ──────────────────────────────────────────────

    /**
     * List review requests
     */
    public function requests(Request $request): JsonResponse
    {
        $query = ReviewRequest::query()
            ->with(['campaign', 'customer', 'order'])
            ->latest();

        // Filter by campaign
        if ($campaignId = $request->get('campaign_id')) {
            $query->where('campaign_id', $campaignId);
        }

        // Filter by status
        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        // Filter by customer
        if ($customerId = $request->get('customer_id')) {
            $query->where('customer_id', $customerId);
        }

        // Filter by date range
        if ($from = $request->get('from')) {
            $query->where('created_at', '>=', $from);
        }

        if ($to = $request->get('to')) {
            $query->where('created_at', '<=', $to);
        }

        $requests = $query->paginate($request->get('per_page', 20));

        return ApiResponse::item([
            'requests' => $requests->items(),
            'pagination' => [
                'current_page' => $requests->currentPage(),
                'last_page' => $requests->lastPage(),
                'per_page' => $requests->perPage(),
                'total' => $requests->total(),
            ]
        ]);
    }

    /**
     * Get review request details
     */
    public function showRequest(string $requestId): JsonResponse
    {
        $request = ReviewRequest::with(['campaign', 'customer', 'order'])
            ->findOrFail($requestId);

        return ApiResponse::item(['request' => $request]);
    }

    /**
     * Create manual review request
     */
    public function createRequest(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'campaign_id' => 'required|exists:review_incentive_campaigns,id',
            'order_id' => 'required|exists:orders,id',
            'customer_id' => 'required|exists:customers,id',
            'metadata' => 'nullable|array',
        ]);

        $campaign = ReviewIncentiveCampaign::findOrFail($validated['campaign_id']);
        $order = Order::findOrFail($validated['order_id']);
        $customer = $order->customer;

        if (!$customer || $customer->id != $validated['customer_id']) {
            return response()->json(['error' => 'Customer does not match order'], 400);
        }

        $reviewRequest = $this->reviewIncentiveService->createReviewRequest(
            $campaign,
            $order,
            $customer,
            $validated['metadata'] ?? []
        );

        return ApiResponse::item([
            'request' => $reviewRequest->load(['campaign', 'customer', 'order']),
            'message' => 'Review request created successfully',
        ], 201);
    }

    /**
     * Send review request
     */
    public function sendRequest(string $requestId): JsonResponse
    {
        try {
            $sent = $this->reviewIncentiveService->sendReviewRequest((int) $requestId);

            if ($sent) {
                return ApiResponse::item([
                    'message' => 'Review request sent successfully',
                ]);
            } else {
                return response()->json(['error' => 'Failed to send review request'], 500);
            }
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Process order for review requests
     */
    public function processOrder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order_id' => 'required|exists:orders,id',
        ]);

        $order = Order::with('customer')->findOrFail($validated['order_id']);

        $requests = $this->reviewIncentiveService->processOrderForReviews($order);

        return ApiResponse::item([
            'requests_created' => $requests->count(),
            'requests' => $requests,
            'message' => "Created {$requests->count()} review request(s)",
        ]);
    }

    /**
     * Resend review request
     */
    public function resendRequest(string $requestId): JsonResponse
    {
        $request = ReviewRequest::findOrFail($requestId);

        if ($request->status !== 'sent') {
            return response()->json(['error' => 'Can only resend previously sent requests'], 400);
        }

        // Reset status to pending
        $request->update(['status' => 'pending']);

        // Send again
        return $this->sendRequest($requestId);
    }

    // ── Public Review Submission ───────────────────────────────────────────────

    /**
     * Get review form by token (public endpoint)
     */
    public function getReviewForm(string $token): JsonResponse
    {
        $request = ReviewRequest::with(['order', 'customer', 'campaign'])
            ->where('token', $token)
            ->where('expires_at', '>', now())
            ->first();

        if (!$request) {
            return response()->json(['error' => 'Invalid or expired review link'], 404);
        }

        // Track that the link was opened
        $this->reviewIncentiveService->trackEngagement($token, 'opened');

        return ApiResponse::item([
            'request' => [
                'token' => $request->token,
                'order_number' => $request->order->number,
                'order_date' => $request->order->created_at,
                'customer_name' => $request->customer->name,
                'incentive_type' => $request->incentive_type,
                'incentive_value' => $request->incentive_value,
                'expires_at' => $request->expires_at,
            ],
            'order_items' => $request->order->items->map(function ($item) {
                return [
                    'product_name' => $item->product_name,
                    'variant_name' => $item->variant_name,
                    'quantity' => $item->quantity,
                ];
            }),
        ]);
    }

    /**
     * Submit review (public endpoint)
     */
    public function submitReview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => 'required|string',
            'rating' => 'required|integer|min:1|max:5',
            'text' => 'nullable|string|max:2000',
            'images' => 'nullable|array|max:5',
            'images.*' => 'nullable|string',
            'product_ratings' => 'nullable|array',
        ]);

        // Track click engagement
        $this->reviewIncentiveService->trackEngagement($validated['token'], 'clicked');

        try {
            $result = $this->reviewIncentiveService->processReviewSubmission(
                $validated['token'],
                [
                    'rating' => $validated['rating'],
                    'text' => $validated['text'] ?? null,
                    'images' => $validated['images'] ?? [],
                    'product_ratings' => $validated['product_ratings'] ?? [],
                ]
            );

            return ApiResponse::item([
                'message' => 'Thank you for your review!',
                'incentive' => $result['incentive'],
                'success' => true,
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Track engagement (public endpoint)
     */
    public function trackEngagement(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => 'required|string',
            'event' => ['required', 'string', Rule::in(['opened', 'clicked'])],
        ]);

        $tracked = $this->reviewIncentiveService->trackEngagement(
            $validated['token'],
            $validated['event']
        );

        return ApiResponse::item(['tracked' => $tracked]);
    }

    // ── Analytics ─────────────────────────────────────────────────────────────

    /**
     * Get campaign analytics
     */
    public function campaignAnalytics(string $campaignId): JsonResponse
    {
        $campaign = ReviewIncentiveCampaign::with(['requests'])->findOrFail($campaignId);

        $analytics = [
            'overview' => $this->getCampaignPerformance($campaign),
            'engagement_funnel' => $this->getEngagementFunnel($campaign),
            'incentive_impact' => $this->getIncentiveImpact($campaign),
            'sentiment_analysis' => $this->getSentimentAnalysis($campaign),
        ];

        if ($campaign->ab_testing['enabled'] ?? false) {
            $analytics['ab_testing'] = $this->getAbTestingResults($campaign);
        }

        return ApiResponse::item(['analytics' => $analytics]);
    }

    /**
     * Get overall review program analytics
     */
    public function overallAnalytics(Request $request): JsonResponse
    {
        $analytics = [
            'total_campaigns' => ReviewIncentiveCampaign::count(),
            'active_campaigns' => ReviewIncentiveCampaign::where('status', 'active')->count(),
            'total_requests' => ReviewRequest::count(),
            'completion_rate' => $this->getOverallCompletionRate(),
            'average_rating' => $this->getAverageRating(),
            'incentive_cost' => $this->getTotalIncentiveCost(),
            'reviews_by_month' => $this->getReviewsByMonth(),
            'top_performing_campaigns' => $this->getTopCampaigns(),
        ];

        return ApiResponse::item(['analytics' => $analytics]);
    }

    // ── Helper Methods ────────────────────────────────────────────────────────

    /**
     * Get campaign performance metrics
     */
    private function getCampaignPerformance(ReviewIncentiveCampaign $campaign): array
    {
        $requests = $campaign->requests;
        $total = $requests->count();

        if ($total === 0) {
            return [
                'total_sent' => 0,
                'total_opened' => 0,
                'total_clicked' => 0,
                'total_completed' => 0,
                'open_rate' => 0,
                'click_rate' => 0,
                'completion_rate' => 0,
                'average_rating' => 0,
                'incentives_processed' => 0,
            ];
        }

        $sent = $requests->where('status', '!=', 'pending')->count();
        $completed = $requests->where('status', 'completed')->count();
        
        return [
            'total_sent' => $campaign->total_sent ?? $sent,
            'total_opened' => $campaign->total_opened ?? 0,
            'total_clicked' => $campaign->total_clicked ?? 0,
            'total_completed' => $campaign->total_completed ?? $completed,
            'open_rate' => $campaign->open_rate ?? 0,
            'click_rate' => $campaign->click_rate ?? 0,
            'completion_rate' => $campaign->conversion_rate ?? ($sent > 0 ? round(($completed / $sent) * 100, 2) : 0),
            'average_rating' => $requests->whereNotNull('review_rating')->avg('review_rating') ?? 0,
            'incentives_processed' => $requests->where('incentive_processed', true)->count(),
        ];
    }

    /**
     * Get engagement funnel data
     */
    private function getEngagementFunnel(ReviewIncentiveCampaign $campaign): array
    {
        $requests = $campaign->requests;
        $total = $requests->count();

        return [
            'created' => $total,
            'sent' => $requests->whereIn('status', ['sent', 'completed'])->count(),
            'opened' => $requests->where(function ($q) {
                $q->whereNotNull('engagement_data->opened_at');
            })->count(),
            'clicked' => $requests->where(function ($q) {
                $q->whereNotNull('engagement_data->clicked_at');
            })->count(),
            'completed' => $requests->where('status', 'completed')->count(),
        ];
    }

    /**
     * Get incentive impact analysis
     */
    private function getIncentiveImpact(ReviewIncentiveCampaign $campaign): array
    {
        return [
            'total_incentives_offered' => $campaign->requests->count(),
            'incentives_claimed' => $campaign->requests->where('incentive_processed', true)->count(),
            'incentive_redemption_rate' => 0, // Calculate from completed requests
            'average_incentive_value' => $campaign->incentive_configuration['value'] ?? 0,
        ];
    }

    /**
     * Get sentiment analysis
     */
    private function getSentimentAnalysis(ReviewIncentiveCampaign $campaign): array
    {
        $reviews = $campaign->requests->where('status', 'completed');
        
        return [
            'positive' => $reviews->where('review_rating', '>=', 4)->count(),
            'neutral' => $reviews->where('review_rating', 3)->count(),
            'negative' => $reviews->where('review_rating', '<=', 2)->count(),
            'with_text' => $reviews->whereNotNull('review_text')->count(),
            'with_images' => 0, // Would count images in review_data
        ];
    }

    /**
     * Get A/B testing results
     */
    private function getAbTestingResults(ReviewIncentiveCampaign $campaign): array
    {
        return [
            'variants' => [],
            'statistical_significance' => false,
            'winning_variant' => null,
        ];
    }

    /**
     * Get overall completion rate
     */
    private function getOverallCompletionRate(): float
    {
        $total = ReviewRequest::whereIn('status', ['sent', 'completed'])->count();
        $completed = ReviewRequest::where('status', 'completed')->count();

        return $total > 0 ? round(($completed / $total) * 100, 2) : 0;
    }

    /**
     * Get average rating across all reviews
     */
    private function getAverageRating(): float
    {
        return round(ReviewRequest::whereNotNull('review_rating')->avg('review_rating') ?? 0, 2);
    }

    /**
     * Get total incentive cost
     */
    private function getTotalIncentiveCost(): float
    {
        // Implementation would calculate actual cost
        return 0;
    }

    /**
     * Get reviews by month
     */
    private function getReviewsByMonth(): array
    {
        return ReviewRequest::where('status', 'completed')
            ->selectRaw('DATE_FORMAT(completed_at, "%Y-%m") as month, COUNT(*) as count')
            ->groupBy('month')
            ->orderBy('month')
            ->limit(12)
            ->pluck('count', 'month')
            ->toArray();
    }

    /**
     * Get top performing campaigns
     */
    private function getTopCampaigns(): array
    {
        return ReviewIncentiveCampaign::query()
            ->orderByDesc('conversion_rate')
            ->limit(5)
            ->get(['id', 'public_id', 'name', 'total_sent', 'total_completed', 'conversion_rate'])
            ->toArray();
    }
}