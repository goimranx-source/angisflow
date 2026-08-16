<?php

declare(strict_types=1);

namespace App\Http\Api\V1;

use App\Domain\Growth\LoyaltyService;
use App\Http\Api\ApiResponse;
use App\Http\Api\Endpoint;
use App\Models\LoyaltyProgram;
use App\Models\LoyaltyMembership;
use App\Models\LoyaltyReward;
use App\Models\LoyaltyTransaction;
use App\Domain\Sales\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

/**
 * Loyalty Program API
 * 
 * Manages loyalty programs, customer enrollment, point transactions,
 * and reward redemption for customer retention.
 */
class LoyaltyController extends Endpoint
{
    public function __construct(
        private LoyaltyService $loyaltyService
    ) {}

    // ── Program Management ────────────────────────────────────────────────────

    /**
     * List loyalty programs
     */
    public function programs(Request $request): JsonResponse
    {
        $query = LoyaltyProgram::query()->withCount('memberships')->latest();

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        $programs = $query->paginate($request->get('per_page', 20));

        return ApiResponse::item([
            'programs' => $programs->items(),
            'pagination' => [
                'current_page' => $programs->currentPage(),
                'last_page' => $programs->lastPage(),
                'per_page' => $programs->perPage(),
                'total' => $programs->total(),
            ]
        ]);
    }

    /**
     * Get program details
     */
    public function showProgram(string $programId): JsonResponse
    {
        $program = LoyaltyProgram::withCount('memberships')->findOrFail($programId);

        return ApiResponse::item([
            'program' => $program,
            'statistics' => $this->getProgramStatistics($program),
        ]);
    }

