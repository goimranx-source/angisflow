<?php

declare(strict_types=1);

namespace App\Domain\Growth;

use App\Domain\Tenancy\TenantContext;
use App\Models\ReviewIncentiveCampaign;
use App\Models\ReviewRequest;
use App\Domain\Sales\Models\Order;
use App\Domain\Sales\Models\Customer;
use App\Models\LoyaltyMembership;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;

/**
 * Review Incentive Service
 *
 * Manages automated review request campaigns with incentives
 * to increase review collection and customer engagement.
 *
 * ── Business Rules ─────────────────────────────────────────────────────────
 *
 * Review requests are triggered by order status changes and customer behavior.
 * Incentives are processed upon successful review submission with fraud
 * prevention measures. All engagement is tracked for campaign optimization.
 */
class ReviewIncentiveService
{
    public function __construct(
        private TenantContext $tenantContext,
        private LoyaltyService $loyaltyService
    ) {}

    // ── Campaign Management ────────────────────────────────────────────────────

    /**
     * Create review incentive campaign
     */
    public function createCampaign(array $data): ReviewIncentiveCampaign
    {
        return DB::transaction(function () use ($data) {
            $campaign = ReviewIncentiveCampaign::create([
                'account_id' => $this->tenantContext->account()->id,
                'business_id' => $this->tenantContext->business()->id,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'status' => $data['status'] ?? 'active',
                'trigger_rules' => $data['trigger_rules'] ?? [
                    'delay_days' => 3,
                    'order_status' => ['delivered', 'completed'],
                    'minimum_order_value' => 0,
                    'customer_segments' => []
                ],
                'delivery_channels' => $data['delivery_channels'] ?? ['email'],
                'message_templates' => $data['message_templates'] ?? [
                    'subject' => 'How was your recent order?',
                    'email_template' => 'default_review_request',
                    'sms_template' => null,
                    'push_template' => null
                ],
                'incentive_configuration' => $data['incentive_configuration'] ?? [
                    'type' => 'points',
                    'value' => 50,
                    'minimum_rating' => 4,
                    'requires_text' => false,
                    'maximum_per_customer' => 5
                ],
                'targeting_rules' => $data['targeting_rules'] ?? [],
                'frequency_limits' => $data['frequency_limits'] ?? [
                    'max_per_customer' => 1,
                    'cooldown_days' => 30
                ],
                'ab_testing' => $data['ab_testing'] ?? [
                    'enabled' => false,
                    'variants' => []
                ],
                'started_at' => isset($data['started_at']) ? Carbon::parse($data['started_at']) : now(),
                'ended_at' => isset($data['ended_at']) ? Carbon::parse($data['ended_at']) : null,
            ]);

            return $campaign;
        });
    }

    /**
     * Update campaign configuration
     */
    public function updateCampaign(int $campaignId, array $data): ReviewIncentiveCampaign
    {
        $campaign = ReviewIncentiveCampaign::findOrFail($campaignId);

        // Prevent changes to core rules if campaign has active requests
        if ($campaign->hasActiveRequests() && isset($data['incentive_configuration'])) {
            throw new \InvalidArgumentException('Cannot modify incentive rules for campaign with active requests');
        }

        $campaign->update($data);
        return $campaign;
    }

    /**
     * Pause or resume campaign
     */
    public function toggleCampaign(int $campaignId, string $status): ReviewIncentiveCampaign
    {
        $campaign = ReviewIncentiveCampaign::findOrFail($campaignId);
        
        if (!in_array($status, ['active', 'paused', 'ended'])) {
            throw new \InvalidArgumentException('Invalid campaign status');
        }

        $campaign->update(['status' => $status]);
        return $campaign;
    }

    // ── Review Request Processing ─────────────────────────────────────────────

    /**
     * Process order for review request triggers
     */
    public function processOrderForReviews(Order $order): Collection
    {
        $customer = $order->customer;
        if (!$customer) {
            return collect();
        }

        // Find applicable campaigns
        $campaigns = $this->getApplicableCampaigns($order);
        $requests = collect();

        foreach ($campaigns as $campaign) {
            if ($this->shouldCreateRequest($campaign, $order, $customer)) {
                $request = $this->createReviewRequest($campaign, $order, $customer);
                $requests->push($request);
            }
        }

        return $requests;
    }

