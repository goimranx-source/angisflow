<?php

namespace App\Models;

use App\Domain\Tenancy\Concerns\BelongsToAccount;
use App\Domain\Shared\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

/**
 * API Rate Limit management and tracking
 *
 * Handles rate limiting for API keys, accounts, IPs, and specific endpoints.
 * Tracks current usage and automatically resets counters based on time windows.
 * Supports burst allowances and graduated rate limiting.
 */
class ApiRateLimit extends Model
{
    use HasFactory, BelongsToAccount, HasPublicId;

    protected $fillable = [
        'account_id',
        'public_id',
        'limit_type',
        'limit_key',
        'endpoint_pattern',
        'requests_per_minute',
        'requests_per_hour',
        'requests_per_day',
        'burst_allowance',
        'current_minute_count',
        'current_hour_count',
        'current_day_count',
        'minute_reset_at',
        'hour_reset_at',
        'day_reset_at',
        'is_active',
        'last_exceeded_at',
        'total_exceeded_count',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'minute_reset_at' => 'datetime',
        'hour_reset_at' => 'datetime',
        'day_reset_at' => 'datetime',
        'last_exceeded_at' => 'datetime',
    ];

    public const LIMIT_TYPES = [
        'api_key' => 'API Key',
        'account' => 'Account',
        'ip' => 'IP Address',
        'endpoint' => 'Specific Endpoint',
    ];

