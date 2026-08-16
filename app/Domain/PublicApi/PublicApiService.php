<?php

declare(strict_types=1);

namespace App\Domain\PublicApi;

use App\Domain\Tenancy\TenantContext;
use App\Models\ApiKey;
use App\Models\ApiRequestLog;
use App\Models\ApiRateLimit;
use App\Models\ApiWebhook;
use App\Models\ApiWebhookDelivery;
use App\Models\ApiIntegration;
use App\Models\ApiUsageStats;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Carbon\Carbon;

/**
 * Public API Management Service
 *
 * Provides comprehensive management of the public API system including
 * API key management, rate limiting, webhook delivery, usage tracking,
 * and integration monitoring. Serves as the central orchestrator for
 * all public API operations.
 */
class PublicApiService
{
    public function __construct(
        private TenantContext $tenantContext
    ) {}

    // ── API Key Management ──────────────────────────────────────────────────

    /**
     * Create a new API key
     */
    public function createApiKey(
        string $name,
        array $scopes,
        ?int $businessId = null,
        array $options = []
    ): array {
        $environment = $options['environment'] ?? 'production';
        $keyData = ApiKey::generateKeyPair($environment);
        
        $apiKey = ApiKey::create([
            'account_id' => $this->tenantContext->account()->id,
            'business_id' => $businessId,
            'name' => $name,
            'key_id' => $keyData['key_id'],
            'key_hash' => $keyData['key_hash'],
            'key_prefix' => $keyData['key_prefix'],
            'environment' => $environment,
            'scopes' => $scopes,
            'rate_limits' => $options['rate_limits'] ?? null,
            'allowed_ips' => $options['allowed_ips'] ?? null,
            'webhook_url' => $options['webhook_url'] ?? null,
            'webhook_secret' => isset($options['webhook_url']) ? ApiWebhook::generateSecret() : null,
            'expires_at' => $options['expires_at'] ?? null,
            'created_by' => auth()->id() ?? 1, // Default to user 1 for API context
            'description' => $options['description'] ?? null,
            'metadata' => $options['metadata'] ?? null,
        ]);
        
        // Create default rate limits
        ApiRateLimit::forApiKey($apiKey);
        
        return [
            'api_key' => $apiKey,
            'secret_key' => $keyData['secret_key'], // Only returned once
        ];
    }

    /**
     * Authenticate API request using API key
     */
    public function authenticateRequest(Request $request): ?ApiKey
    {
        $keyId = $this->extractApiKeyId($request);
        $secretKey = $this->extractSecretKey($request);
        
        if (!$keyId || !$secretKey) {
            return null;
        }
        
        $apiKey = ApiKey::where('key_id', $keyId)
            ->active()
            ->first();
        
        if (!$apiKey || !$apiKey->verifySecretKey($secretKey)) {
            return null;
        }
        
        // Check IP restrictions
        if (!$apiKey->canAccessFromIp($request->ip())) {
            return null;
        }
        
        // Update last used tracking
        $apiKey->updateLastUsed($request->ip());
        
        return $apiKey;
    }

    /**
     * Extract API key ID from request
     */
    private function extractApiKeyId(Request $request): ?string
    {
        // Check Authorization header: Bearer pk_live_...
        if ($auth = $request->header('Authorization')) {
            if (preg_match('/Bearer\s+(pk_\w+_\w+)/', $auth, $matches)) {
                return $matches[1];
            }
        }
        
        // Check X-API-Key header
        if ($apiKey = $request->header('X-API-Key')) {
            if (str_starts_with($apiKey, 'pk_')) {
                return $apiKey;
            }
        }
        
        return null;
    }

    /**
     * Extract secret key from request
     */
    private function extractSecretKey(Request $request): ?string
    {
        // Check Authorization header: Bearer pk_live_... sk_live_...
        if ($auth = $request->header('Authorization')) {
            if (preg_match('/Bearer\s+pk_\w+_\w+\s+(sk_\w+_\w+)/', $auth, $matches)) {
                return $matches[1];
            }
        }
        
        // Check X-API-Secret header
        if ($secretKey = $request->header('X-API-Secret')) {
            if (str_starts_with($secretKey, 'sk_')) {
                return $secretKey;
            }
        }
        
        return null;
    }

