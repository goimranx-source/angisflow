<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Storefront\CustomerPortalService;
use App\Domain\Sales\Models\Customer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Customer Authentication Middleware
 *
 * Authenticates customer portal sessions using bearer tokens.
 */
class CustomerAuth
{
    public function __construct(
        private CustomerPortalService $customerPortalService
    ) {}

    /**
     * Handle an incoming request
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (!$token) {
            return response()->json([
                'error' => 'Authentication required',
                'code' => 'TOKEN_MISSING',
            ], 401);
        }

        $session = $this->customerPortalService->validateSession($token);

        if (!$session) {
            return response()->json([
                'error' => 'Invalid or expired session',
                'code' => 'SESSION_INVALID',
            ], 401);
        }

        // Load the customer
        $customer = Customer::find($session->customer_id);

        if (!$customer || !$customer->is_active) {
            return response()->json([
                'error' => 'Customer account is inactive',
                'code' => 'ACCOUNT_INACTIVE',
            ], 401);
        }

        // Add customer to request
        $request->merge(['customer' => $customer]);

        return $next($request);
    }
}