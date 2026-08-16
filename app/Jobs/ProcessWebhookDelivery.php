<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ApiWebhookDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;

/**
 * Process webhook delivery
 *
 * Handles the asynchronous delivery of webhooks to external endpoints
 * with retry logic, timeout handling, and comprehensive logging.
 */
class ProcessWebhookDelivery implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4; // Initial attempt + 3 retries
    public int $timeout = 60; // 60 seconds timeout
    public int $backoff = 60; // 1 minute initial backoff

    public function __construct(
        private int $deliveryId
    ) {}

    /**
     * Execute the job
     */
    public function handle(): void
    {
        $delivery = ApiWebhookDelivery::find($this->deliveryId);
        
        if (!$delivery || $delivery->status === 'delivered') {
            return;
        }
        
        try {
            $this->deliverWebhook($delivery);
        } catch (\Exception $e) {
            $this->handleDeliveryError($delivery, $e);
            throw $e; // Re-throw to trigger retry
        }
    }

    /**
     * Deliver webhook to endpoint
     */
    private function deliverWebhook(ApiWebhookDelivery $delivery): void
    {
        $webhook = $delivery->webhook;
        $attempt = $this->attempts();
        
        $startTime = microtime(true);
        
        try {
            // Prepare headers
            $headers = array_merge(
                ['Content-Type' => $webhook->content_type],
                $webhook->custom_headers ?? []
            );
            
            // Add webhook signature
            $signature = $this->generateSignature($delivery->payload, $webhook->secret);
            $headers['X-Webhook-Signature'] = $signature;
            $headers['X-Webhook-ID'] = $delivery->delivery_id;
            $headers['X-Webhook-Event'] = $delivery->event_type;
            $headers['X-Webhook-Timestamp'] = $delivery->created_at->getTimestamp();
            $headers['X-Webhook-Attempt'] = $attempt;
            
            // Send request
            $response = Http::withHeaders($headers)
                ->timeout($webhook->timeout_seconds)
                ->retry(1, 0) // Single attempt, no internal retries
                ->post($webhook->url, $delivery->payload);
            
            $duration = (int) round((microtime(true) - $startTime) * 1000);
            
            // Update delivery record
            $delivery->update([
                'status' => $response->successful() ? 'delivered' : 'failed',
                'attempts' => $attempt,
                'last_attempt_at' => now(),
                'response_status' => $response->status(),
                'response_headers' => $response->headers(),
                'response_body' => $this->truncateResponse($response->body()),
                'response_time_ms' => $duration,
                'error_message' => $response->successful() ? null : 'HTTP ' . $response->status(),
                'delivered_at' => $response->successful() ? now() : null,
            ]);
            
            if ($response->successful()) {
                // Update webhook health metrics
                $webhook->recordSuccessfulDelivery();
            } else {
                // HTTP error - will trigger retry
                throw new \Exception("HTTP {$response->status()}: {$response->body()}");
            }
            
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $duration = (int) round((microtime(true) - $startTime) * 1000);
            
            $delivery->update([
                'status' => 'failed',
                'attempts' => $attempt,
                'last_attempt_at' => now(),
                'response_time_ms' => $duration,
                'error_message' => 'Connection failed: ' . $e->getMessage(),
            ]);
            
            throw $e;
        }
    }

    /**
     * Handle delivery error
     */
    private function handleDeliveryError(ApiWebhookDelivery $delivery, \Exception $e): void
    {
        $delivery->update([
            'status' => 'failed',
            'error_message' => $e->getMessage(),
            'last_attempt_at' => now(),
        ]);
        
        // Update webhook failure metrics
        $delivery->webhook->recordFailedDelivery();
    }

    /**
     * Generate webhook signature
     */
    private function generateSignature(array $payload, string $secret): string
    {
        $jsonPayload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return 'sha256=' . hash_hmac('sha256', $jsonPayload, $secret);
    }

    /**
     * Truncate response for storage
     */
    private function truncateResponse(?string $response): ?string
    {
        if (!$response) {
            return null;
        }
        
        // Store first 10KB of response
        return strlen($response) > 10240 
            ? substr($response, 0, 10240) . '...[truncated]'
            : $response;
    }

    /**
     * Calculate backoff based on attempt number
     */
    public function backoff(): int
    {
        // Exponential backoff: 1min, 5min, 15min, 30min
        return match($this->attempts()) {
            1 => 60,      // 1 minute
            2 => 300,     // 5 minutes  
            3 => 900,     // 15 minutes
            4 => 1800,    // 30 minutes
            default => 3600, // 1 hour
        };
    }

    /**
     * Determine if the job should fail permanently
     */
    public function failed(\Exception $exception): void
    {
        $delivery = ApiWebhookDelivery::find($this->deliveryId);
        
        if ($delivery) {
            $delivery->update([
                'status' => 'failed',
                'failed_at' => now(),
                'error_message' => 'Max retries exceeded: ' . $exception->getMessage(),
            ]);
            
            // Mark webhook as unhealthy if too many failures
            $webhook = $delivery->webhook;
            $recentFailures = $webhook->deliveries()
                ->where('created_at', '>=', now()->subHours(24))
                ->where('status', 'failed')
                ->count();
                
            if ($recentFailures >= 10) {
                $webhook->markUnhealthy('Too many failed deliveries');
            }
        }
    }
}