    /**
     * Revoke API key
     */
    public function revokeApiKey(int $apiKeyId, ?string $reason = null): bool
    {
        $apiKey = ApiKey::findOrFail($apiKeyId);
        
        $apiKey->update([
            'is_active' => false,
            'metadata' => array_merge($apiKey->metadata ?? [], [
                'revoked_at' => now()->toIso8601String(),
                'revoked_by' => auth()->id(),
                'revocation_reason' => $reason,
            ]),
        ]);
        
        return true;
    }

    // ── Rate Limiting ───────────────────────────────────────────────────────

    /**
     * Check if request is allowed under rate limits
     */
    public function checkRateLimit(ApiKey $apiKey, Request $request): array
    {
        // Check API key specific limits
        $keyLimit = ApiRateLimit::forApiKey($apiKey);
        if (!$keyLimit->isRequestAllowed()) {
            return [
                'allowed' => false,
                'limit_type' => 'api_key',
                'remaining' => $keyLimit->getRemainingRequests(),
                'reset_times' => $keyLimit->getSecondsUntilReset(),
                'retry_after' => $this->getRetryAfterSeconds($keyLimit),
            ];
        }
        
        // Check account limits
        $accountLimit = ApiRateLimit::forAccount($apiKey->account_id);
        if (!$accountLimit->isRequestAllowed()) {
            return [
                'allowed' => false,
                'limit_type' => 'account',
                'remaining' => $accountLimit->getRemainingRequests(),
                'reset_times' => $accountLimit->getSecondsUntilReset(),
                'retry_after' => $this->getRetryAfterSeconds($accountLimit),
            ];
        }
        
        // Check IP limits
        $ipLimit = ApiRateLimit::forIp($request->ip(), $apiKey->account_id);
        if (!$ipLimit->isRequestAllowed()) {
            return [
                'allowed' => false,
                'limit_type' => 'ip',
                'remaining' => $ipLimit->getRemainingRequests(),
                'reset_times' => $ipLimit->getSecondsUntilReset(),
                'retry_after' => $this->getRetryAfterSeconds($ipLimit),
            ];
        }
        
        return [
            'allowed' => true,
            'remaining' => [
                'api_key' => $keyLimit->getRemainingRequests(),
                'account' => $accountLimit->getRemainingRequests(),
                'ip' => $ipLimit->getRemainingRequests(),
            ],
        ];
    }

    /**
     * Record request against rate limits
     */
    public function recordRequest(ApiKey $apiKey, Request $request): void
    {
        ApiRateLimit::forApiKey($apiKey)->recordRequest();
        ApiRateLimit::forAccount($apiKey->account_id)->recordRequest();
        ApiRateLimit::forIp($request->ip(), $apiKey->account_id)->recordRequest();
    }

    /**
     * Get retry after seconds for rate limit
     */
    private function getRetryAfterSeconds(ApiRateLimit $rateLimit): int
    {
        $resetTimes = $rateLimit->getSecondsUntilReset();
        return min(array_filter($resetTimes, fn($time) => $time > 0)) ?: 60;
    }

    // ── Request Logging ─────────────────────────────────────────────────────

    /**
     * Log API request
     */
    public function logRequest(
        Request $request,
        array $response,
        ?ApiKey $apiKey = null,
        array $metrics = []
    ): ApiRequestLog {
        $requestData = [
            'id' => $request->header('X-Request-ID') ?? \Illuminate\Support\Str::uuid(),
            'method' => $request->method(),
            'endpoint' => $request->path(),
            'version' => $request->route()?->parameter('version') ?? 'v1',
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'headers' => $this->sanitizeHeaders($request->headers->all()),
            'body' => $request->json()?->all(),
            'query' => $request->query(),
        ];
        
        $responseData = [
            'status' => $response['status'],
            'headers' => $response['headers'] ?? [],
            'body' => $response['body'] ?? null,
            'size' => $response['size'] ?? 0,
            'duration_ms' => $response['duration_ms'],
            'db_queries' => $metrics['db_queries'] ?? 0,
            'db_time_ms' => $metrics['db_time_ms'] ?? 0,
            'cache_hits' => $metrics['cache_hits'] ?? 0,
            'cache_misses' => $metrics['cache_misses'] ?? 0,
            'rate_limited' => $response['rate_limited'] ?? false,
            'rate_limit_key' => $response['rate_limit_key'] ?? null,
            'remaining_requests' => $response['remaining_requests'] ?? null,
            'error_type' => $response['error_type'] ?? null,
            'error_message' => $response['error_message'] ?? null,
            'error_trace' => $response['error_trace'] ?? null,
        ];
        
        return ApiRequestLog::createFromRequest($requestData, $responseData, $apiKey);
    }

