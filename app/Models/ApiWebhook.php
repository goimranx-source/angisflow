<?php

namespace App\Models;

use App\Domain\Tenancy\Concerns\BelongsToAccount;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use App\Domain\Shared\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * API Webhook configuration for real-time event notifications
 *
 * Manages webhook endpoints that receive real-time notifications about
 * events and data changes in the ERP system. Supports event filtering,
 * retry logic, and health monitoring.
 */
class ApiWebhook extends Model
{
    use HasFactory, BelongsToAccount, BelongsToBusiness, HasPublicId, SoftDeletes;

    protected $fillable = [
        'account_id',
        'business_id',
        'api_key_id',
        'public_id',
        'name',
        'url',
        'secret',
        'events',
        'filters',
        'content_type',
        'custom_headers',
        'timeout_seconds',
        'retry_attempts',
        'retry_intervals',
        'is_active',
        'last_triggered_at',
        'last_success_at',
        'last_failure_at',
        'total_deliveries',
        'successful_deliveries',
        'failed_deliveries',
        'success_rate',
        'consecutive_failures',
        'is_healthy',
        'disabled_at',
        'disabled_reason',
    ];

    protected $casts = [
        'events' => 'array',
        'filters' => 'array',
        'custom_headers' => 'array',
        'retry_intervals' => 'array',
        'is_active' => 'boolean',
        'is_healthy' => 'boolean',
        'last_triggered_at' => 'datetime',
        'last_success_at' => 'datetime',
        'last_failure_at' => 'datetime',
        'disabled_at' => 'datetime',
        'success_rate' => 'decimal:4',
    ];

    protected $hidden = [
        'secret',
    ];

    public const AVAILABLE_EVENTS = [
        // Order Events
        'order.created' => 'Order Created',
        'order.updated' => 'Order Updated',
        'order.cancelled' => 'Order Cancelled',
        'order.fulfilled' => 'Order Fulfilled',
        'order.shipped' => 'Order Shipped',
        'order.delivered' => 'Order Delivered',
        'order.returned' => 'Order Returned',
        
        // Payment Events
        'payment.received' => 'Payment Received',
        'payment.failed' => 'Payment Failed',
        'payment.refunded' => 'Payment Refunded',
        
        // Product Events
        'product.created' => 'Product Created',
        'product.updated' => 'Product Updated',
        'product.deleted' => 'Product Deleted',
        'product.stock_low' => 'Product Stock Low',
        'product.out_of_stock' => 'Product Out of Stock',
        
        // Customer Events
        'customer.created' => 'Customer Created',
        'customer.updated' => 'Customer Updated',
        'customer.deleted' => 'Customer Deleted',
        
        // Invoice Events
        'invoice.created' => 'Invoice Created',
        'invoice.sent' => 'Invoice Sent',
        'invoice.paid' => 'Invoice Paid',
        'invoice.overdue' => 'Invoice Overdue',
        
        // Inventory Events
        'inventory.adjusted' => 'Inventory Adjusted',
        'inventory.movement' => 'Inventory Movement',
        
        // Integration Events
        'integration.connected' => 'Integration Connected',
        'integration.disconnected' => 'Integration Disconnected',
        'integration.sync_completed' => 'Integration Sync Completed',
        'integration.sync_failed' => 'Integration Sync Failed',
    ];

    public const DEFAULT_RETRY_INTERVALS = [60, 300, 900]; // 1 min, 5 min, 15 min
    public const MAX_CONSECUTIVE_FAILURES = 10;

    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(ApiKey::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(ApiWebhookDelivery::class, 'webhook_id');
    }

    public function recentDeliveries(): HasMany
    {
        return $this->deliveries()
                    ->where('created_at', '>', now()->subDays(7))
                    ->orderBy('created_at', 'desc');
    }

    /**
     * Generate a new webhook secret
     */
    public static function generateSecret(): string
    {
        return 'whsec_' . Str::random(32);
    }

    /**
     * Check if webhook should receive event
     */
    public function shouldReceiveEvent(string $eventType, array $eventData = []): bool
    {
        if (!$this->is_active || !$this->is_healthy) {
            return false;
        }
        
        // Check if event type is subscribed
        if (!in_array($eventType, $this->events ?? [], true)) {
            return false;
        }
        
        // Apply filters if configured
        if ($this->filters) {
            return $this->passesFilters($eventType, $eventData);
        }
        
        return true;
    }

    /**
     * Check if event passes configured filters
     */
    protected function passesFilters(string $eventType, array $eventData): bool
    {
        foreach ($this->filters as $filter) {
            $field = $filter['field'] ?? null;
            $operator = $filter['operator'] ?? 'equals';
            $value = $filter['value'] ?? null;
            
            if (!$field || !isset($eventData[$field])) {
                continue;
            }
            
            $fieldValue = $eventData[$field];
            
            $passes = match($operator) {
                'equals' => $fieldValue == $value,
                'not_equals' => $fieldValue != $value,
                'contains' => is_string($fieldValue) && str_contains($fieldValue, $value),
                'not_contains' => is_string($fieldValue) && !str_contains($fieldValue, $value),
                'in' => is_array($value) && in_array($fieldValue, $value),
                'not_in' => is_array($value) && !in_array($fieldValue, $value),
                'greater_than' => is_numeric($fieldValue) && is_numeric($value) && $fieldValue > $value,
                'less_than' => is_numeric($fieldValue) && is_numeric($value) && $fieldValue < $value,
                default => true,
            };
            
            if (!$passes) {
                return false;
            }
        }
        
        return true;
    }

