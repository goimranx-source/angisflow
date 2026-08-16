<?php

namespace App\Models;

use App\Domain\Tenancy\Concerns\BelongsToAccount;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use App\Domain\Shared\Concerns\HasPublicId;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * API Key for external integrations and public API access
 *
 * Provides secure, scoped access tokens for external applications to interact
 * with the ERP system. Each key has specific scopes, rate limits, and security
 * features including IP restrictions and usage tracking.
 */
class ApiKey extends Model
{
    use HasFactory, BelongsToAccount, BelongsToBusiness, HasPublicId, SoftDeletes;

    protected $fillable = [
        'account_id',
        'business_id',
        'public_id',
        'name',
        'key_id',
        'key_hash',
        'key_prefix',
        'environment',
        'scopes',
        'rate_limits',
        'allowed_ips',
        'webhook_url',
        'webhook_secret',
        'is_active',
        'last_used_at',
        'last_used_from_ip',
        'expires_at',
        'created_by',
        'description',
        'metadata',
    ];

    protected $casts = [
        'scopes' => 'array',
        'rate_limits' => 'array',
        'allowed_ips' => 'array',
        'metadata' => 'array',
        'is_active' => 'boolean',
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    protected $hidden = [
        'key_hash',
        'webhook_secret',
    ];

    public const ENVIRONMENTS = [
        'production' => 'Production',
        'sandbox' => 'Sandbox',
        'development' => 'Development',
    ];

    public const SCOPES = [
        // Core Resources
        'orders:read' => 'Read orders',
        'orders:write' => 'Create and update orders',
        'products:read' => 'Read products and inventory',
        'products:write' => 'Create and update products',
        'customers:read' => 'Read customer information',
        'customers:write' => 'Create and update customers',
        
        // Financial
        'invoices:read' => 'Read invoices',
        'invoices:write' => 'Create and update invoices',
        'payments:read' => 'Read payment information',
        'payments:write' => 'Process payments',
        'transactions:read' => 'Read financial transactions',
        
        // Inventory
        'inventory:read' => 'Read inventory levels',
        'inventory:write' => 'Adjust inventory',
        'stock:read' => 'Read stock movements',
        'stock:write' => 'Create stock movements',
        
        // Analytics
        'reports:read' => 'Access reports and analytics',
        'webhooks:read' => 'Read webhook configurations',
        'webhooks:write' => 'Manage webhooks',
        
        // Administrative
        'users:read' => 'Read user information',
        'settings:read' => 'Read system settings',
        'integrations:read' => 'Read integration status',
        'integrations:write' => 'Manage integrations',
        
        // Bulk Operations
        'bulk:read' => 'Bulk read operations',
        'bulk:write' => 'Bulk write operations',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function requestLogs(): HasMany
    {
        return $this->hasMany(ApiRequestLog::class);
    }

    public function webhooks(): HasMany
    {
        return $this->hasMany(ApiWebhook::class);
    }

    public function rateLimits(): HasMany
    {
        return $this->hasMany(ApiRateLimit::class, 'limit_key', 'key_id')
                    ->where('limit_type', 'api_key');
    }

    public function usageStats(): HasMany
    {
        return $this->hasMany(ApiUsageStats::class);
    }

    /**
     * Generate a new API key pair
     */
    public static function generateKeyPair(string $environment = 'production'): array
    {
        $prefix = match($environment) {
            'production' => 'pk_live_',
            'sandbox' => 'pk_test_',
            'development' => 'pk_dev_',
            default => 'pk_live_',
        };
        
        $keyId = $prefix . Str::random(24);
        $secretKey = 'sk_' . substr($prefix, 3) . Str::random(32);
        
        return [
            'key_id' => $keyId,
            'secret_key' => $secretKey,
            'key_hash' => hash('sha256', $secretKey),
            'key_prefix' => substr($secretKey, 0, 20) . '...',
        ];
    }

    /**
     * Verify a secret key against this API key
     */
    public function verifySecretKey(string $secretKey): bool
    {
        return hash_equals($this->key_hash, hash('sha256', $secretKey));
    }

    /**
     * Check if key has specific scope
     */
    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes ?? [], true) || 
               in_array('*', $this->scopes ?? [], true);
    }

    /**
     * Check if key can access from IP
     */
    public function canAccessFromIp(string $ip): bool
    {
        if (empty($this->allowed_ips)) {
            return true; // No IP restrictions
        }
        
        return in_array($ip, $this->allowed_ips, true);
    }

    /**
     * Check if key is expired
     */
    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    /**
     * Update last used tracking
     */
    public function updateLastUsed(string $ip): void
    {
        $this->update([
            'last_used_at' => now(),
            'last_used_from_ip' => $ip,
        ]);
    }

    /**
     * Get scope labels
     */
    public function getScopeLabels(): array
    {
        return collect($this->scopes ?? [])
            ->map(fn($scope) => self::SCOPES[$scope] ?? $scope)
            ->toArray();
    }

    /**
     * Check if key is healthy (active, not expired, etc.)
     */
    public function isHealthy(): bool
    {
        return $this->is_active && !$this->isExpired();
    }

    /**
     * Get environment label
     */
    public function getEnvironmentLabel(): string
    {
        return self::ENVIRONMENTS[$this->environment] ?? ucfirst($this->environment);
    }

    /**
     * Get usage summary for display
     */
    public function getUsageSummary(?int $days = 30): array
    {
        $stats = $this->usageStats()
            ->where('period_type', 'day')
            ->where('period_date', '>=', now()->subDays($days))
            ->get();
        
        return [
            'total_requests' => $stats->sum('total_requests'),
            'successful_requests' => $stats->sum('successful_requests'),
            'failed_requests' => $stats->sum('failed_requests'),
            'rate_limited_requests' => $stats->sum('rate_limited_requests'),
            'avg_response_time' => $stats->avg('avg_response_time_ms'),
            'success_rate' => $stats->sum('total_requests') > 0 
                ? $stats->sum('successful_requests') / $stats->sum('total_requests') * 100 
                : 0,
        ];
    }

    /**
     * Scope for active keys
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true)
                    ->where(function ($q) {
                        $q->whereNull('expires_at')
                          ->orWhere('expires_at', '>', now());
                    });
    }

    /**
     * Scope for specific environment
     */
    public function scopeEnvironment($query, string $environment)
    {
        return $query->where('environment', $environment);
    }

    /**
     * Scope for keys with specific scope
     */
    public function scopeWithScope($query, string $scope)
    {
        return $query->whereJsonContains('scopes', $scope)
                    ->orWhereJsonContains('scopes', '*');
    }
}