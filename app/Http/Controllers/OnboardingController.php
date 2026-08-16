<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Account;
use App\Domain\Tenancy\Models\Business;
use App\Domain\Tenancy\Models\Workspace;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Multi-step onboarding flow.
 *
 * Guides new users through workspace creation, business setup, and plan
 * selection after they register. Each step tracks its completion in the
 * account's onboarding_steps JSON.
 */
class OnboardingController extends Controller
{
    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * Create a new workspace during onboarding.
     *
     * Accepts workspace name and optional team member invitations. Team members
     * will receive email invites with role assignments.
     */
    public function workspace(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'team_members' => 'nullable|array|max:10',
            'team_members.*.email' => 'required_with:team_members|email',
            'team_members.*.role' => ['required_with:team_members', 'string', 'in:manager,accountant,viewer'],
        ]);

        $account = $request->user()->account;

        // Check workspace limit from current plan
        $subscription = $account->subscription;
        if ($subscription) {
            $plan = $subscription->plan;
            $workspaceLimit = $plan->limit('workspaces');

            if ($workspaceLimit !== Plan::UNLIMITED) {
                $currentCount = $account->workspaces()->count();
                if ($currentCount >= $workspaceLimit) {
                    return response()->json([
                        'message' => 'Your plan allows up to ' . $workspaceLimit . ' workspace(s). Please upgrade to add more.',
                    ], 403);
                }
            }
        }

        $workspace = DB::transaction(function () use ($account, $validated) {
            $workspace = Workspace::create([
                'account_id' => $account->id,
                'name' => $validated['name'],
                'slug' => Workspace::uniqueSlug($account->id, $validated['name']),
                'is_active' => true,
            ]);

            // Track onboarding step
            $account->trackOnboardingStep('workspace_created');

            // TODO: Send team member invitations if provided
            // This would involve creating pending invitations and sending emails

            return $workspace;
        });

        return response()->json([
            'message' => 'Workspace created successfully',
            'workspace' => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'slug' => $workspace->slug,
            ],
        ]);
    }

    /**
     * Create a new business during onboarding.
     *
     * Requires a workspace_id to associate the business with. Validates
     * business limits from the current subscription plan.
     */
    public function business(Request $request)
    {
        $validated = $request->validate([
            'workspace_id' => 'required|exists:workspaces,id',
            'name' => 'required|string|max:255',
            'short_code' => 'required|string|min:2|max:6|alpha_num',
            'currency' => 'required|string|size:3',
            'country' => 'nullable|string|size:2',
        ]);

        $account = $request->user()->account;

        // Verify workspace belongs to this account
        $workspace = Workspace::where('account_id', $account->id)
            ->where('id', $validated['workspace_id'])
            ->firstOrFail();

        // Check business limit from current plan
        $subscription = $account->subscription;
        if ($subscription) {
            $plan = $subscription->plan;
            $businessLimit = $plan->limit('businesses');

            if ($businessLimit !== Plan::UNLIMITED) {
                $currentCount = $account->businesses()->count();
                if ($currentCount >= $businessLimit) {
                    return response()->json([
                        'message' => 'Your plan allows up to ' . $businessLimit . ' business(es). Please upgrade to add more.',
                    ], 403);
                }
            }
        }

        $business = DB::transaction(function () use ($account, $workspace, $validated) {
            $business = Business::create([
                'account_id' => $account->id,
                'workspace_id' => $workspace->id,
                'name' => $validated['name'],
                'short_code' => strtoupper($validated['short_code']),
                'base_currency' => strtoupper($validated['currency']),
                'country' => $validated['country'] ?? null,
                'timezone' => $account->timezone,
                'is_active' => true,
            ]);

            // Track onboarding step
            $account->trackOnboardingStep('business_created');

            // Update user's current business
            $request->user()->update(['current_business_id' => $business->id]);

            return $business;
        });

        return response()->json([
            'message' => 'Business created successfully',
            'business' => [
                'id' => $business->id,
                'name' => $business->name,
                'short_code' => $business->short_code,
                'currency' => $business->base_currency,
            ],
        ]);
    }

    /**
     * Start a subscription during onboarding.
     *
     * Creates a trial subscription for the selected plan with $0 initial
     * payment. Marks onboarding as complete.
     */
    public function subscribe(Request $request)
    {
        $validated = $request->validate([
            'plan_id' => 'required|exists:plans,id',
        ]);

        $account = $request->user()->account;
        $plan = Plan::findOrFail($validated['plan_id']);

        // Check if already has an active subscription
        if ($account->subscription && $account->subscription->isLive()) {
            return response()->json([
                'message' => 'You already have an active subscription.',
            ], 400);
        }

        DB::transaction(function () use ($account, $plan) {
            // Create trial subscription
            Subscription::create([
                'account_id' => $account->id,
                'plan_id' => $plan->id,
                'status' => Subscription::STATUS_TRIALING,
                'started_at' => now(),
                'trial_ends_at' => now()->addDays($plan->trial_days),
                'current_period_start' => now(),
                'current_period_end' => now()->addDays($plan->trial_days),
            ]);

            // Update account status
            $account->update([
                'status' => Account::STATUS_TRIALING,
                'trial_ends_at' => now()->addDays($plan->trial_days),
            ]);

            // Track onboarding steps
            $account->trackOnboardingStep('plan_selected');
            $account->trackOnboardingStep('payment_confirmed');
            $account->markOnboardingComplete();
        });

        // Build boot payload with updated tenant info
        $boot = $this->buildBootPayload($request);

        return response()->json([
            'message' => 'Trial started successfully! Welcome to Prism.',
            'redirect' => '/home',
            'boot' => $boot,
        ]);
    }

    /**
     * Skip remaining onboarding steps.
     *
     * Marks onboarding as complete even if some steps were skipped.
     * User can complete missing steps from the dashboard later.
     */
    public function skip(Request $request)
    {
        $account = $request->user()->account;

        // Mark as complete even with incomplete steps
        $account->markOnboardingComplete();

        return response()->json([
            'message' => 'Onboarding skipped. You can complete setup from your dashboard.',
            'redirect' => '/home',
        ]);
    }

    /**
     * Build the boot payload for the frontend.
     *
     * Includes updated session data with account, user, and tenant information.
     */
    private function buildBootPayload(Request $request): array
    {
        $user = $request->user()->load('account');
        $account = $user->account;

        // Get current workspace and business
        $workspace = $account->workspaces()->where('is_active', true)->first();
        $business = $user->current_business_id
            ? Business::find($user->current_business_id)
            : $account->businesses()->where('is_active', true)->first();

        // Get subscription with plan
        $subscription = $account->subscription()->with('plan')->first();

        // Build allowance from plan limits
        $allowance = null;
        if ($subscription && $subscription->plan) {
            $plan = $subscription->plan;
            $allowance = [
                'plan' => $plan->name,
                'plan_code' => $plan->code,
                'workspaces' => [
                    'limit' => $plan->limit('workspaces'),
                    'used' => $account->workspaces()->count(),
                    'can_add' => $this->canAddWithinLimit(
                        $account->workspaces()->count(),
                        $plan->limit('workspaces')
                    ),
                ],
                'businesses' => [
                    'limit' => $plan->limit('businesses'),
                    'used' => $account->businesses()->count(),
                    'can_add' => $this->canAddWithinLimit(
                        $account->businesses()->count(),
                        $plan->limit('businesses')
                    ),
                ],
                'users' => [
                    'limit' => $plan->limit('users'),
                    'used' => $account->users()->count(),
                    'can_add' => $this->canAddWithinLimit(
                        $account->users()->count(),
                        $plan->limit('users')
                    ),
                ],
            ];
        }

        return [
            'auth' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'avatar' => $user->avatar,
                    'is_owner' => $user->is_owner,
                    'two_factor_enabled' => $user->two_factor_enabled,
                ],
            ],
            'tenant' => [
                'account' => [
                    'id' => $account->id,
                    'name' => $account->name,
                    'status' => $account->status,
                    'usable' => $account->isUsable(),
                    'on_trial' => $account->isOnTrial(),
                    'trial_ends_at' => $account->trial_ends_at?->toIso8601String(),
                    'onboarding_completed' => $account->onboarding_completed,
                    'onboarding_steps' => $account->onboarding_steps,
                ],
                'workspace' => $workspace ? [
                    'id' => $workspace->id,
                    'name' => $workspace->name,
                ] : null,
                'business' => $business ? [
                    'id' => $business->id,
                    'name' => $business->name,
                    'short_code' => $business->short_code,
                    'currency' => $business->base_currency,
                ] : null,
                'workspaces' => $account->workspaces->map(fn ($ws) => [
                    'id' => $ws->id,
                    'name' => $ws->name,
                ])->toArray(),
                'businesses' => $account->businesses->map(fn ($biz) => [
                    'id' => $biz->id,
                    'name' => $biz->name,
                    'short_code' => $biz->short_code,
                    'currency' => $biz->base_currency,
                    'workspace' => $biz->workspace_id,
                ])->toArray(),
                'allowance' => $allowance,
            ],
        ];
    }

    /**
     * Check if more items can be added within plan limit.
     */
    private function canAddWithinLimit(int $current, int $limit): bool
    {
        if ($limit === Plan::UNLIMITED) {
            return true;
        }

        return $current < $limit;
    }
}