    /**
     * Sanitize headers for logging
     */
    private function sanitizeHeaders(array $headers): array
    {
        $sensitiveHeaders = ['authorization', 'x-api-key', 'x-api-secret', 'cookie'];
        
        foreach ($headers as $key => $value) {
            if (in_array(strtolower($key), $sensitiveHeaders)) {
                $headers[$key] = ['[SANITIZED]'];
            }
        }
        
        return $headers;
    }

    // ── Usage Statistics ────────────────────────────────────────────────────

    /**
     * Record usage statistics
     */
    public function recordUsage(ApiRequestLog $requestLog): void
    {
        $requestData = [
            'is_successful' => $requestLog->isSuccessful(),
            'rate_limited' => $requestLog->rate_limited,
            'response_time_ms' => $requestLog->duration_ms,
            'request_size' => strlen($requestLog->request_body ?? ''),
            'response_size' => $requestLog->response_size,
            'db_queries' => $requestLog->db_queries_count,
            'db_time_ms' => $requestLog->db_query_time_ms,
            'cache_hits' => $requestLog->cache_hits,
            'cache_misses' => $requestLog->cache_misses,
            'error_type' => $requestLog->error_type,
        ];
        
        $now = now();
        
        // Record hourly stats
        ApiUsageStats::recordUsage(
            $requestLog->account_id,
            $requestLog->api_key_id,
            'hour',
            $now,
            $requestData
        );
        
        // Record daily stats
        ApiUsageStats::recordUsage(
            $requestLog->account_id,
            $requestLog->api_key_id,
            'day',
            $now,
            $requestData
        );
        
        // Record monthly stats
        ApiUsageStats::recordUsage(
            $requestLog->account_id,
            $requestLog->api_key_id,
            'month',
            $now->startOfMonth(),
            $requestData
        );
    }

    // ── Webhook Management ──────────────────────────────────────────────────

    /**
     * Create webhook
     */
    public function createWebhook(
        string $name,
        string $url,
        array $events,
        ?int $businessId = null,
        ?int $apiKeyId = null,
        array $options = []
    ): ApiWebhook {
        return ApiWebhook::create([
            'account_id' => $this->tenantContext->account()->id,
            'business_id' => $businessId,
            'api_key_id' => $apiKeyId,
            'name' => $name,
            'url' => $url,
            'secret' => ApiWebhook::generateSecret(),
            'events' => $events,
            'filters' => $options['filters'] ?? null,
            'content_type' => $options['content_type'] ?? 'application/json',
            'custom_headers' => $options['custom_headers'] ?? null,
            'timeout_seconds' => $options['timeout_seconds'] ?? 30,
            'retry_attempts' => $options['retry_attempts'] ?? 3,
            'retry_intervals' => $options['retry_intervals'] ?? null,
            'is_active' => $options['is_active'] ?? true,
        ]);
    }

    /**
     * Trigger webhook for event
     */
    public function triggerWebhook(
        string $eventType,
        string $eventId,
        array $eventData,
        ?int $businessId = null
    ): Collection {
        $query = ApiWebhook::where('account_id', $this->tenantContext->account()->id)
            ->active()
            ->healthy()
            ->subscribedToEvent($eventType);
        
        if ($businessId) {
            $query->where(function ($q) use ($businessId) {
                $q->where('business_id', $businessId)
                  ->orWhereNull('business_id');
            });
        }
        
        $webhooks = $query->get();
        $deliveries = collect();
        
        foreach ($webhooks as $webhook) {
            if ($webhook->shouldReceiveEvent($eventType, $eventData)) {
                $delivery = ApiWebhookDelivery::createForWebhook(
                    $webhook,
                    $eventType,
                    $eventId,
                    $eventData
                );
                
                // Dispatch job for asynchronous processing
                \App\Jobs\ProcessWebhookDelivery::dispatch($delivery->id);
                
                $deliveries->push($delivery);
            }
        }
        
        return $deliveries;
    }

