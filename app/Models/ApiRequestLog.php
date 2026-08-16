<?php

namespace App\Models;

use App\Domain\Tenancy\Concerns\BelongsToAccount;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use App\Domain\Shared\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

/**
 * API Request Log for comprehensive API usage tracking and monitoring
 *
 * Logs all public API requests for security monitoring, usage analytics,
 * billing calculations, and performance optimization. Provides detailed
 * request/response information while protecting sensitive data.
 */
class ApiRequestLog extends Model
{
    use HasFactory, BelongsToAccount, BelongsToBusiness, HasPublicId;

    protected $table = 'api_request_log';

    protected $fillable = [
        'account_id',
        'business_id',
        'api_key_id',
        'public_id',
        'request_id',
        'method',
        'endpoint',
        'version',
        'ip_address',
        'user_agent',
        'request_headers',
        'request_body',
        'query_params',
        'response_status',
        'response_headers',
        'response_body',
        'response_size',
        'duration_ms',
        'db_queries_count',
        'db_query_time_ms',
        'cache_hits',
        'cache_misses',
        'rate_limited',
        'rate_limit_key',
        'remaining_requests',
        'has_error',
        'error_type',
        'error_message',
        'error_trace',
        'suspicious',
        'security_flags',
    ];

    protected $casts = [
        'request_headers' => 'array',
        'response_headers' => 'array',
        'security_flags' => 'array',
        'rate_limited' => 'boolean',
        'has_error' => 'boolean',
        'suspicious' => 'boolean',
    ];

    public const ERROR_TYPES = [
        'authentication' => 'Authentication Error',
        'authorization' => 'Authorization Error',
        'validation' => 'Validation Error',
        'not_found' => 'Resource Not Found',
        'rate_limit' => 'Rate Limit Exceeded',
        'server_error' => 'Internal Server Error',
        'network' => 'Network Error',
        'timeout' => 'Request Timeout',
    ];

