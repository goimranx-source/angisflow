<?php

namespace App\Models;

use App\Domain\Tenancy\Concerns\BelongsToAccount;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use App\Domain\Shared\Concerns\HasPublicId;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * API Integration management for high-level integration tracking
 *
 * Manages and monitors third-party integrations including e-commerce platforms,
 * POS systems, accounting software, and custom applications. Tracks sync status,
 * health metrics, and configuration details.
 */
class ApiIntegration extends Model
{
    use HasFactory, BelongsToAccount, BelongsToBusiness, HasPublicId, SoftDeletes;

    protected $fillable = [
        'account_id',
        'business_id',
        'public_id',
        'name',
        'slug',
        'type',
        'provider',
        'version',
        'configuration',
        'field_mappings',
        'sync_settings',
        'bidirectional',
        'status',
        'is_active',
        'last_sync_at',
        'last_success_at',
        'last_error_at',
        'last_error_message',
        'total_syncs',
        'successful_syncs',
        'failed_syncs',
        'success_rate',
        'records_synced_today',
        'records_synced_total',
        'created_by',
        'description',
        'metadata',
        'installed_at',
    ];

    protected $casts = [
        'configuration' => 'array',
        'field_mappings' => 'array',
        'sync_settings' => 'array',
        'metadata' => 'array',
        'bidirectional' => 'boolean',
        'is_active' => 'boolean',
        'last_sync_at' => 'datetime',
        'last_success_at' => 'datetime',
        'last_error_at' => 'datetime',
        'success_rate' => 'decimal:4',
        'installed_at' => 'datetime',
    ];

    public const INTEGRATION_TYPES = [
        'e-commerce' => 'E-commerce Platform',
        'pos' => 'Point of Sale System',
        'accounting' => 'Accounting Software',
        'crm' => 'Customer Relationship Management',
        'inventory' => 'Inventory Management',
        'payment' => 'Payment Processor',
        'shipping' => 'Shipping & Logistics',
        'marketing' => 'Marketing Automation',
        'analytics' => 'Analytics Platform',
        'custom' => 'Custom Integration',
    ];

    public const PROVIDERS = [
        'shopify' => 'Shopify',
        'woocommerce' => 'WooCommerce',
        'magento' => 'Magento',
        'bigcommerce' => 'BigCommerce',
        'square' => 'Square',
        'stripe' => 'Stripe',
        'paypal' => 'PayPal',
        'quickbooks' => 'QuickBooks',
        'xero' => 'Xero',
        'salesforce' => 'Salesforce',
        'hubspot' => 'HubSpot',
        'mailchimp' => 'Mailchimp',
        'fedex' => 'FedEx',
        'ups' => 'UPS',
        'dhl' => 'DHL',
        'custom' => 'Custom/Other',
    ];

