<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\PublicApi\PublicApiService;
use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public API Rate Limiting Middleware
 *
 * Enforces rate limits for public API requests based on API key,
 * account, and IP address limits. Provides comprehensive rate
 * limiting with burst allowances and proper HTTP headers.
 */
class PublicApiRateLimit
{
    public function __construct(
        private PublicApiService $publicApiService
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var ApiKey $apiKey */
        $apiKey = $request->attributes->get('api_key');
        
        if (!$apiKey) {
            // If no API key, apply basic IP-based rate limiting
            return $this->handleGuestRateLimit($request, $next);
        }
        
        // Check rate limits
        $rateLimitResult = $this->publicApiService->checkRateLimit($apiKey, $request);
        
        if (!$rateLimitResult['allowed']) {
            return $this->rateLimitExceededResponse($rateLimitResult);
        }
        
        // Record the request against rate limits
        $this->publicApiService->recordRequest($apiKey, $request);
        
        // Process the request
        $response = $next($request);
        
        // Add rate limit headers to response
        $this->addRateLimitHeaders($response, $rateLimitResult);
        
        return $response;
    }
    
    /**
     * Handle rate limiting for requests without API keys
     */
    private function handleGuestRateLimit(Request $request, Closure $next): Response
    {
        // Apply basic IP-based rate limiting for guest requests
        $ipRateLimit = \App\Models\ApiRateLimit::forIp($request->ip(), 1); // Use account ID 1 as fallback
        
        if (!$ipRateLimit->isRequestAllowed()) {
            return $this->rateLimitExceededResponse([
                'allowed' => false,
                'limit_type' => 'ip',
                'remaining' => $ipRateLimit->getRemainingRequests(),
                'reset_times' => $ipRateLimit->getSecondsUntilReset(),
                // `?:` cannot rescue this: min([]) raises a ValueError before
                // the fallback is ever reached, so the guard has to come first.
                'retry_after' => self::soonestReset($ipRateLimit->getSecondsUntilReset()),
            ]);
        }
        
        $ipRateLimit->recordRequest();
        
        $response = $next($request);
        
        // Add basic rate limit headers
        $remaining = $ipRateLimit->getRemainingRequests();
        $response->headers->set('X-RateLimit-Limit', (string) $ipRateLimit->requests_per_minute);
        $response->headers->set('X-RateLimit-Remaining', (string) $remaining['minute']);
        $response->headers->set('X-RateLimit-Reset', (string) ($ipRateLimit->minute_reset_at?->getTimestamp() ?? time() + 60));
        
        return $response;
    }
    
    /**
     * Return rate limit exceeded response
     */
    private function rateLimitExceededResponse(array $rateLimitResult): Response
    {
        $response = response()->json([
            'error' => 'Rate Limit Exceeded',
            'message' => $this->getRateLimitMessage($rateLimitResult['limit_type']),
            'code' => 'RATE_LIMIT_EXCEEDED',
            'details' => [
                'limit_type' => $rateLimitResult['limit_type'],
                'remaining' => $rateLimitResult['remaining'] ?? [],
                'retry_after' => $rateLimitResult['retry_after'],
            ],
        ], 429);
        
        // Add rate limit headers
        $this->addRateLimitHeaders($response, $rateLimitResult);
        
        // Add Retry-After header
        $response->headers->set('Retry-After', (string) $rateLimitResult['retry_after']);
        
        return $response;
    }
    
    /**
     * Add rate limit headers to response
     */
    private function addRateLimitHeaders(Response $response, array $rateLimitResult): void
    {
        if (!isset($rateLimitResult['remaining'])) {
            return;
        }
        
        $remaining = $rateLimitResult['remaining'];
        
        // Add standard rate limit headers
        if (is_array($remaining) && $remaining !== []) {
            // Multiple rate limits (API key, account, IP). Each entry may itself
            // be a per-window array; an empty one contributes nothing rather
            // than raising, which is why min() is never called on it directly.
            $candidates = [];

            foreach ($remaining as $limits) {
                if (is_array($limits)) {
                    if ($limits !== []) {
                        $candidates[] = min($limits);
                    }
                } elseif (is_numeric($limits)) {
                    $candidates[] = $limits;
                }
            }

            $response->headers->set('X-RateLimit-Remaining', (string) ($candidates === [] ? 0 : min($candidates)));
            
            // Add detailed headers for each limit type
            foreach ($remaining as $type => $limits) {
                if (is_array($limits)) {
                    $response->headers->set("X-RateLimit-{$type}-Minute", (string) ($limits['minute'] ?? 0));
                    $response->headers->set("X-RateLimit-{$type}-Hour", (string) ($limits['hour'] ?? 0));
                    $response->headers->set("X-RateLimit-{$type}-Day", (string) ($limits['day'] ?? 0));
                }
            }
        } else {
            // Single rate limit
            $response->headers->set('X-RateLimit-Remaining', (string) ($remaining['minute'] ?? 0));
        }
        
        // Add reset time
        if (isset($rateLimitResult['reset_times'])) {
            $pending = array_filter((array) $rateLimitResult['reset_times'], fn ($time) => $time > 0);

            // No window is currently counting down, so there is no reset to
            // advertise — and min([]) would have thrown rather than said so.
            if ($pending !== []) {
                $response->headers->set('X-RateLimit-Reset', (string) (time() + min($pending)));
            }
        }

        // Add rate limit policy information
        $response->headers->set('X-RateLimit-Policy', 'api-key;minute=60;hour=1000;day=10000');
    }

    /**
     * Seconds until the first window frees up, or a sane wait when none is
     * counting down.
     *
     * Exists because `min(array_filter(...)) ?: 60` reads as if it defaults,
     * and does not: on an empty array PHP 8 raises a ValueError from min()
     * before the fallback is consulted, turning "you are rate limited" into a
     * 500.
     *
     * @param  array<string, int|float>|mixed  $resetTimes
     */
    private static function soonestReset(mixed $resetTimes, int $fallbackSeconds = 60): int
    {
        $pending = array_filter((array) $resetTimes, fn ($seconds) => is_numeric($seconds) && $seconds > 0);

        return $pending === [] ? $fallbackSeconds : (int) ceil(min($pending));
    }
    
    /**
     * Get appropriate rate limit message based on limit type
     */
    private function getRateLimitMessage(string $limitType): string
    {
        return match($limitType) {
            'api_key' => 'API key rate limit exceeded. Please reduce your request frequency.',
            'account' => 'Account rate limit exceeded. Please upgrade your plan or reduce usage.',
            'ip' => 'IP address rate limit exceeded. Please wait before making more requests.',
            'endpoint' => 'Endpoint rate limit exceeded. This endpoint has specific usage limits.',
            default => 'Rate limit exceeded. Please wait before making more requests.',
        };
    }
}