    public const SECURITY_FLAGS = [
        'suspicious_ip' => 'Suspicious IP Address',
        'high_frequency' => 'High Frequency Requests',
        'invalid_auth' => 'Invalid Authentication Attempts',
        'sql_injection' => 'Potential SQL Injection',
        'xss_attempt' => 'Potential XSS Attempt',
        'large_payload' => 'Unusually Large Payload',
        'banned_user_agent' => 'Banned User Agent',
        'geographic_anomaly' => 'Geographic Anomaly',
    ];

    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(ApiKey::class);
    }

    /**
     * Check if the request was successful
     */
    public function isSuccessful(): bool
    {
        return $this->response_status >= 200 && $this->response_status < 400;
    }

    /**
     * Check if the request was a client error
     */
    public function isClientError(): bool
    {
        return $this->response_status >= 400 && $this->response_status < 500;
    }

    /**
     * Check if the request was a server error
     */
    public function isServerError(): bool
    {
        return $this->response_status >= 500;
    }

    /**
     * Get performance rating based on duration
     */
    public function getPerformanceRating(): string
    {
        return match(true) {
            $this->duration_ms <= 100 => 'excellent',
            $this->duration_ms <= 500 => 'good',
            $this->duration_ms <= 1000 => 'fair',
            $this->duration_ms <= 3000 => 'poor',
            default => 'critical',
        };
    }

    /**
     * Get error type label
     */
    public function getErrorTypeLabel(): ?string
    {
        return $this->error_type ? self::ERROR_TYPES[$this->error_type] ?? ucfirst($this->error_type) : null;
    }

    /**
     * Get security flag labels
     */
    public function getSecurityFlagLabels(): array
    {
        return collect($this->security_flags ?? [])
            ->map(fn($flag) => self::SECURITY_FLAGS[$flag] ?? ucfirst(str_replace('_', ' ', $flag)))
            ->toArray();
    }

    /**
     * Sanitize request body to remove sensitive data
     */
    public function sanitizeRequestBody(array $body): array
    {
        $sensitiveFields = [
            'password', 'secret', 'token', 'key', 'api_key',
            'credit_card', 'ssn', 'social_security', 'bank_account',
            'authorization', 'x-api-key', 'bearer'
        ];

        return $this->sanitizeArray($body, $sensitiveFields);
    }

    /**
     * Sanitize response body to remove sensitive data
     */
    public function sanitizeResponseBody(array $body): array
    {
        $sensitiveFields = [
            'password', 'secret', 'token', 'key', 'api_key',
            'credit_card', 'ssn', 'social_security', 'bank_account'
        ];

        return $this->sanitizeArray($body, $sensitiveFields);
    }

    /**
     * Recursively sanitize array by removing sensitive fields
     */
    private function sanitizeArray(array $data, array $sensitiveFields): array
    {
        foreach ($data as $key => $value) {
            $keyLower = strtolower($key);
            
            if (collect($sensitiveFields)->contains(fn($field) => str_contains($keyLower, $field))) {
                $data[$key] = '[SANITIZED]';
            } elseif (is_array($value)) {
                $data[$key] = $this->sanitizeArray($value, $sensitiveFields);
            }
        }
        
        return $data;
    }

    /**
     * Create a log entry from request data
     */
    public static function createFromRequest(
        array $requestData,
        array $responseData,
        ?ApiKey $apiKey = null
    ): self {
        return self::create([
            'account_id' => $apiKey?->account_id ?? auth()->user()?->account_id,
            'business_id' => $apiKey?->business_id,
            'api_key_id' => $apiKey?->id,
            'request_id' => $requestData['id'] ?? \Illuminate\Support\Str::uuid(),
            'method' => $requestData['method'],
            'endpoint' => $requestData['endpoint'],
            'version' => $requestData['version'] ?? 'v1',
            'ip_address' => $requestData['ip'],
            'user_agent' => $requestData['user_agent'],
            'request_headers' => self::sanitizeHeaders($requestData['headers'] ?? []),
            'request_body' => isset($requestData['body']) ? json_encode($requestData['body']) : null,
            'query_params' => isset($requestData['query']) ? http_build_query($requestData['query']) : null,
            'response_status' => $responseData['status'],
            'response_headers' => $responseData['headers'] ?? [],
            'response_body' => isset($responseData['body']) ? json_encode($responseData['body']) : null,
            'response_size' => $responseData['size'] ?? 0,
            'duration_ms' => $responseData['duration_ms'],
            'db_queries_count' => $responseData['db_queries'] ?? 0,
            'db_query_time_ms' => $responseData['db_time_ms'] ?? 0,
            'cache_hits' => $responseData['cache_hits'] ?? 0,
            'cache_misses' => $responseData['cache_misses'] ?? 0,
            'rate_limited' => $responseData['rate_limited'] ?? false,
            'rate_limit_key' => $responseData['rate_limit_key'] ?? null,
            'remaining_requests' => $responseData['remaining_requests'] ?? null,
            'has_error' => $responseData['status'] >= 400,
            'error_type' => $responseData['error_type'] ?? null,
            'error_message' => $responseData['error_message'] ?? null,
            'error_trace' => $responseData['error_trace'] ?? null,
            'suspicious' => self::detectSuspiciousActivity($requestData, $responseData),
            'security_flags' => self::analyzeSecurityFlags($requestData, $responseData),
        ]);
    }

    /**
     * Sanitize headers to remove sensitive information
     */
    private static function sanitizeHeaders(array $headers): array
    {
        $sensitiveHeaders = ['authorization', 'x-api-key', 'cookie', 'x-auth-token'];
        
        foreach ($headers as $key => $value) {
            if (in_array(strtolower($key), $sensitiveHeaders)) {
                $headers[$key] = '[SANITIZED]';
            }
        }
        
        return $headers;
    }

    /**
     * Detect suspicious activity patterns
     */
    private static function detectSuspiciousActivity(array $requestData, array $responseData): bool
    {
        // High frequency from same IP
        if (self::isHighFrequencyIp($requestData['ip'])) {
            return true;
        }
        
        // Multiple auth failures
        if ($responseData['status'] === 401 && self::hasMultipleAuthFailures($requestData['ip'])) {
            return true;
        }
        
        // Suspicious patterns in request
        if (self::hasSuspiciousPatterns($requestData)) {
            return true;
        }
        
        return false;
    }

    /**
     * Analyze and flag security concerns
     */
    private static function analyzeSecurityFlags(array $requestData, array $responseData): array
    {
        $flags = [];
        
        // Check for high frequency requests
        if (self::isHighFrequencyIp($requestData['ip'])) {
            $flags[] = 'high_frequency';
        }
        
        // Check for invalid auth attempts
        if ($responseData['status'] === 401) {
            $flags[] = 'invalid_auth';
        }
        
        // Check for suspicious patterns
        if (self::hasSqlInjectionPatterns($requestData)) {
            $flags[] = 'sql_injection';
        }
        
        if (self::hasXssPatterns($requestData)) {
            $flags[] = 'xss_attempt';
        }
        
        // Check payload size
        if (isset($requestData['body']) && strlen(json_encode($requestData['body'])) > 10000) {
            $flags[] = 'large_payload';
        }
        
        return $flags;
    }

    /**
     * Check if IP has high frequency requests
     */
    private static function isHighFrequencyIp(string $ip): bool
    {
        $count = self::where('ip_address', $ip)
            ->where('created_at', '>', now()->subMinutes(5))
            ->count();
            
        return $count > 100; // More than 100 requests in 5 minutes
    }

    /**
     * Check for multiple auth failures from IP
     */
    private static function hasMultipleAuthFailures(string $ip): bool
    {
        $failures = self::where('ip_address', $ip)
            ->where('response_status', 401)
            ->where('created_at', '>', now()->subHour())
            ->count();
            
        return $failures > 10; // More than 10 auth failures in an hour
    }

    /**
     * Check for suspicious patterns in request
     */
    private static function hasSuspiciousPatterns(array $requestData): bool
    {
        $suspiciousPatterns = [
            '/etc/passwd', '../', 'union select', 'drop table',
            '<script>', 'javascript:', 'onload=', 'onerror='
        ];
        
        $searchIn = [
            $requestData['endpoint'] ?? '',
            $requestData['query'] ?? [],
            $requestData['body'] ?? [],
        ];
        
        foreach ($searchIn as $data) {
            $content = is_array($data) ? json_encode($data) : $data;
            foreach ($suspiciousPatterns as $pattern) {
                if (stripos($content, $pattern) !== false) {
                    return true;
                }
            }
        }
        
        return false;
    }

    /**
     * Check for SQL injection patterns
     */
    private static function hasSqlInjectionPatterns(array $requestData): bool
    {
        $patterns = ['union select', 'drop table', 'insert into', 'delete from', '\' or \'1\'=\'1'];
        $content = json_encode($requestData);
        
        foreach ($patterns as $pattern) {
            if (stripos($content, $pattern) !== false) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Check for XSS patterns
     */
    private static function hasXssPatterns(array $requestData): bool
    {
        $patterns = ['<script>', 'javascript:', 'onload=', 'onerror=', 'document.cookie'];
        $content = json_encode($requestData);
        
        foreach ($patterns as $pattern) {
            if (stripos($content, $pattern) !== false) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Scope for successful requests
     */
    public function scopeSuccessful($query)
    {
        return $query->whereBetween('response_status', [200, 399]);
    }

    /**
     * Scope for failed requests
     */
    public function scopeFailed($query)
    {
        return $query->where('response_status', '>=', 400);
    }

    /**
     * Scope for suspicious requests
     */
    public function scopeSuspicious($query)
    {
        return $query->where('suspicious', true);
    }

    /**
     * Scope for slow requests
     */
    public function scopeSlow($query, int $threshold = 1000)
    {
        return $query->where('duration_ms', '>', $threshold);
    }

    /**
     * Scope for specific time period
     */
    public function scopeInPeriod($query, Carbon $start, Carbon $end)
    {
        return $query->whereBetween('created_at', [$start, $end]);
    }
}