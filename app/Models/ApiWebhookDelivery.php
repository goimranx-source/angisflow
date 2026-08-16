<?php

namespace App\Models;

use App\Domain\Tenancy\Concerns\BelongsToAccount;
use App\Domain\Shared\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * API Webhook Delivery tracking individual webhook attempts
 *
 * Records each attempt to deliver a webhook, including request/response
 * details, timing information, and retry logic. Provides comprehensive
 * delivery audit trail for debugging and monitoring.
 */
class ApiWebhookDelivery extends Model
{
    use HasFactory, BelongsToAccount, HasPublicId;

    protected $fillable = [
        'account_id',
        'webhook_id',
        'public_id',
        'delivery_id',
        'event_type',
        'event_id',
        'attempt_number',
        'status',
        'attempted_at',
        'completed_at',
        'request_url',
        'request_headers',
        'request_body',
        'request_size',
        'response_status',
        'response_headers',
        'response_body',
        'response_size',
        'response_time_ms',
        'error_type',
        'error_message',
        'retry_after',
    ];

    protected $casts = [
        'request_headers' => 'array',
        'response_headers' => 'array',
        'attempted_at' => 'datetime',
        'completed_at' => 'datetime',
        'retry_after' => 'datetime',
    ];

    public const STATUSES = [
        'pending' => 'Pending',
        'success' => 'Success',
        'failed' => 'Failed',
        'cancelled' => 'Cancelled',
    ];

    public const ERROR_TYPES = [
        'network' => 'Network Error',
        'timeout' => 'Request Timeout',
        'http_error' => 'HTTP Error',
        'server_error' => 'Server Error',
        'client_error' => 'Client Error',
        'ssl_error' => 'SSL/TLS Error',
        'dns_error' => 'DNS Resolution Error',
        'connection_refused' => 'Connection Refused',
    ];

    public function webhook(): BelongsTo
    {
        return $this->belongsTo(ApiWebhook::class, 'webhook_id');
    }

    /**
     * Check if delivery was successful
     */
    public function isSuccessful(): bool
    {
        return $this->status === 'success' && 
               $this->response_status >= 200 && 
               $this->response_status < 300;
    }

    /**
     * Check if delivery failed
     */
    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    /**
     * Check if delivery should be retried
     */
    public function shouldRetry(): bool
    {
        if ($this->status !== 'failed') {
            return false;
        }
        
        $webhook = $this->webhook;
        if (!$webhook) {
            return false;
        }
        
        // Check if we haven't exceeded max retry attempts
        if ($this->attempt_number >= ($webhook->retry_attempts ?? 3)) {
            return false;
        }
        
        // Check if retry time has passed
        if ($this->retry_after && $this->retry_after->isFuture()) {
            return false;
        }
        
        return true;
    }

    /**
     * Mark delivery as successful
     */
    public function markSuccess(array $responseData): void
    {
        $this->update([
            'status' => 'success',
            'completed_at' => now(),
            'response_status' => $responseData['status'] ?? 200,
            'response_headers' => $responseData['headers'] ?? [],
            'response_body' => $responseData['body'] ?? null,
            'response_size' => $responseData['size'] ?? 0,
            'response_time_ms' => $responseData['response_time_ms'] ?? null,
        ]);
        
        $this->webhook?->recordSuccess();
    }

    /**
     * Mark delivery as failed
     */
    public function markFailed(
        string $errorType, 
        string $errorMessage, 
        ?array $responseData = null
    ): void {
        $updates = [
            'status' => 'failed',
            'completed_at' => now(),
            'error_type' => $errorType,
            'error_message' => $errorMessage,
        ];
        
        if ($responseData) {
            $updates['response_status'] = $responseData['status'] ?? null;
            $updates['response_headers'] = $responseData['headers'] ?? [];
            $updates['response_body'] = $responseData['body'] ?? null;
            $updates['response_size'] = $responseData['size'] ?? 0;
            $updates['response_time_ms'] = $responseData['response_time_ms'] ?? null;
        }
        
        // Set retry time if this delivery should be retried
        if ($this->webhook && $this->attempt_number < ($this->webhook->retry_attempts ?? 3)) {
            $updates['retry_after'] = $this->webhook->getNextRetryTime($this->attempt_number + 1);
        }
        
        $this->update($updates);
        
        $this->webhook?->recordFailure($errorMessage);
    }

