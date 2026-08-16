<?php

declare(strict_types=1);

namespace App\Http\Api\V1;

use App\Domain\Billing\Allowance;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Billing\PlanEntitlement;
use App\Domain\Catalogue\Contracts\ModuleEntitlement;
use App\Domain\Catalogue\Models\Module;
use App\Domain\Tenancy\TenantContext;
use App\Http\Api\ApiResponse;
use App\Http\Api\Endpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What this workspace is entitled to, and what is blocked.
 *
 * Used by the billing screen to show what the current plan includes, and by
 * the operator admin panel (task 38) to inspect and override entitlements.
 *
 * ── What this returns ────────────────────────────────────────────────────────
 *
 * plan         the current plan code and name, or null on trial
 * status       trialing | active | past_due | cancelled | expired | none
 * unrestricted true when the resolver returns null (trial or empty features)
 * permitted    list of module keys the plan allows (empty when unrestricted)
 * blocked      modules that are enabled in this workspace but not permitted
 * allowances   workspace and business counts vs limits
 *
 * The `blocked` list is the operator's diagnostic: a module that is enabled
 * but not permitted means the subscriber downgraded after enabling it, or an
 * operator toggled something manually. The sidebar already hides it; this
 * surface explains why.
 */
class EntitlementEndpoint extends Endpoint
{
    public function __construct(
        private readonly ModuleEntitlement $entitlement,
        private readonly TenantContext $tenant,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $account = $this->tenant->account();
        $workspace = $this->tenant->workspace();

        $subscription = Subscription::withoutGlobalScopes()
            ->where('account_id', $account->id)
            ->latest('id')
            ->with('plan')
            ->first();

        $plan = $subscription?->plan;
        $permitted = $this->entitlement->permittedKeys($workspace);
        $unrestricted = $permitted === null;

        // Which non-core modules are enabled here but not in the permitted list.
        $blocked = [];

        if (! $unrestricted && $workspace !== null) {
            $enabledKeys = $workspace->modules()
                ->wherePivot('is_enabled', true)
                ->where('is_core', false)
                ->pluck('key')
                ->all();

            $blocked = array_values(array_filter(
                $enabledKeys,
                fn (string $key) => ! in_array($key, $permitted, true),
            ));
        }

        $allowance = Allowance::for($account);

        return ApiResponse::cacheable([
            'data' => [
                'plan' => $plan ? [
                    'code'     => $plan->code,
                    'name'     => $plan->name,
                    'interval' => $plan->interval,
                    'features' => $plan->features ?? [],
                ] : null,

                'subscription' => $subscription ? [
                    'status'              => $subscription->status,
                    'trial_ends_at'       => $subscription->trial_ends_at?->toIso8601String(),
                    'current_period_end'  => $subscription->current_period_end?->toIso8601String(),
                    'cancel_at_period_end' => $subscription->cancel_at_period_end,
                ] : null,

                'unrestricted' => $unrestricted,
                'permitted'    => $unrestricted ? [] : $permitted,
                'blocked'      => $blocked,

                'allowances' => $allowance->toPayload($workspace?->id),
            ],
        ], seconds: 60);
    }
}
