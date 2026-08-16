<?php

namespace App\Models;

use App\Domain\Tenancy\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

/**
 * API Usage Statistics for billing and analytics
 *
 * Aggregates API usage data by time periods for billing calculations,
 * usage analytics, and performance monitoring. Supports hourly, daily,
 * and monthly aggregations.
 */
class ApiUsageStats extends Model
{
    use HasFactory, BelongsToAccount;

    protected $fillable = [
        'account_id',
        'api_key_id',
        'period_type',
        'period_date',
        'period_hour',
        'total_requests',
        'successful_requests',
        'failed_requests',
        'rate_limited_requests',
        'avg_response_time_ms',
        'max_response_time_ms',
        'min_response_time_ms',
        'total_response_size_bytes',
        'total_request_size_bytes',
        'total_db_queries',
        'total_db_time_ms',
        'total_cache_hits',
        'total_cache_misses',
        'auth_errors',
        'validation_errors',
        'server_errors',
        'not_found_errors',
    ];

    protected $casts = [
        'period_date' => 'date',
        'total_response_size_bytes' => 'integer',
        'total_request_size_bytes' => 'integer',
    ];

    public const PERIOD_TYPES = [
        'hour' => 'Hourly',
        'day' => 'Daily',
        'month' => 'Monthly',
    ];

    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(ApiKey::class);
    }

    /**
     * Calculate success rate
     */
    public function getSuccessRate(): float
    {
        if ($this->total_requests === 0) {
            return 0;
        }
        
        return ($this->successful_requests / $this->total_requests) * 100;
    }

    /**
     * Calculate error rate
     */
    public function getErrorRate(): float
    {
        if ($this->total_requests === 0) {
            return 0;
        }
        
        return ($this->failed_requests / $this->total_requests) * 100;
    }

    /**
     * Calculate rate limit rate
     */
    public function getRateLimitRate(): float
    {
        if ($this->total_requests === 0) {
            return 0;
        }
        
        return ($this->rate_limited_requests / $this->total_requests) * 100;
    }

    /**
     * Get formatted data transfer
     */
    public function getFormattedDataTransfer(): array
    {
        return [
            'request_size' => $this->formatBytes($this->total_request_size_bytes),
            'response_size' => $this->formatBytes($this->total_response_size_bytes),
            'total_size' => $this->formatBytes($this->total_request_size_bytes + $this->total_response_size_bytes),
        ];
    }

    /**
     * Format bytes to human readable
     */
    protected function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * Get cache hit rate
     */
    public function getCacheHitRate(): float
    {
        $totalCacheRequests = $this->total_cache_hits + $this->total_cache_misses;
        
        if ($totalCacheRequests === 0) {
            return 0;
        }
        
        return ($this->total_cache_hits / $totalCacheRequests) * 100;
    }

    /**
     * Get error breakdown
     */
    public function getErrorBreakdown(): array
    {
        $totalErrors = $this->auth_errors + $this->validation_errors + 
                      $this->server_errors + $this->not_found_errors;
        
        if ($totalErrors === 0) {
            return [];
        }
        
        return [
            'auth_errors' => [
                'count' => $this->auth_errors,
                'percentage' => round(($this->auth_errors / $totalErrors) * 100, 1),
            ],
            'validation_errors' => [
                'count' => $this->validation_errors,
                'percentage' => round(($this->validation_errors / $totalErrors) * 100, 1),
            ],
            'server_errors' => [
                'count' => $this->server_errors,
                'percentage' => round(($this->server_errors / $totalErrors) * 100, 1),
            ],
            'not_found_errors' => [
                'count' => $this->not_found_errors,
                'percentage' => round(($this->not_found_errors / $totalErrors) * 100, 1),
            ],
        ];
    }

    /**
     * Update stats with new request data
     */
    public function updateWithRequest(array $requestData): void
    {
        $updates = [
            'total_requests' => $this->total_requests + 1,
        ];
        
        // Update success/failure counts
        if ($requestData['is_successful']) {
            $updates['successful_requests'] = $this->successful_requests + 1;
        } else {
            $updates['failed_requests'] = $this->failed_requests + 1;
        }
        
        // Update rate limiting
        if ($requestData['rate_limited'] ?? false) {
            $updates['rate_limited_requests'] = $this->rate_limited_requests + 1;
        }
        
        // Update response time metrics
        if (isset($requestData['response_time_ms'])) {
            $responseTime = $requestData['response_time_ms'];
            
            // Calculate new average
            $totalTime = ($this->avg_response_time_ms * $this->total_requests) + $responseTime;
            $updates['avg_response_time_ms'] = round($totalTime / ($this->total_requests + 1));
            
            // Update min/max
            $updates['max_response_time_ms'] = max($this->max_response_time_ms ?? 0, $responseTime);
            $updates['min_response_time_ms'] = $this->min_response_time_ms === null ? 
                $responseTime : min($this->min_response_time_ms, $responseTime);
        }
        
        // Update data transfer
        if (isset($requestData['request_size'])) {
            $updates['total_request_size_bytes'] = $this->total_request_size_bytes + $requestData['request_size'];
        }
        
        if (isset($requestData['response_size'])) {
            $updates['total_response_size_bytes'] = $this->total_response_size_bytes + $requestData['response_size'];
        }
        
        // Update database metrics
        if (isset($requestData['db_queries'])) {
            $updates['total_db_queries'] = $this->total_db_queries + $requestData['db_queries'];
        }
        
        if (isset($requestData['db_time_ms'])) {
            $updates['total_db_time_ms'] = $this->total_db_time_ms + $requestData['db_time_ms'];
        }
        
        // Update cache metrics
        if (isset($requestData['cache_hits'])) {
            $updates['total_cache_hits'] = $this->total_cache_hits + $requestData['cache_hits'];
        }
        
        if (isset($requestData['cache_misses'])) {
            $updates['total_cache_misses'] = $this->total_cache_misses + $requestData['cache_misses'];
        }
        
        // Update error counts
        if (isset($requestData['error_type'])) {
            $errorType = $requestData['error_type'];
            $errorField = match($errorType) {
                'authentication', 'authorization' => 'auth_errors',
                'validation' => 'validation_errors',
                'server_error' => 'server_errors',
                'not_found' => 'not_found_errors',
                default => null,
            };
            
            if ($errorField) {
                $updates[$errorField] = $this->$errorField + 1;
            }
        }
        
        $this->update($updates);
    }

    /**
     * Create or update stats for period
     */
    public static function recordUsage(
        int $accountId,
        ?int $apiKeyId,
        string $periodType,
        Carbon $date,
        array $requestData
    ): self {
        $attributes = [
            'account_id' => $accountId,
            'api_key_id' => $apiKeyId,
            'period_type' => $periodType,
            'period_date' => $date->toDateString(),
        ];
        
        // Add hour for hourly stats
        if ($periodType === 'hour') {
            $attributes['period_hour'] = $date->hour;
        }
        
        $stats = self::firstOrCreate($attributes, [
            'total_requests' => 0,
            'successful_requests' => 0,
            'failed_requests' => 0,
            'rate_limited_requests' => 0,
            'total_response_size_bytes' => 0,
            'total_request_size_bytes' => 0,
            'total_db_queries' => 0,
            'total_db_time_ms' => 0,
            'total_cache_hits' => 0,
            'total_cache_misses' => 0,
            'auth_errors' => 0,
            'validation_errors' => 0,
            'server_errors' => 0,
            'not_found_errors' => 0,
        ]);
        
        $stats->updateWithRequest($requestData);
        
        return $stats;
    }

    /**
     * Get period type label
     */
    public function getPeriodTypeLabel(): string
    {
        return self::PERIOD_TYPES[$this->period_type] ?? ucfirst($this->period_type);
    }

    /**
     * Get formatted period
     */
    public function getFormattedPeriod(): string
    {
        return match($this->period_type) {
            'hour' => $this->period_date->format('M j, Y') . ' at ' . $this->period_hour . ':00',
            'day' => $this->period_date->format('M j, Y'),
            'month' => $this->period_date->format('F Y'),
            default => $this->period_date->format('M j, Y'),
        };
    }

    /**
     * Scope for specific period type
     */
    public function scopePeriodType($query, string $periodType)
    {
        return $query->where('period_type', $periodType);
    }

    /**
     * Scope for date range
     */
    public function scopeDateRange($query, Carbon $start, Carbon $end)
    {
        return $query->whereBetween('period_date', [$start->toDateString(), $end->toDateString()]);
    }

    /**
     * Scope for specific API key
     */
    public function scopeForApiKey($query, int $apiKeyId)
    {
        return $query->where('api_key_id', $apiKeyId);
    }

    /**
     * Scope for account totals (all API keys)
     */
    public function scopeAccountTotals($query)
    {
        return $query->whereNull('api_key_id');
    }

    /**
     * Clean up old statistics
     */
    public static function cleanup(): int
    {
        $deleted = 0;
        
        // Remove hourly stats older than 30 days
        $deleted += self::where('period_type', 'hour')
            ->where('period_date', '<', now()->subDays(30))
            ->delete();
        
        // Remove daily stats older than 1 year
        $deleted += self::where('period_type', 'day')
            ->where('period_date', '<', now()->subYear())
            ->delete();
        
        // Keep monthly stats indefinitely for historical analysis
        
        return $deleted;
    }
}