    /**
     * Create retry attempt
     */
    public function createRetryAttempt(): ?self
    {
        if (!$this->shouldRetry()) {
            return null;
        }
        
        return self::create([
            'account_id' => $this->account_id,
            'webhook_id' => $this->webhook_id,
            'delivery_id' => $this->delivery_id,
            'event_type' => $this->event_type,
            'event_id' => $this->event_id,
            'attempt_number' => $this->attempt_number + 1,
            'status' => 'pending',
            'attempted_at' => now(),
            'request_url' => $this->request_url,
            'request_headers' => $this->request_headers,
            'request_body' => $this->request_body,
            'request_size' => $this->request_size,
        ]);
    }

    /**
     * Get response time category for display
     */
    public function getResponseTimeCategory(): string
    {
        if (!$this->response_time_ms) {
            return 'unknown';
        }
        
        return match(true) {
            $this->response_time_ms <= 500 => 'fast',
            $this->response_time_ms <= 2000 => 'moderate',
            $this->response_time_ms <= 5000 => 'slow',
            default => 'very_slow',
        };
    }

    /**
     * Get status label
     */
    public function getStatusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst($this->status);
    }

    /**
     * Get error type label
     */
    public function getErrorTypeLabel(): ?string
    {
        return $this->error_type ? self::ERROR_TYPES[$this->error_type] ?? ucfirst(str_replace('_', ' ', $this->error_type)) : null;
    }

    /**
     * Get delivery summary
     */
    public function getSummary(): array
    {
        return [
            'id' => $this->public_id,
            'event' => $this->event_type,
            'attempt' => $this->attempt_number,
            'status' => $this->getStatusLabel(),
            'response_time' => $this->response_time_ms ? $this->response_time_ms . 'ms' : null,
            'response_status' => $this->response_status,
            'error' => $this->error_message,
            'attempted_at' => $this->attempted_at->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }

    /**
     * Create delivery from webhook and event data
     */
    public static function createForWebhook(
        ApiWebhook $webhook,
        string $eventType,
        string $eventId,
        array $eventData
    ): self {
        $payload = [
            'id' => \Illuminate\Support\Str::uuid(),
            'event_type' => $eventType,
            'event_id' => $eventId,
            'data' => $eventData,
            'webhook' => [
                'id' => $webhook->public_id,
                'name' => $webhook->name,
            ],
            'created_at' => now()->toIso8601String(),
        ];
        
        $requestBody = json_encode($payload);
        $requestHeaders = array_merge(
            [
                'Content-Type' => $webhook->content_type ?? 'application/json',
                'User-Agent' => 'Prism-ERP-Webhook/1.0',
                'X-Webhook-Signature' => $webhook->createSignature($requestBody),
                'X-Webhook-Timestamp' => (string) time(),
                'X-Webhook-ID' => $webhook->public_id,
            ],
            $webhook->custom_headers ?? []
        );
        
        return self::create([
            'account_id' => $webhook->account_id,
            'webhook_id' => $webhook->id,
            'delivery_id' => $payload['id'],
            'event_type' => $eventType,
            'event_id' => $eventId,
            'attempt_number' => 1,
            'status' => 'pending',
            'attempted_at' => now(),
            'request_url' => $webhook->url,
            'request_headers' => $requestHeaders,
            'request_body' => $requestBody,
            'request_size' => strlen($requestBody),
        ]);
    }

    /**
     * Scope for successful deliveries
     */
    public function scopeSuccessful($query)
    {
        return $query->where('status', 'success')
                    ->whereBetween('response_status', [200, 299]);
    }

    /**
     * Scope for failed deliveries
     */
    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    /**
     * Scope for pending deliveries
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope for deliveries ready for retry
     */
    public function scopeReadyForRetry($query)
    {
        return $query->where('status', 'failed')
                    ->where(function ($q) {
                        $q->whereNull('retry_after')
                          ->orWhere('retry_after', '<=', now());
                    });
    }

    /**
     * Scope for slow deliveries
     */
    public function scopeSlow($query, int $threshold = 5000)
    {
        return $query->where('response_time_ms', '>', $threshold);
    }

    /**
     * Scope for specific event type
     */
    public function scopeForEvent($query, string $eventType)
    {
        return $query->where('event_type', $eventType);
    }
}