    public const DEFAULT_LIMITS = [
        'api_key' => [
            'requests_per_minute' => 60,
            'requests_per_hour' => 1000,
            'requests_per_day' => 10000,
            'burst_allowance' => 10,
        ],
        'account' => [
            'requests_per_minute' => 300,
            'requests_per_hour' => 5000,
            'requests_per_day' => 50000,
            'burst_allowance' => 50,
        ],
        'ip' => [
            'requests_per_minute' => 30,
            'requests_per_hour' => 500,
            'requests_per_day' => 2000,
            'burst_allowance' => 5,
        ],
    ];

    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(ApiKey::class, 'limit_key', 'key_id')
                    ->when($this->limit_type === 'api_key');
    }

    /**
     * Check if request is allowed under current rate limits
     */
    public function isRequestAllowed(): bool
    {
        $this->resetCountersIfNeeded();
        
        // Check minute limit (most restrictive)
        if ($this->current_minute_count >= ($this->requests_per_minute + $this->burst_allowance)) {
            return false;
        }
        
        // Check hour limit
        if ($this->current_hour_count >= $this->requests_per_hour) {
            return false;
        }
        
        // Check day limit
        if ($this->current_day_count >= $this->requests_per_day) {
            return false;
        }
        
        return true;
    }

    /**
     * Record a request against this rate limit
     */
    public function recordRequest(): void
    {
        $this->resetCountersIfNeeded();
        
        $this->increment('current_minute_count');
        $this->increment('current_hour_count');
        $this->increment('current_day_count');
    }

    /**
     * Record a rate limit exceedance
     */
    public function recordExceedance(): void
    {
        $this->update([
            'last_exceeded_at' => now(),
            'total_exceeded_count' => $this->total_exceeded_count + 1,
        ]);
    }

    /**
     * Get remaining requests for each time window
     */
    public function getRemainingRequests(): array
    {
        $this->resetCountersIfNeeded();
        
        return [
            'minute' => max(0, ($this->requests_per_minute + $this->burst_allowance) - $this->current_minute_count),
            'hour' => max(0, $this->requests_per_hour - $this->current_hour_count),
            'day' => max(0, $this->requests_per_day - $this->current_day_count),
        ];
    }

    /**
     * Get seconds until each limit resets
     */
    public function getSecondsUntilReset(): array
    {
        return [
            'minute' => $this->minute_reset_at ? now()->diffInSeconds($this->minute_reset_at, false) : 0,
            'hour' => $this->hour_reset_at ? now()->diffInSeconds($this->hour_reset_at, false) : 0,
            'day' => $this->day_reset_at ? now()->diffInSeconds($this->day_reset_at, false) : 0,
        ];
    }

    /**
     * Check if currently rate limited
     */
    public function isRateLimited(): bool
    {
        return !$this->isRequestAllowed();
    }

    /**
     * Get the most restrictive limit that's being hit
     */
    public function getRestrictiveLimit(): ?string
    {
        $this->resetCountersIfNeeded();
        
        if ($this->current_minute_count >= ($this->requests_per_minute + $this->burst_allowance)) {
            return 'minute';
        }
        
        if ($this->current_hour_count >= $this->requests_per_hour) {
            return 'hour';
        }
        
        if ($this->current_day_count >= $this->requests_per_day) {
            return 'day';
        }
        
        return null;
    }

    /**
     * Reset counters if time windows have passed
     */
    protected function resetCountersIfNeeded(): void
    {
        $now = now();
        $updates = [];
        
        // Reset minute counter
        if (!$this->minute_reset_at || $now->isAfter($this->minute_reset_at)) {
            $updates['current_minute_count'] = 0;
            $updates['minute_reset_at'] = $now->copy()->addMinute();
        }
        
        // Reset hour counter
        if (!$this->hour_reset_at || $now->isAfter($this->hour_reset_at)) {
            $updates['current_hour_count'] = 0;
            $updates['hour_reset_at'] = $now->copy()->addHour();
        }
        
        // Reset day counter
        if (!$this->day_reset_at || $now->isAfter($this->day_reset_at)) {
            $updates['current_day_count'] = 0;
            $updates['day_reset_at'] = $now->copy()->addDay();
        }
        
        if (!empty($updates)) {
            $this->update($updates);
        }
    }

    /**
     * Create or get rate limit for API key
     */
    public static function forApiKey(ApiKey $apiKey): self
    {
        return self::firstOrCreate([
            'account_id' => $apiKey->account_id,
            'limit_type' => 'api_key',
            'limit_key' => $apiKey->key_id,
        ], array_merge(
            self::DEFAULT_LIMITS['api_key'],
            $apiKey->rate_limits ?? [],
            [
                'is_active' => true,
            ]
        ));
    }

    /**
     * Create or get rate limit for account
     */
    public static function forAccount(int $accountId): self
    {
        return self::firstOrCreate([
            'account_id' => $accountId,
            'limit_type' => 'account',
            'limit_key' => (string) $accountId,
        ], array_merge(
            self::DEFAULT_LIMITS['account'],
            [
                'is_active' => true,
            ]
        ));
    }

    /**
     * Create or get rate limit for IP address
     */
    public static function forIp(string $ip, int $accountId): self
    {
        return self::firstOrCreate([
            'account_id' => $accountId,
            'limit_type' => 'ip',
            'limit_key' => $ip,
        ], array_merge(
            self::DEFAULT_LIMITS['ip'],
            [
                'is_active' => true,
            ]
        ));
    }

    /**
     * Create or get rate limit for specific endpoint
     */
    public static function forEndpoint(
        string $endpoint,
        int $accountId,
        array $limits = []
    ): self {
        return self::firstOrCreate([
            'account_id' => $accountId,
            'limit_type' => 'endpoint',
            'limit_key' => $endpoint,
            'endpoint_pattern' => $endpoint,
        ], array_merge(
            self::DEFAULT_LIMITS['api_key'], // Use API key defaults
            $limits,
            [
                'is_active' => true,
            ]
        ));
    }

    /**
     * Get usage percentage for display
     */
    public function getUsagePercentages(): array
    {
        $this->resetCountersIfNeeded();
        
        return [
            'minute' => min(100, ($this->current_minute_count / $this->requests_per_minute) * 100),
            'hour' => min(100, ($this->current_hour_count / $this->requests_per_hour) * 100),
            'day' => min(100, ($this->current_day_count / $this->requests_per_day) * 100),
        ];
    }

    /**
     * Check if rate limit is frequently exceeded
     */
    public function isFrequentlyExceeded(): bool
    {
        if ($this->total_exceeded_count === 0) {
            return false;
        }
        
        // More than 10 exceedances in the last day
        $recentExceedances = self::where('limit_type', $this->limit_type)
            ->where('limit_key', $this->limit_key)
            ->where('last_exceeded_at', '>', now()->subDay())
            ->sum('total_exceeded_count');
            
        return $recentExceedances > 10;
    }

    /**
     * Get limit type label
     */
    public function getLimitTypeLabel(): string
    {
        return self::LIMIT_TYPES[$this->limit_type] ?? ucfirst($this->limit_type);
    }

    /**
     * Scope for active limits
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope for specific limit type
     */
    public function scopeType($query, string $type)
    {
        return $query->where('limit_type', $type);
    }

    /**
     * Scope for frequently exceeded limits
     */
    public function scopeFrequentlyExceeded($query)
    {
        return $query->where('total_exceeded_count', '>', 10)
                    ->where('last_exceeded_at', '>', now()->subDay());
    }

    /**
     * Clean up old rate limit records
     */
    public static function cleanup(): int
    {
        $deleted = 0;
        
        // Remove unused IP limits older than 7 days
        $deleted += self::where('limit_type', 'ip')
            ->where('current_day_count', 0)
            ->where('updated_at', '<', now()->subDays(7))
            ->delete();
        
        // Remove inactive limits older than 30 days
        $deleted += self::where('is_active', false)
            ->where('updated_at', '<', now()->subDays(30))
            ->delete();
        
        return $deleted;
    }
}