    /**
     * Create loyalty program
     */
    public function createProgram(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'type' => ['required', 'string', Rule::in(['points', 'cashback', 'tier', 'hybrid'])],
            'status' => ['sometimes', 'string', Rule::in(['active', 'inactive', 'draft'])],
            'point_name' => 'nullable|string|max:50',
            'point_name_plural' => 'nullable|string|max:50',
            'earning_rules' => 'nullable|array',
            'redemption_rules' => 'nullable|array',
            'tier_structure' => 'nullable|array',
            'referral_program' => 'nullable|array',
            'configuration' => 'nullable|array',
            'started_at' => 'nullable|date',
            'ended_at' => 'nullable|date|after:started_at',
        ]);

        $program = $this->loyaltyService->createProgram($validated);

        return ApiResponse::item([
            'program' => $program,
            'message' => 'Loyalty program created successfully',
        ], 201);
    }

    /**
     * Update loyalty program
     */
    public function updateProgram(Request $request, string $programId): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string|max:1000',
            'status' => ['sometimes', 'string', Rule::in(['active', 'inactive', 'draft'])],
            'point_name' => 'sometimes|string|max:50',
            'point_name_plural' => 'sometimes|string|max:50',
            'earning_rules' => 'sometimes|array',
            'redemption_rules' => 'sometimes|array',
            'tier_structure' => 'sometimes|array',
            'referral_program' => 'sometimes|array',
            'configuration' => 'sometimes|array',
            'ended_at' => 'nullable|date|after:started_at',
        ]);

        $program = $this->loyaltyService->updateProgram((int) $programId, $validated);

        return ApiResponse::item([
            'program' => $program,
            'message' => 'Loyalty program updated successfully',
        ]);
    }

    // ── Member Management ─────────────────────────────────────────────────────

    /**
     * List loyalty memberships
     */
    public function memberships(Request $request): JsonResponse
    {
        $query = LoyaltyMembership::query()
            ->with(['program', 'customer'])
            ->latest();

        // Filter by program
        if ($programId = $request->get('program_id')) {
            $query->where('loyalty_program_id', $programId);
        }

        // Filter by status
        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        // Filter by tier
        if ($tier = $request->get('tier')) {
            $query->where('current_tier', $tier);
        }

        // Search by customer
        if ($search = $request->get('search')) {
            $query->whereHas('customer', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $memberships = $query->paginate($request->get('per_page', 20));

        return ApiResponse::item([
            'memberships' => $memberships->items(),
            'pagination' => [
                'current_page' => $memberships->currentPage(),
                'last_page' => $memberships->lastPage(),
                'per_page' => $memberships->perPage(),
                'total' => $memberships->total(),
            ]
        ]);
    }

    /**
     * Get membership details
     */
    public function showMembership(string $membershipId): JsonResponse
    {
        $membership = LoyaltyMembership::with([
            'program',
            'customer',
            'transactions' => function ($query) {
                $query->latest()->limit(50);
            }
        ])->findOrFail($membershipId);

        return ApiResponse::item([
            'membership' => $membership,
            'statistics' => $this->getMembershipStatistics($membership),
        ]);
    }

    /**
     * Enroll customer in program
     */
    public function enrollCustomer(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'program_id' => 'required|exists:loyalty_programs,id',
            'customer_id' => 'required|exists:customers,id',
            'referred_by_code' => 'nullable|string|exists:loyalty_memberships,referral_code',
            'metadata' => 'nullable|array',
        ]);

        try {
            $membership = $this->loyaltyService->enrollCustomer(
                $validated['program_id'],
                $validated['customer_id'],
                $validated
            );

            return ApiResponse::item([
                'membership' => $membership->load(['program', 'customer']),
                'message' => 'Customer enrolled successfully',
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Update membership
     */
    public function updateMembership(Request $request, string $membershipId): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'string', Rule::in(['active', 'suspended', 'cancelled'])],
            'current_tier' => 'sometimes|string|max:100',
            'metadata' => 'sometimes|array',
        ]);

        $membership = $this->loyaltyService->updateMembership((int) $membershipId, $validated);

        return ApiResponse::item([
            'membership' => $membership,
            'message' => 'Membership updated successfully',
        ]);
    }

    // ── Points Management ─────────────────────────────────────────────────────

    /**
     * Award points to member
     */
    public function awardPoints(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'membership_id' => 'required|exists:loyalty_memberships,id',
            'points' => 'required|integer|min:1',
            'source_type' => 'required|string|max:50',
            'source_id' => 'nullable|integer',
            'description' => 'nullable|string|max:500',
            'metadata' => 'nullable|array',
        ]);

        try {
            $transaction = $this->loyaltyService->awardPoints(
                $validated['membership_id'],
                $validated['points'],
                $validated['source_type'],
                $validated['source_id'] ?? null,
                $validated['description'] ?? null,
                $validated['metadata'] ?? []
            );

            return ApiResponse::item([
                'transaction' => $transaction->load('membership'),
                'message' => 'Points awarded successfully',
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Redeem points
     */
    public function redeemPoints(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'membership_id' => 'required|exists:loyalty_memberships,id',
            'points' => 'required|integer|min:1',
            'reward_type' => 'required|string|max:50',
            'reward_id' => 'nullable|integer',
            'description' => 'nullable|string|max:500',
            'metadata' => 'nullable|array',
        ]);

        try {
            $transaction = $this->loyaltyService->redeemPoints(
                $validated['membership_id'],
                $validated['points'],
                $validated['reward_type'],
                $validated['reward_id'] ?? null,
                $validated['description'] ?? null,
                $validated['metadata'] ?? []
            );

            return ApiResponse::item([
                'transaction' => $transaction->load('membership'),
                'message' => 'Points redeemed successfully',
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Adjust points balance (admin)
     */
    public function adjustPoints(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'membership_id' => 'required|exists:loyalty_memberships,id',
            'points' => 'required|integer|not_in:0',
            'reason' => 'required|string|max:500',
            'metadata' => 'nullable|array',
        ]);

        try {
            $transaction = $this->loyaltyService->adjustPoints(
                $validated['membership_id'],
                $validated['points'],
                $validated['reason'],
                auth()->id(),
                $validated['metadata'] ?? []
            );

            return ApiResponse::item([
                'transaction' => $transaction->load('membership'),
                'message' => 'Points adjusted successfully',
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Get member point history
     */
    public function pointHistory(Request $request, string $membershipId): JsonResponse
    {
        $query = LoyaltyTransaction::where('membership_id', $membershipId)
            ->latest();

        // Filter by transaction type
        if ($type = $request->get('type')) {
            $query->where('type', $type);
        }

        // Filter by date range
        if ($from = $request->get('from')) {
            $query->where('processed_at', '>=', $from);
        }

        if ($to = $request->get('to')) {
            $query->where('processed_at', '<=', $to);
        }

        $transactions = $query->paginate($request->get('per_page', 50));

        return ApiResponse::item([
            'transactions' => $transactions->items(),
            'pagination' => [
                'current_page' => $transactions->currentPage(),
                'last_page' => $transactions->lastPage(),
                'per_page' => $transactions->perPage(),
                'total' => $transactions->total(),
            ]
        ]);
    }

    // ── Rewards Management ────────────────────────────────────────────────────

    /**
     * List rewards
     */
    public function rewards(Request $request): JsonResponse
    {
        $query = LoyaltyReward::query()->latest();

        // Filter by program
        if ($programId = $request->get('program_id')) {
            $query->where('loyalty_program_id', $programId);
        }

        // Filter by type
        if ($type = $request->get('type')) {
            $query->where('type', $type);
        }

        // Filter by availability
        if ($request->get('available_only') === 'true') {
            $query->where('is_active', true)
                  ->where('available_from', '<=', now())
                  ->where(function ($q) {
                      $q->whereNull('available_until')
                        ->orWhere('available_until', '>', now());
                  });
        }

        $rewards = $query->paginate($request->get('per_page', 20));

        return ApiResponse::item([
            'rewards' => $rewards->items(),
            'pagination' => [
                'current_page' => $rewards->currentPage(),
                'last_page' => $rewards->lastPage(),
                'per_page' => $rewards->perPage(),
                'total' => $rewards->total(),
            ]
        ]);
    }

    /**
     * Create reward
     */
    public function createReward(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'program_id' => 'nullable|exists:loyalty_programs,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'type' => ['required', 'string', Rule::in(['product', 'discount', 'cashback', 'service', 'experience'])],
            'points_required' => 'required|integer|min:1',
            'monetary_value' => 'nullable|numeric|min:0',
            'currency' => 'nullable|string|size:3',
            'stock_quantity' => 'nullable|integer|min:0',
            'per_customer_limit' => 'nullable|integer|min:1',
            'eligibility_rules' => 'nullable|array',
            'configuration' => 'nullable|array',
            'is_active' => 'boolean',
            'available_from' => 'nullable|date',
            'available_until' => 'nullable|date|after:available_from',
        ]);

        $reward = $this->loyaltyService->createReward($validated);

        return ApiResponse::item([
            'reward' => $reward,
            'message' => 'Reward created successfully',
        ], 201);
    }

    /**
     * Redeem reward
     */
    public function redeemReward(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'membership_id' => 'required|exists:loyalty_memberships,id',
            'reward_id' => 'required|exists:loyalty_rewards,id',
        ]);

        try {
            $result = $this->loyaltyService->redeemReward(
                $validated['membership_id'],
                $validated['reward_id']
            );

            return ApiResponse::item([
                'transaction' => $result['transaction'],
                'reward' => $result['reward'],
                'fulfillment' => $result['fulfillment'],
                'message' => 'Reward redeemed successfully',
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    // ── Analytics ─────────────────────────────────────────────────────────────

    /**
     * Get loyalty analytics
     */
    public function analytics(Request $request): JsonResponse
    {
        $programId = $request->get('program_id');

        $analytics = [
            'overview' => $this->getLoyaltyOverview($programId),
            'engagement' => $this->getEngagementMetrics($programId),
            'redemption_patterns' => $this->getRedemptionPatterns($programId),
            'tier_distribution' => $this->getTierDistribution($programId),
        ];

        return ApiResponse::item(['analytics' => $analytics]);
    }

    // ── Helper Methods ────────────────────────────────────────────────────────

    /**
     * Get program statistics
     */
    private function getProgramStatistics(LoyaltyProgram $program): array
    {
        $memberships = $program->memberships();
        $transactions = LoyaltyTransaction::whereIn('membership_id', $memberships->pluck('id'));

        return [
            'total_members' => $memberships->count(),
            'active_members' => $memberships->where('status', 'active')->count(),
            'points_issued' => $transactions->where('type', 'earned')->sum('points'),
            'points_redeemed' => abs($transactions->where('type', 'redeemed')->sum('points')),
            'average_balance' => $memberships->avg('points_balance'),
            'top_tier_members' => $memberships->whereNotNull('current_tier')->count(),
        ];
    }

    /**
     * Get membership statistics
     */
    private function getMembershipStatistics(LoyaltyMembership $membership): array
    {
        $transactions = $membership->transactions();

        return [
            'total_transactions' => $transactions->count(),
            'points_earned_this_month' => $transactions->where('type', 'earned')
                ->where('processed_at', '>=', now()->startOfMonth())
                ->sum('points'),
            'points_redeemed_this_month' => abs($transactions->where('type', 'redeemed')
                ->where('processed_at', '>=', now()->startOfMonth())
                ->sum('points')),
            'last_activity' => $transactions->latest()->first()?->processed_at,
            'favorite_reward_types' => [], // Implementation would analyze transaction patterns
        ];
    }

    /**
     * Get loyalty overview metrics
     */
    private function getLoyaltyOverview(?string $programId): array
    {
        $query = LoyaltyMembership::query();
        
        if ($programId) {
            $query->where('loyalty_program_id', $programId);
        }

        return [
            'total_members' => $query->count(),
            'active_members' => $query->where('status', 'active')->count(),
            'total_points_balance' => $query->sum('points_balance'),
            'average_lifetime_value' => $query->avg('points_lifetime_earned'),
        ];
    }

    /**
     * Get engagement metrics
     */
    private function getEngagementMetrics(?string $programId): array
    {
        // Implementation would return engagement analysis
        return [
            'monthly_active_members' => 0,
            'average_transactions_per_member' => 0,
            'retention_rate' => 0,
            'engagement_trends' => [],
        ];
    }

    /**
     * Get redemption patterns
     */
    private function getRedemptionPatterns(?string $programId): array
    {
        // Implementation would return redemption analysis
        return [
            'top_reward_types' => [],
            'average_redemption_value' => 0,
            'redemption_frequency' => [],
            'seasonal_patterns' => [],
        ];
    }

    /**
     * Get tier distribution
     */
    private function getTierDistribution(?string $programId): array
    {
        $query = LoyaltyMembership::query();
        
        if ($programId) {
            $query->where('loyalty_program_id', $programId);
        }

        return $query->selectRaw('current_tier, COUNT(*) as count')
            ->groupBy('current_tier')
            ->get()
            ->pluck('count', 'current_tier')
            ->toArray();
    }
}