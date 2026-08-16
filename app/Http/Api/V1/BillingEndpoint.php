<?php

declare(strict_types=1);

namespace App\Http\Api\V1;

use App\Domain\Tenancy\TenantContext;
use App\Http\Api\Endpoint;
use Illuminate\Http\JsonResponse;

/**
 * What the subscriber is on, and when it renews.
 *
 * A stub at this stage. The admin panel that manages all of this from the other
 * side comes next and will read the same two tables.
 */
class BillingEndpoint extends Endpoint
{
    public function show(TenantContext $tenant): JsonResponse
    {
        $account = $tenant->account();

        $subscription = $account?->subscription()
            ->with('plan:id,code,name,price_minor,currency,interval')
            ->first();

        return response()->json([
            'data' => [
                'account' => [
                    'status' => $account?->status,
                    'trial_ends_at' => $account?->trial_ends_at?->toIso8601String(),
                    'suspended_reason' => $account?->suspended_reason,
                ],
                'subscription' => $subscription === null ? null : [
                    'status' => $subscription->status,
                    'plan' => $subscription->plan?->name,
                    'price' => $subscription->plan?->price()->jsonSerialize(),
                    'interval' => $subscription->plan?->interval,
                    'renews_at' => $subscription->current_period_end?->toIso8601String(),
                    'trial_ends_at' => $subscription->trial_ends_at?->toIso8601String(),
                    'cancel_at_period_end' => $subscription->cancel_at_period_end,
                ],
            ],
        ])->header('Cache-Control', 'no-store, private');
    }
}