    // ── Integration Management ──────────────────────────────────────────────

    /**
     * Create integration
     */
    public function createIntegration(
        string $name,
        string $type,
        ?string $provider = null,
        array $configuration = [],
        ?int $businessId = null
    ): ApiIntegration {
        return ApiIntegration::create([
            'account_id' => $this->tenantContext->account()->id,
            'business_id' => $businessId,
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name),
            'type' => $type,
            'provider' => $provider,
            'configuration' => $configuration,
            'status' => 'setup',
            'created_by' => auth()->id() ?? 1, // Default to user 1 for API context
            'installed_at' => now(),
        ]);
    }

    /**
     * Get integration sync status
     */
    public function getIntegrationSyncStatus(?int $businessId = null): array
    {
        $query = ApiIntegration::where('account_id', $this->tenantContext->account()->id)
            ->active();
        
        if ($businessId) {
            $query->where('business_id', $businessId);
        }
        
        $integrations = $query->get();
        
        return [
            'total_integrations' => $integrations->count(),
            'healthy_integrations' => $integrations->filter(fn($i) => $i->isHealthy())->count(),
            'due_for_sync' => $integrations->filter(fn($i) => $i->isSyncDue())->count(),
            'recent_syncs' => $integrations->filter(fn($i) => $i->last_sync_at && $i->last_sync_at->isAfter(now()->subHour()))->count(),
            'failed_syncs' => $integrations->filter(fn($i) => $i->status === 'error')->count(),
            'integrations' => $integrations->map(fn($i) => [
                'id' => $i->public_id,
                'name' => $i->name,
                'type' => $i->getTypeLabel(),
                'status' => $i->getStatusLabel(),
                'health' => $i->getHealthStatus(),
                'last_sync' => $i->last_sync_at?->diffForHumans(),
            ]),
        ];
    }

    // ── Analytics and Reporting ─────────────────────────────────────────────

    /**
     * Get API usage analytics
     */
    public function getUsageAnalytics(
        ?int $apiKeyId = null,
        ?Carbon $startDate = null,
        ?Carbon $endDate = null
    ): array {
        $startDate = $startDate ?? now()->subDays(30);
        $endDate = $endDate ?? now();
        
        $query = ApiUsageStats::where('account_id', $this->tenantContext->account()->id)
            ->periodType('day')
            ->dateRange($startDate, $endDate);
        
        if ($apiKeyId) {
            $query->forApiKey($apiKeyId);
        }
        
        $stats = $query->get();
        
        return [
            'summary' => [
                'total_requests' => $stats->sum('total_requests'),
                'successful_requests' => $stats->sum('successful_requests'),
                'failed_requests' => $stats->sum('failed_requests'),
                'rate_limited_requests' => $stats->sum('rate_limited_requests'),
                'success_rate' => $stats->sum('total_requests') > 0 
                    ? ($stats->sum('successful_requests') / $stats->sum('total_requests')) * 100 
                    : 0,
                'avg_response_time' => $stats->avg('avg_response_time_ms'),
                'data_transferred' => $stats->sum('total_request_size_bytes') + $stats->sum('total_response_size_bytes'),
            ],
            'daily_breakdown' => $stats->map(fn($stat) => [
                'date' => $stat->period_date->format('Y-m-d'),
                'requests' => $stat->total_requests,
                'success_rate' => $stat->getSuccessRate(),
                'avg_response_time' => $stat->avg_response_time_ms,
                'errors' => $stat->getErrorBreakdown(),
            ]),
        ];
    }

    /**
     * Get API health status
     */
    public function getApiHealthStatus(): array
    {
        $accountId = $this->tenantContext->account()->id;
        
        // Get recent request statistics
        $recentStats = ApiUsageStats::where('account_id', $accountId)
            ->periodType('hour')
            ->where('period_date', '>=', now()->subHours(24))
            ->get();
        
        $totalRequests = $recentStats->sum('total_requests');
        $successfulRequests = $recentStats->sum('successful_requests');
        $failedRequests = $recentStats->sum('failed_requests');
        
        // Get active API keys count
        $activeApiKeys = ApiKey::where('account_id', $accountId)->active()->count();
        
        // Get webhook health
        $totalWebhooks = ApiWebhook::where('account_id', $accountId)->active()->count();
        $healthyWebhooks = ApiWebhook::where('account_id', $accountId)->active()->healthy()->count();
        
        // Get recent errors
        $recentErrors = ApiRequestLog::where('account_id', $accountId)
            ->where('has_error', true)
            ->where('created_at', '>', now()->subHours(24))
            ->count();
        
        return [
            'overall_status' => $this->determineOverallHealth($totalRequests, $successfulRequests, $failedRequests),
            'metrics' => [
                'requests_24h' => $totalRequests,
                'success_rate_24h' => $totalRequests > 0 ? ($successfulRequests / $totalRequests) * 100 : 0,
                'error_rate_24h' => $totalRequests > 0 ? ($failedRequests / $totalRequests) * 100 : 0,
                'avg_response_time_24h' => $recentStats->avg('avg_response_time_ms'),
                'active_api_keys' => $activeApiKeys,
                'healthy_webhooks' => "{$healthyWebhooks}/{$totalWebhooks}",
                'recent_errors' => $recentErrors,
            ],
            'status_indicators' => [
                'api_keys' => $activeApiKeys > 0 ? 'healthy' : 'warning',
                'success_rate' => $this->getSuccessRateStatus($totalRequests, $successfulRequests),
                'response_time' => $this->getResponseTimeStatus($recentStats->avg('avg_response_time_ms')),
                'webhooks' => $totalWebhooks > 0 && $healthyWebhooks === $totalWebhooks ? 'healthy' : 
                             ($healthyWebhooks > 0 ? 'warning' : 'error'),
                'errors' => $recentErrors < 10 ? 'healthy' : ($recentErrors < 50 ? 'warning' : 'error'),
            ],
        ];
    }

    /**
     * Determine overall API health status
     */
    private function determineOverallHealth(int $totalRequests, int $successfulRequests, int $failedRequests): string
    {
        if ($totalRequests === 0) {
            return 'unknown';
        }
        
        $successRate = ($successfulRequests / $totalRequests) * 100;
        
        if ($successRate >= 99) return 'excellent';
        if ($successRate >= 95) return 'healthy';
        if ($successRate >= 90) return 'warning';
        
        return 'error';
    }

    /**
     * Get success rate status
     */
    private function getSuccessRateStatus(int $totalRequests, int $successfulRequests): string
    {
        if ($totalRequests === 0) return 'unknown';
        
        $rate = ($successfulRequests / $totalRequests) * 100;
        
        return $rate >= 95 ? 'healthy' : ($rate >= 90 ? 'warning' : 'error');
    }

    /**
     * Get response time status
     */
    private function getResponseTimeStatus(?float $avgResponseTime): string
    {
        if ($avgResponseTime === null) return 'unknown';
        
        return $avgResponseTime <= 500 ? 'healthy' : 
               ($avgResponseTime <= 1000 ? 'warning' : 'error');
    }

    /**
     * Clean up old data
     */
    public function cleanup(): array
    {
        $results = [
            'request_logs_cleaned' => 0,
            'webhook_deliveries_cleaned' => 0,
            'rate_limits_cleaned' => 0,
            'usage_stats_cleaned' => 0,
        ];
        
        // Clean up old request logs (keep 90 days)
        $results['request_logs_cleaned'] = ApiRequestLog::where('created_at', '<', now()->subDays(90))->delete();
        
        // Clean up old webhook deliveries (keep 30 days)
        $results['webhook_deliveries_cleaned'] = ApiWebhookDelivery::where('created_at', '<', now()->subDays(30))->delete();
        
        // Clean up unused rate limits
        $results['rate_limits_cleaned'] = ApiRateLimit::cleanup();
        
        // Clean up old usage statistics
        $results['usage_stats_cleaned'] = ApiUsageStats::cleanup();
        
        return $results;
    }
}