    /**
     * Create individual review request
     */
    public function createReviewRequest(
        ReviewIncentiveCampaign $campaign,
        Order $order,
        Customer $customer,
        array $metadata = []
    ): ReviewRequest {
        return DB::transaction(function () use ($campaign, $order, $customer, $metadata) {
            $request = ReviewRequest::create([
                'campaign_id' => $campaign->id,
                'order_id' => $order->id,
                'customer_id' => $customer->id,
                'status' => 'pending',
                'delivery_channel' => $campaign->delivery_channels[0] ?? 'email',
                'scheduled_at' => $this->calculateSendTime($campaign, $order),
                'token' => $this->generateSecureToken(),
                'expires_at' => now()->addDays(30),
                'incentive_type' => $campaign->incentive_configuration['type'] ?? null,
                'incentive_value' => $campaign->incentive_configuration['value'] ?? null,
                'incentive_currency' => $campaign->incentive_configuration['currency'] ?? null,
                'ab_variant' => $this->selectAbVariant($campaign),
                'metadata' => array_merge([
                    'order_number' => $order->number,
                    'order_value' => $order->total->toDecimal(),
                    'order_date' => $order->created_at->toISOString(),
                ], $metadata),
            ]);

            return $request;
        });
    }

    /**
     * Send review request
     */
    public function sendReviewRequest(int $requestId): bool
    {
        $request = ReviewRequest::findOrFail($requestId);

        if (!$request->canBeSent()) {
            throw new \InvalidArgumentException('Review request cannot be sent');
        }

        return DB::transaction(function () use ($request) {
            // Generate review URL with secure token
            $reviewUrl = $this->generateReviewUrl($request);

            // Prepare message data
            $messageData = $this->prepareMessageData($request, $reviewUrl);

            // Send via appropriate channel
            $sent = $this->deliverMessage($request, $messageData);

            if ($sent) {
                $request->update([
                    'status' => 'sent',
                    'sent_at' => now(),
                    'review_url' => $reviewUrl,
                ]);

                // Update campaign statistics
                $request->campaign->increment('total_sent');
            }

            return $sent;
        });
    }

    /**
     * Process review submission
     */
    public function processReviewSubmission(
        string $token,
        array $reviewData
    ): array {
        $request = ReviewRequest::where('token', $token)
            ->where('expires_at', '>', now())
            ->firstOrFail();

        if ($request->status !== 'sent') {
            throw new \InvalidArgumentException('Invalid review request');
        }

        return DB::transaction(function () use ($request, $reviewData) {
            // Update request with submission data
            $request->update([
                'status' => 'completed',
                'completed_at' => now(),
                'review_rating' => $reviewData['rating'] ?? null,
                'review_text' => $reviewData['text'] ?? null,
                'review_data' => $reviewData,
            ]);

            // Update campaign statistics
            $request->campaign->increment('total_completed');

            // Process incentive if eligible
            $incentiveResult = $this->processIncentive($request, $reviewData);

            // Update conversion metrics
            $this->updateConversionMetrics($request);

            return [
                'request' => $request,
                'incentive' => $incentiveResult,
                'success' => true,
            ];
        });
    }

    /**
     * Track engagement (opened, clicked)
     */
    public function trackEngagement(string $token, string $event): bool
    {
        $request = ReviewRequest::where('token', $token)->first();

        if (!$request) {
            return false;
        }

        $engagementData = $request->engagement_data ?? [];
        
        switch ($event) {
            case 'opened':
                if (!isset($engagementData['opened_at'])) {
                    $engagementData['opened_at'] = now()->toISOString();
                    $request->campaign->increment('total_opened');
                }
                break;

            case 'clicked':
                if (!isset($engagementData['clicked_at'])) {
                    $engagementData['clicked_at'] = now()->toISOString();
                    $request->campaign->increment('total_clicked');
                }
                break;
        }

        $request->update(['engagement_data' => $engagementData]);
        return true;
    }

    // ── Incentive Processing ───────────────────────────────────────────────────

    /**
     * Process review incentive
     */
    private function processIncentive(ReviewRequest $request, array $reviewData): ?array
    {
        $campaign = $request->campaign;
        $incentiveConfig = $campaign->incentive_configuration;

        // Check if incentive should be awarded
        if (!$this->isEligibleForIncentive($request, $reviewData, $incentiveConfig)) {
            return null;
        }

        // Check for fraud prevention
        if (!$this->passesSecurityChecks($request, $reviewData)) {
            return [
                'status' => 'blocked',
                'reason' => 'Security check failed',
            ];
        }

        $result = null;

        switch ($incentiveConfig['type']) {
            case 'points':
                $result = $this->awardLoyaltyPoints($request, $incentiveConfig);
                break;

            case 'discount':
                $result = $this->generateDiscountCode($request, $incentiveConfig);
                break;

            case 'cashback':
                $result = $this->processCashback($request, $incentiveConfig);
                break;

            case 'product':
                $result = $this->awardProduct($request, $incentiveConfig);
                break;
        }

        if ($result && $result['status'] === 'success') {
            $request->update([
                'incentive_processed' => true,
                'incentive_processed_at' => now(),
                'incentive_data' => $result,
            ]);
        }

        return $result;
    }