    /**
     * Create HMAC signature for webhook payload
     */
    public function createSignature(string $payload, ?int $timestamp = null): string
    {
        $timestamp = $timestamp ?? time();
        $signedPayload = $timestamp . '.' . $payload;
        
        return hash_hmac('sha256', $signedPayload, $this->secret);
    }

    /**
     * Verify webhook signature
     */
    public function verifySignature(string $signature, string $payload, int $timestamp): bool
    {
        $expectedSignature = $this->createSignature($payload, $timestamp);
        
        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Record successful delivery
     */
    public function recordSuccess(): void
    {
        $this->increment('total_deliveries');
        $this->increment('successful_deliveries');
        
        $this->update([
            'last_success_at' => now(),
            'consecutive_failures' => 0,
            'is_healthy' => true,
            'success_rate' => $this->calculateSuccessRate(),
        ]);
    }

    /**
     * Record failed delivery
     */
    public function recordFailure(string $reason = null): void
    {
        $this->increment('total_deliveries');
        $this->increment('failed_deliveries');
        $this->increment('consecutive_failures');
        
        $updates = [
            'last_failure_at' => now(),
            'success_rate' => $this->calculateSuccessRate(),
        ];
        
        // Auto-disable webhook after too many consecutive failures
        if ($this->consecutive_failures >= self::MAX_CONSECUTIVE_FAILURES) {
            $updates['is_healthy'] = false;
            $updates['disabled_at'] = now();
            $updates['disabled_reason'] = $reason ?? 'Too many consecutive failures';
        }
        
        $this->update($updates);
    }

    /**
     * Calculate current success rate
     */
    protected function calculateSuccessRate(): float
    {
        if ($this->total_deliveries === 0) {
            return 1.0;
        }
        
        return round($this->successful_deliveries / $this->total_deliveries, 4);
    }

    /**
     * Get retry intervals array
     */
    public function getRetryIntervals(): array
    {
        return $this->retry_intervals ?? self::DEFAULT_RETRY_INTERVALS;
    }

    /**
     * Get next retry time for attempt number
     */
    public function getNextRetryTime(int $attemptNumber): ?\Carbon\Carbon
    {
        $intervals = $this->getRetryIntervals();
        $maxAttempts = $this->retry_attempts ?? 3;
        
        if ($attemptNumber > $maxAttempts) {
            return null;
        }
        
        $intervalIndex = min($attemptNumber - 1, count($intervals) - 1);
        $interval = $intervals[$intervalIndex] ?? 900; // Default to 15 minutes
        
        return now()->addSeconds($interval);
    }

    /**
     * Check if webhook is healthy
     */
    public function checkHealth(): bool
    {
        // Consider healthy if success rate is above 80% and not too many recent failures
        $isHealthy = $this->success_rate >= 0.80 && 
                    $this->consecutive_failures < self::MAX_CONSECUTIVE_FAILURES;
        
        if ($isHealthy !== $this->is_healthy) {
            $this->update(['is_healthy' => $isHealthy]);
        }
        
        return $isHealthy;
    }

    /**
     * Manually enable/disable webhook
     */
    public function setEnabled(bool $enabled, ?string $reason = null): void
    {
        $updates = ['is_active' => $enabled];
        
        if (!$enabled) {
            $updates['disabled_at'] = now();
            $updates['disabled_reason'] = $reason ?? 'Manually disabled';
        } else {
            $updates['disabled_at'] = null;
            $updates['disabled_reason'] = null;
            $updates['consecutive_failures'] = 0;
            $updates['is_healthy'] = true;
        }
        
        $this->update($updates);
    }

    /**
     * Get webhook statistics
     */
    public function getStatistics(?int $days = 30): array
    {
        $deliveries = $this->deliveries()
            ->where('created_at', '>', now()->subDays($days))
            ->get();
        
        $totalDeliveries = $deliveries->count();
        $successfulDeliveries = $deliveries->where('status', 'success')->count();
        $failedDeliveries = $deliveries->where('status', 'failed')->count();
        
        return [
            'total_deliveries' => $totalDeliveries,
            'successful_deliveries' => $successfulDeliveries,
            'failed_deliveries' => $failedDeliveries,
            'success_rate' => $totalDeliveries > 0 ? ($successfulDeliveries / $totalDeliveries) * 100 : 0,
            'average_response_time' => $deliveries->where('response_time_ms', '>', 0)->avg('response_time_ms'),
            'consecutive_failures' => $this->consecutive_failures,
            'is_healthy' => $this->is_healthy,
            'last_success' => $this->last_success_at?->diffForHumans(),
            'last_failure' => $this->last_failure_at?->diffForHumans(),
        ];
    }

    /**
     * Get available event labels
     */
    public static function getEventLabels(): array
    {
        return self::AVAILABLE_EVENTS;
    }

    /**
     * Get subscribed event labels
     */
    public function getSubscribedEventLabels(): array
    {
        return collect($this->events ?? [])
            ->mapWithKeys(fn($event) => [$event => self::AVAILABLE_EVENTS[$event] ?? $event])
            ->toArray();
    }

    /**
     * Check if webhook is subscribed to an event
     */
    public function isSubscribedToEvent(string $eventType): bool
    {
        return in_array($eventType, $this->events ?? []);
    }

    /**
     * Scope for active webhooks
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope for healthy webhooks
     */
    public function scopeHealthy($query)
    {
        return $query->where('is_healthy', true);
    }

    /**
     * Scope for webhooks subscribing to specific event
     */
    public function scopeSubscribedToEvent($query, string $eventType)
    {
        return $query->whereJsonContains('events', $eventType);
    }

    /**
     * Scope for webhooks with recent failures
     */
    public function scopeWithRecentFailures($query)
    {
        return $query->where('consecutive_failures', '>', 0)
                    ->where('last_failure_at', '>', now()->subHours(24));
    }
}