<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\PublicApi\PublicApiService;
use App\Http\Api\ApiResponse;
use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public API Authentication Middleware
 *
 * Authenticates requests to the public API using API keys and sets up
 * the appropriate context for the request. Also handles basic security
 * checks and request validation.
 */
class PublicApiAuth
{
    public function __construct(
        private PublicApiService $publicApiService
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Authenticate the request
        $apiKey = $this->publicApiService->authenticateRequest($request);
        
        if (!$apiKey) {
            return $this->unauthorizedResponse('Invalid or missing API key');
        }
        
        // Check if API key is active and not expired
        if (!$apiKey->isHealthy()) {
            return $this->unauthorizedResponse('API key is inactive or expired');
        }
        
        // Check scope permissions for this endpoint
        $requiredScope = $this->getRequiredScope($request);
        if ($requiredScope && !$apiKey->hasScope($requiredScope)) {
            return $this->forbiddenResponse("Missing required scope: {$requiredScope}");
        }
        
        // Set API key in request for downstream usage
        $request->attributes->set('api_key', $apiKey);
        $request->attributes->set('authenticated_via', 'api_key');
        
        // Set tenant context from API key
        $tenantContext = app(\App\Domain\Tenancy\TenantContext::class);
        $tenantContext->setAccount($apiKey->account);
        
        if ($apiKey->business) {
            $tenantContext->setBusiness($apiKey->business);
        }
        
        return $next($request);
    }
    
    /**
     * Determine required scope for the current request
     */
    private function getRequiredScope(Request $request): ?string
    {
        $method = strtolower($request->method());
        $path = trim($request->path(), '/');
        
        // Map common API endpoints to required scopes
        $scopeMappings = [
            // Orders
            'GET:api/v1/public/orders' => 'orders:read',
            'POST:api/v1/public/orders' => 'orders:write',
            'PUT:api/v1/public/orders' => 'orders:write',
            'PATCH:api/v1/public/orders' => 'orders:write',
            'DELETE:api/v1/public/orders' => 'orders:write',
            
            // Products
            'GET:api/v1/public/products' => 'products:read',
            'POST:api/v1/public/products' => 'products:write',
            'PUT:api/v1/public/products' => 'products:write',
            'PATCH:api/v1/public/products' => 'products:write',
            'DELETE:api/v1/public/products' => 'products:write',
            
            // Customers
            'GET:api/v1/public/customers' => 'customers:read',
            'POST:api/v1/public/customers' => 'customers:write',
            'PUT:api/v1/public/customers' => 'customers:write',
            'PATCH:api/v1/public/customers' => 'customers:write',
            'DELETE:api/v1/public/customers' => 'customers:write',
            
            // Invoices
            'GET:api/v1/public/invoices' => 'invoices:read',
            'POST:api/v1/public/invoices' => 'invoices:write',
            'PUT:api/v1/public/invoices' => 'invoices:write',
            'PATCH:api/v1/public/invoices' => 'invoices:write',
            
            // Inventory
            'GET:api/v1/public/inventory' => 'inventory:read',
            'POST:api/v1/public/inventory' => 'inventory:write',
            'PUT:api/v1/public/inventory' => 'inventory:write',
            'PATCH:api/v1/public/inventory' => 'inventory:write',
            
            // Webhooks
            'GET:api/v1/public/webhooks' => 'webhooks:read',
            'POST:api/v1/public/webhooks' => 'webhooks:write',
            'PUT:api/v1/public/webhooks' => 'webhooks:write',
            'PATCH:api/v1/public/webhooks' => 'webhooks:write',
            'DELETE:api/v1/public/webhooks' => 'webhooks:write',
        ];
        
        $key = strtoupper($method) . ':' . $path;
        
        // Check exact match first
        if (isset($scopeMappings[$key])) {
            return $scopeMappings[$key];
        }
        
        // Check pattern matches for dynamic routes
        foreach ($scopeMappings as $pattern => $scope) {
            $patternRegex = str_replace('*', '[^/]+', preg_quote($pattern, '/'));
            if (preg_match("/^{$patternRegex}$/", $key)) {
                return $scope;
            }
        }
        
        // Default scope checking based on HTTP method and path patterns
        if (preg_match('/^GET:/', $key)) {
            // GET requests generally need read access
            if (str_contains($path, 'orders')) return 'orders:read';
            if (str_contains($path, 'products')) return 'products:read';
            if (str_contains($path, 'customers')) return 'customers:read';
            if (str_contains($path, 'invoices')) return 'invoices:read';
            if (str_contains($path, 'inventory')) return 'inventory:read';
        } elseif (preg_match('/^(POST|PUT|PATCH|DELETE):/', $key)) {
            // Write requests generally need write access
            if (str_contains($path, 'orders')) return 'orders:write';
            if (str_contains($path, 'products')) return 'products:write';
            if (str_contains($path, 'customers')) return 'customers:write';
            if (str_contains($path, 'invoices')) return 'invoices:write';
            if (str_contains($path, 'inventory')) return 'inventory:write';
        }
        
        return null; // No specific scope required
    }
    
    /**
     * Return unauthorized response
     */
    private function unauthorizedResponse(string $message): Response
    {
        return response()->json([
            'error' => 'Unauthorized',
            'message' => $message,
            'code' => 'API_AUTH_FAILED',
        ], 401);
    }
    
    /**
     * Return forbidden response
     */
    private function forbiddenResponse(string $message): Response
    {
        return response()->json([
            'error' => 'Forbidden',
            'message' => $message,
            'code' => 'INSUFFICIENT_SCOPE',
        ], 403);
    }
}