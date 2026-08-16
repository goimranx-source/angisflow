<?php

declare(strict_types=1);

namespace App\Http\Api\V1\Public;

use App\Http\Api\Endpoint;
use App\Http\Api\ApiResponse;
use App\Domain\PublicApi\PublicApiService;
use App\Models\ApiKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public API management endpoints
 *
 * Provides endpoints for managing API keys, webhooks, integrations,
 * and monitoring API usage. These endpoints are used by account
 * administrators to configure their public API access.
 */
class PublicApiEndpoint extends Endpoint
{
    public function __construct(
        private PublicApiService $publicApiService
    ) {}

    /**
     * List API keys
     */
    public function listApiKeys(Request $request): JsonResponse
    {
        $this->validate($request, [
            'environment' => 'sometimes|in:production,sandbox,development',
            'business_id' => 'sometimes|integer|exists:businesses,id',
        ]);

        $query = ApiKey::where('account_id', auth()->user()->account_id);

        if ($request->filled('environment')) {
            $query->environment($request->environment);
        }

        if ($request->filled('business_id')) {
            $query->where('business_id', $request->business_id);
        }

        $apiKeys = $query->with(['creator', 'business'])
            ->orderBy('created_at', 'desc')
            ->get();

        return ApiResponse::collection($apiKeys->map(function ($apiKey) {
            return [
                'id' => $apiKey->public_id,
                'name' => $apiKey->name,
                'key_id' => $apiKey->key_id,
                'key_prefix' => $apiKey->key_prefix,
                'environment' => $apiKey->environment,
                'environment_label' => $apiKey->getEnvironmentLabel(),
                'scopes' => $apiKey->getScopeLabels(),
                'is_active' => $apiKey->is_active,
                'is_healthy' => $apiKey->isHealthy(),
                'expires_at' => $apiKey->expires_at?->toIso8601String(),
                'last_used_at' => $apiKey->last_used_at?->toIso8601String(),
                'last_used_from_ip' => $apiKey->last_used_from_ip,
                'created_at' => $apiKey->created_at->toIso8601String(),
                'created_by' => $apiKey->creator?->name,
                'business' => $apiKey->business ? [
                    'id' => $apiKey->business->public_id,
                    'name' => $apiKey->business->name,
                ] : null,
                'usage_summary' => $apiKey->getUsageSummary(),
            ];
        }));
    }

    /**
     * Create API key
     */
    public function createApiKey(Request $request): JsonResponse
    {
        $this->validate($request, [
            'name' => 'required|string|max:255',
            'scopes' => 'required|array|min:1',
            'scopes.*' => 'string|in:' . implode(',', array_keys(ApiKey::SCOPES)),
            'environment' => 'required|in:production,sandbox,development',
            'business_id' => 'nullable|integer|exists:businesses,id',
            'description' => 'nullable|string|max:1000',
            'expires_at' => 'nullable|date|after:now',
            'allowed_ips' => 'nullable|array',
            'allowed_ips.*' => 'ip',
            'rate_limits' => 'nullable|array',
            'rate_limits.requests_per_minute' => 'nullable|integer|min:1|max:1000',
            'rate_limits.requests_per_hour' => 'nullable|integer|min:1|max:10000',
            'rate_limits.requests_per_day' => 'nullable|integer|min:1|max:100000',
            'webhook_url' => 'nullable|url',
        ]);

        $result = $this->publicApiService->createApiKey(
            $request->name,
            $request->scopes,
            $request->business_id,
            $request->only([
                'environment', 'description', 'expires_at', 'allowed_ips',
                'rate_limits', 'webhook_url'
            ])
        );

        return ApiResponse::item([
            'api_key' => [
                'id' => $result['api_key']->public_id,
                'name' => $result['api_key']->name,
                'key_id' => $result['api_key']->key_id,
                'environment' => $result['api_key']->environment,
                'scopes' => $result['api_key']->getScopeLabels(),
                'created_at' => $result['api_key']->created_at->toIso8601String(),
            ],
            'secret_key' => $result['secret_key'], // Only shown once
            'warning' => 'Store the secret key securely. It will not be shown again.',
        ]);
    }

    /**
     * Show API key details
     */
    public function showApiKey(string $publicId): JsonResponse
    {
        $apiKey = ApiKey::where('account_id', auth()->user()->account_id)
            ->where('public_id', $publicId)
            ->with(['creator', 'business', 'usageStats'])
            ->firstOrFail();

        return ApiResponse::item([
            'id' => $apiKey->public_id,
            'name' => $apiKey->name,
            'key_id' => $apiKey->key_id,
            'key_prefix' => $apiKey->key_prefix,
            'environment' => $apiKey->environment,
            'environment_label' => $apiKey->getEnvironmentLabel(),
            'scopes' => $apiKey->getScopeLabels(),
            'is_active' => $apiKey->is_active,
            'is_healthy' => $apiKey->isHealthy(),
            'description' => $apiKey->description,
            'allowed_ips' => $apiKey->allowed_ips,
            'expires_at' => $apiKey->expires_at?->toIso8601String(),
            'last_used_at' => $apiKey->last_used_at?->toIso8601String(),
            'last_used_from_ip' => $apiKey->last_used_from_ip,
            'created_at' => $apiKey->created_at->toIso8601String(),
            'created_by' => $apiKey->creator?->name,
            'business' => $apiKey->business ? [
                'id' => $apiKey->business->public_id,
                'name' => $apiKey->business->name,
            ] : null,
            'usage_summary' => $apiKey->getUsageSummary(),
        ]);
    }