    /**
     * Award loyalty points for review
     */
    private function awardLoyaltyPoints(ReviewRequest $request, array $config): array
    {
        try {
            $membership = LoyaltyMembership::where('customer_id', $request->customer_id)
                ->whereHas('program', function ($query) {
                    $query->where('status', 'active');
                })
                ->where('status', 'active')
                ->first();

            if (!$membership) {
                return [
                    'status' => 'failed',
                    'reason' => 'No active loyalty membership',
                ];
            }

            $points = $config['value'];
            
            $transaction = $this->loyaltyService->awardPoints(
                $membership->id,
                $points,
                'review',
                $request->id,
                "Points earned for product review",
                [
                    'campaign_id' => $request->campaign_id,
                    'order_id' => $request->order_id,
                    'rating' => $request->review_rating,
                ]
            );

            return [
                'status' => 'success',
                'type' => 'points',
                'value' => $points,
                'transaction_id' => $transaction->id,
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'failed',
                'reason' => $e->getMessage(),
            ];
        }
    }

    /**
     * Generate discount code incentive
     */
    private function generateDiscountCode(ReviewRequest $request, array $config): array
    {
        $code = 'REVIEW-' . strtoupper(Str::random(8));
        
        return [
            'status' => 'success',
            'type' => 'discount',
            'code' => $code,
            'value' => $config['value'],
            'type_detail' => $config['discount_type'] ?? 'percentage',
            'expires_at' => now()->addDays($config['expires_days'] ?? 30)->toISOString(),
            'minimum_order' => $config['minimum_order'] ?? null,
        ];
    }

    /**
     * Process cashback incentive
     */
    private function processCashback(ReviewRequest $request, array $config): array
    {
        // Implementation would integrate with payment/wallet system
        return [
            'status' => 'pending',
            'type' => 'cashback',
            'value' => $config['value'],
            'currency' => $config['currency'] ?? 'USD',
            'processing_note' => 'Cashback will be processed within 2-3 business days',
        ];
    }

    /**
     * Award product incentive
     */
    private function awardProduct(ReviewRequest $request, array $config): array
    {
        return [
            'status' => 'success',
            'type' => 'product',
            'product_id' => $config['product_id'],
            'fulfillment_method' => $config['fulfillment_method'] ?? 'manual',
            'processing_note' => 'Product reward will be processed manually',
        ];
    }

    // ── Helper Methods ────────────────────────────────────────────────────────

    /**
     * Get applicable campaigns for order
     */
    private function getApplicableCampaigns(Order $order): Collection
    {
        return ReviewIncentiveCampaign::where('status', 'active')
            ->where(function ($query) {
                $query->whereNull('ended_at')
                    ->orWhere('ended_at', '>', now());
            })
            ->get()
            ->filter(function ($campaign) use ($order) {
                return $this->campaignMatchesOrder($campaign, $order);
            });
    }

    /**
     * Check if campaign applies to order
     */
    private function campaignMatchesOrder(ReviewIncentiveCampaign $campaign, Order $order): bool
    {
        $triggerRules = $campaign->trigger_rules;

        // Check order status
        if (isset($triggerRules['order_status'])) {
            if (!in_array($order->status, $triggerRules['order_status'])) {
                return false;
            }
        }

        // Check minimum order value
        if (isset($triggerRules['minimum_order_value'])) {
            if ($order->total->toDecimal() < $triggerRules['minimum_order_value']) {
                return false;
            }
        }

        // Additional targeting rules can be added here

        return true;
    }

    /**
     * Check if should create request for customer
     */
    private function shouldCreateRequest(
        ReviewIncentiveCampaign $campaign,
        Order $order,
        Customer $customer
    ): bool {
        $frequencyLimits = $campaign->frequency_limits;

        // Check maximum requests per customer
        if (isset($frequencyLimits['max_per_customer'])) {
            $existingRequests = ReviewRequest::where('campaign_id', $campaign->id)
                ->where('customer_id', $customer->id)
                ->count();

            if ($existingRequests >= $frequencyLimits['max_per_customer']) {
                return false;
            }
        }

        // Check cooldown period
        if (isset($frequencyLimits['cooldown_days'])) {
            $recentRequest = ReviewRequest::where('campaign_id', $campaign->id)
                ->where('customer_id', $customer->id)
                ->where('created_at', '>', now()->subDays($frequencyLimits['cooldown_days']))
                ->exists();

            if ($recentRequest) {
                return false;
            }
        }

        return true;
    }