    public const STATUSES = [
        'active' => 'Active',
        'paused' => 'Paused',
        'error' => 'Error',
        'disabled' => 'Disabled',
        'setup' => 'Setup Required',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Check if integration is healthy
     */
    public function isHealthy(): bool
    {
        if (!$this->is_active || $this->status !== 'active') {
            return false;
        }
        
        // Consider healthy if success rate is above 90% or no recent errors
        return ($this->success_rate ?? 1.0) >= 0.90 && 
               (!$this->last_error_at || $this->last_error_at->isBefore(now()->subHours(24)));
    }

    /**
     * Record successful sync
     */
    public function recordSuccessfulSync(int $recordsSynced = 0): void
    {
        $this->increment('total_syncs');
        $this->increment('successful_syncs');
        $this->increment('records_synced_today', $recordsSynced);
        $this->increment('records_synced_total', $recordsSynced);
        
        $this->update([
            'last_sync_at' => now(),
            'last_success_at' => now(),
            'success_rate' => $this->calculateSuccessRate(),
            'status' => 'active',
        ]);
    }

    /**
     * Record failed sync
     */
    public function recordFailedSync(string $errorMessage): void
    {
        $this->increment('total_syncs');
        $this->increment('failed_syncs');
        
        $this->update([
            'last_sync_at' => now(),
            'last_error_at' => now(),
            'last_error_message' => $errorMessage,
            'success_rate' => $this->calculateSuccessRate(),
            'status' => 'error',
        ]);
    }

    /**
     * Calculate current success rate
     */
    protected function calculateSuccessRate(): float
    {
        if ($this->total_syncs === 0) {
            return 1.0;
        }
        
        return round($this->successful_syncs / $this->total_syncs, 4);
    }

    /**
     * Get configuration value
     */
    public function getConfig(string $key, $default = null)
    {
        return data_get($this->configuration, $key, $default);
    }

    /**
     * Set configuration value
     */
    public function setConfig(string $key, $value): void
    {
        $config = $this->configuration ?? [];
        data_set($config, $key, $value);
        $this->update(['configuration' => $config]);
    }

    /**
     * Get field mapping
     */
    public function getFieldMapping(string $localField): ?string
    {
        return data_get($this->field_mappings, $localField);
    }

    /**
     * Set field mapping
     */
    public function setFieldMapping(string $localField, string $remoteField): void
    {
        $mappings = $this->field_mappings ?? [];
        $mappings[$localField] = $remoteField;
        $this->update(['field_mappings' => $mappings]);
    }

    /**
     * Check if sync is due based on settings
     */
    public function isSyncDue(): bool
    {
        $syncFrequency = data_get($this->sync_settings, 'frequency', 'hourly');
        $lastSync = $this->last_sync_at;
        
        if (!$lastSync) {
            return true; // Never synced
        }
        
        return match($syncFrequency) {
            'realtime' => false, // Realtime syncs are event-driven
            'every_5_minutes' => $lastSync->isBefore(now()->subMinutes(5)),
            'every_15_minutes' => $lastSync->isBefore(now()->subMinutes(15)),
            'every_30_minutes' => $lastSync->isBefore(now()->subMinutes(30)),
            'hourly' => $lastSync->isBefore(now()->subHour()),
            'every_2_hours' => $lastSync->isBefore(now()->subHours(2)),
            'every_6_hours' => $lastSync->isBefore(now()->subHours(6)),
            'daily' => $lastSync->isBefore(now()->subDay()),
            'weekly' => $lastSync->isBefore(now()->subWeek()),
            'manual' => false, // Manual syncs only
            default => $lastSync->isBefore(now()->subHour()),
        };
    }

    /**
     * Reset daily counters (called by scheduler)
     */
    public function resetDailyCounters(): void
    {
        $this->update(['records_synced_today' => 0]);
    }

    /**
     * Get sync statistics
     */
    public function getSyncStatistics(?int $days = 30): array
    {
        // In a real implementation, you might query sync logs or other related tables
        return [
            'total_syncs' => $this->total_syncs,
            'successful_syncs' => $this->successful_syncs,
            'failed_syncs' => $this->failed_syncs,
            'success_rate' => $this->success_rate * 100,
            'records_synced_today' => $this->records_synced_today,
            'records_synced_total' => $this->records_synced_total,
            'last_sync' => $this->last_sync_at?->diffForHumans(),
            'last_success' => $this->last_success_at?->diffForHumans(),
            'last_error' => $this->last_error_at?->diffForHumans(),
            'is_healthy' => $this->isHealthy(),
            'status' => $this->getStatusLabel(),
        ];
    }

    /**
     * Pause integration
     */
    public function pause(string $reason = null): void
    {
        $this->update([
            'status' => 'paused',
            'last_error_message' => $reason,
        ]);
    }

    /**
     * Resume integration
     */
    public function resume(): void
    {
        $this->update([
            'status' => 'active',
            'last_error_message' => null,
        ]);
    }

    /**
     * Get integration type label
     */
    public function getTypeLabel(): string
    {
        return self::INTEGRATION_TYPES[$this->type] ?? ucfirst($this->type);
    }

    /**
     * Get provider label
     */
    public function getProviderLabel(): string
    {
        return self::PROVIDERS[$this->provider] ?? ucfirst($this->provider ?? 'Unknown');
    }

    /**
     * Get status label
     */
    public function getStatusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst($this->status);
    }

    /**
     * Get health status with color
     */
    public function getHealthStatus(): array
    {
        $isHealthy = $this->isHealthy();
        
        return [
            'status' => $isHealthy ? 'healthy' : 'unhealthy',
            'label' => $isHealthy ? 'Healthy' : 'Unhealthy',
            'color' => $isHealthy ? 'green' : 'red',
            'description' => $this->getHealthDescription(),
        ];
    }

    /**
     * Get health description
     */
    protected function getHealthDescription(): string
    {
        if (!$this->is_active) {
            return 'Integration is disabled';
        }
        
        if ($this->status === 'error') {
            return 'Integration has errors: ' . $this->last_error_message;
        }
        
        if ($this->status === 'paused') {
            return 'Integration is paused';
        }
        
        if (($this->success_rate ?? 1.0) < 0.90) {
            return 'Low success rate: ' . round($this->success_rate * 100) . '%';
        }
        
        return 'Integration is working normally';
    }

    /**
     * Scope for active integrations
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true)
                    ->where('status', 'active');
    }

    /**
     * Scope for specific type
     */
    public function scopeType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope for specific provider
     */
    public function scopeProvider($query, string $provider)
    {
        return $query->where('provider', $provider);
    }

    /**
     * Scope for healthy integrations
     */
    public function scopeHealthy($query)
    {
        return $query->where('is_active', true)
                    ->where('status', 'active')
                    ->where('success_rate', '>=', 0.90);
    }

    /**
     * Scope for integrations due for sync
     */
    public function scopeDueForSync($query)
    {
        return $query->where('is_active', true)
                    ->where('status', 'active')
                    ->where(function ($q) {
                        $q->whereNull('last_sync_at')
                          ->orWhere('last_sync_at', '<', now()->subHour()); // Simple hourly check
                    });
    }
}