    /**
     * Update API key
     */
    public function updateApiKey(Request $request, string $publicId): JsonResponse
    {
        $this->validate($request, [
            'name' => 'sometimes|string|max:255',
            'scopes' => 'sometimes|array|min:1',
            'scopes.*' => 'string|in:' . implode(',', array_keys(ApiKey::SCOPES)),
            'description' => 'nullable|string|max:1000',
            'allowed_ips' => 'nullable|array',
            'allowed_ips.*' => 'ip',
            'is_active' => 'sometimes|boolean',
        ]);

        $apiKey = ApiKey::where('account_id', auth()->user()->account_id)
            ->where('public_id', $publicId)
            ->firstOrFail();

        $apiKey->update($request->only([
            'name', 'scopes', 'description', 'allowed_ips', 'is_active'
        ]));

        return ApiResponse::item([
            'id' => $apiKey->public_id,
            'name' => $apiKey->name,
            'scopes' => $apiKey->getScopeLabels(),
            'is_active' => $apiKey->is_active,
            'updated_at' => $apiKey->updated_at->toIso8601String(),
        ]);
    }

    /**
     * Revoke API key
     */
    public function revokeApiKey(Request $request, string $publicId): JsonResponse
    {
        $this->validate($request, [
            'reason' => 'nullable|string|max:255',
        ]);

        $apiKey = ApiKey::where('account_id', auth()->user()->account_id)
            ->where('public_id', $publicId)
            ->firstOrFail();

        $this->publicApiService->revokeApiKey($apiKey->id, $request->reason);

        return ApiResponse::item([
            'message' => 'API key revoked successfully',
            'revoked_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Get API usage analytics
     */
    public function getUsageAnalytics(Request $request): JsonResponse
    {
        $this->validate($request, [
            'api_key_id' => 'nullable|string|exists:api_keys,public_id',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'period' => 'sometimes|in:hour,day,week,month',
        ]);

        $apiKeyId = null;
        if ($request->filled('api_key_id')) {
            $apiKey = ApiKey::where('account_id', auth()->user()->account_id)
                ->where('public_id', $request->api_key_id)
                ->firstOrFail();
            $apiKeyId = $apiKey->id;
        }

        $analytics = $this->publicApiService->getUsageAnalytics(
            $apiKeyId,
            $request->start_date ? \Carbon\Carbon::parse($request->start_date) : null,
            $request->end_date ? \Carbon\Carbon::parse($request->end_date) : null
        );

        return ApiResponse::item($analytics);
    }

    /**
     * Get API health status
     */
    public function getHealthStatus(): JsonResponse
    {
        $healthStatus = $this->publicApiService->getApiHealthStatus();

        return ApiResponse::item($healthStatus);
    }

    /**
     * Get available scopes
     */
    public function getScopes(): JsonResponse
    {
        $scopes = collect(ApiKey::SCOPES)->map(function ($label, $scope) {
            return [
                'scope' => $scope,
                'label' => $label,
                'category' => explode(':', $scope)[0],
                'action' => explode(':', $scope)[1] ?? null,
            ];
        })->groupBy('category');

        return ApiResponse::item([
            'scopes' => $scopes,
            'categories' => $scopes->keys(),
        ]);
    }

    /**
     * Test API key
     */
    public function testApiKey(Request $request): JsonResponse
    {
        $this->validate($request, [
            'key_id' => 'required|string',
            'secret_key' => 'required|string',
        ]);

        // Create a mock request to test authentication
        $mockRequest = new Request();
        $mockRequest->headers->set('Authorization', "Bearer {$request->key_id} {$request->secret_key}");
        $mockRequest->server->set('REMOTE_ADDR', $request->ip());

        $apiKey = $this->publicApiService->authenticateRequest($mockRequest);

        if (!$apiKey) {
            return response()->json([
                'valid' => false,
                'message' => 'Invalid API key or secret',
            ], 401);
        }

        return ApiResponse::item([
            'valid' => true,
            'api_key' => [
                'id' => $apiKey->public_id,
                'name' => $apiKey->name,
                'environment' => $apiKey->environment,
                'scopes' => $apiKey->getScopeLabels(),
                'is_healthy' => $apiKey->isHealthy(),
            ],
            'message' => 'API key is valid and working',
        ]);
    }
}