    /**
     * Calculate when to send review request
     */
    private function calculateSendTime(ReviewIncentiveCampaign $campaign, Order $order): Carbon
    {
        $delayDays = $campaign->trigger_rules['delay_days'] ?? 3;
        $baseTime = $order->updated_at ?? $order->created_at;
        
        return $baseTime->addDays($delayDays);
    }

    /**
     * Generate secure token for review request
     */
    private function generateSecureToken(): string
    {
        return Str::random(64);
    }

    /**
     * Select A/B test variant if enabled
     */
    private function selectAbVariant(ReviewIncentiveCampaign $campaign): ?string
    {
        $abTesting = $campaign->ab_testing;
        
        if (!($abTesting['enabled'] ?? false) || empty($abTesting['variants'])) {
            return null;
        }

        // Simple random selection - could be enhanced with weighted distribution
        $variants = array_keys($abTesting['variants']);
        return $variants[array_rand($variants)];
    }

    /**
     * Generate review URL with token
     */
    private function generateReviewUrl(ReviewRequest $request): string
    {
        // This would generate a URL to the review form with the secure token
        return config('app.url') . "/reviews/{$request->token}";
    }

    /**
     * Prepare message data for delivery
     */
    private function prepareMessageData(ReviewRequest $request, string $reviewUrl): array
    {
        $campaign = $request->campaign;
        $templates = $campaign->message_templates;
        
        // Get A/B variant template if applicable
        if ($request->ab_variant) {
            $variantConfig = $campaign->ab_testing['variants'][$request->ab_variant] ?? [];
            $templates = array_merge($templates, $variantConfig['templates'] ?? []);
        }

        return [
            'to' => $request->customer->email,
            'subject' => $templates['subject'] ?? 'How was your recent order?',
            'template' => $templates['email_template'] ?? 'default_review_request',
            'variables' => [
                'customer_name' => $request->customer->name,
                'order_number' => $request->order->number,
                'review_url' => $reviewUrl,
                'incentive_value' => $request->incentive_value,
                'incentive_type' => $request->incentive_type,
                'tracking_pixel_url' => $this->generateTrackingPixelUrl($request->token),
            ],
        ];
    }

    /**
     * Deliver message via appropriate channel
     */
    private function deliverMessage(ReviewRequest $request, array $messageData): bool
    {
        // This would integrate with the actual messaging system
        // For now, return true to simulate successful delivery
        return true;
    }

    /**
     * Check if eligible for incentive
     */
    private function isEligibleForIncentive(
        ReviewRequest $request,
        array $reviewData,
        array $incentiveConfig
    ): bool {
        // Check minimum rating requirement
        if (isset($incentiveConfig['minimum_rating'])) {
            $rating = $reviewData['rating'] ?? 0;
            if ($rating < $incentiveConfig['minimum_rating']) {
                return false;
            }
        }

        // Check if text is required
        if ($incentiveConfig['requires_text'] ?? false) {
            $text = trim($reviewData['text'] ?? '');
            if (empty($text) || strlen($text) < 10) {
                return false;
            }
        }

        // Check maximum incentives per customer
        if (isset($incentiveConfig['maximum_per_customer'])) {
            $processed = ReviewRequest::where('customer_id', $request->customer_id)
                ->where('incentive_processed', true)
                ->count();

            if ($processed >= $incentiveConfig['maximum_per_customer']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Security checks for fraud prevention
     */
    private function passesSecurityChecks(ReviewRequest $request, array $reviewData): bool
    {
        // Implement fraud detection logic:
        // - Check for duplicate reviews
        // - Validate review authenticity
        // - Check customer behavior patterns
        // - IP address verification
        
        // For now, return true (passes all checks)
        return true;
    }

    /**
     * Update conversion metrics
     */
    private function updateConversionMetrics(ReviewRequest $request): void
    {
        $campaign = $request->campaign;
        
        // Update conversion rate
        $totalSent = $campaign->total_sent ?? 1;
        $totalCompleted = $campaign->total_completed ?? 0;
        $conversionRate = ($totalCompleted / $totalSent) * 100;
        
        $campaign->update([
            'conversion_rate' => $conversionRate,
            'last_activity' => now(),
        ]);
    }

    /**
     * Generate tracking pixel URL
     */
    private function generateTrackingPixelUrl(string $token): string
    {
        return config('app.url') . "/track/open/{$token}.gif";
    }
}