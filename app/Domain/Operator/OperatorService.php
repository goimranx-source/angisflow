<?php

declare(strict_types=1);

namespace App\Domain\Operator;

use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Billing\PlanEntitlement;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Account;
use App\Domain\Tenancy\Models\Workspace;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Platform-level account management.
 *
 * Every action here is taken by an operator on behalf of a subscriber, not by
 * the subscriber themselves. The distinction matters for the audit trail and
 * for the error messages — "your account has been suspended" and "you suspended
 * this account" are different sentences for different readers.
 *
 * ── What operators can do ────────────────────────────────────────────────────
 *
 * - List and search all accounts
 * - View account detail (subscription, workspaces, user count)
 * - Suspend / unsuspend with a reason
 * - Extend a trial
 * - Change the plan (creates a new subscription row)
 * - Grant / revoke module keys for a specific workspace
 *
 * ── What operators cannot do here ────────────────────────────────────────────
 *
 * - Delete accounts (irreversible, needs a separate confirmation flow)
 * - Read subscriber data (ledger, orders, messages) — that is impersonation,
 *   which is a separate, audited action not built in this task
 */
class OperatorService
{
    /**
     * Paginated account list, newest first, with optional search.
     *
     * @return CursorPaginator<Account>
     */
    public function accounts(string $search = '', int $perPage = 50): CursorPaginator
    {
        $query = Account::withoutGlobalScopes()
            ->withTrashed()
            ->with(['subscription.plan:id,code,name'])
            ->orderByDesc('id');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        return $query->cursorPaginate($perPage);
    }

    /**
     * Full detail for one account.
     */
    public function accountDetail(string $publicId): array
    {
        $account = Account::withoutGlobalScopes()
            ->withTrashed()
            ->where('public_id', $publicId)
            ->with([
                'subscription.plan:id,code,name,price_minor,currency,interval',
                'workspaces' => fn ($q) => $q->withoutGlobalScopes()->withTrashed(),
            ])
            ->firstOrFail();

        $userCount = User::withoutGlobalScopes()
            ->where('account_id', $account->id)
            ->count();

        $workspaces = $account->workspaces->map(function (Workspace $ws) use ($account) {
            $overrides = EntitlementOverride::where('workspace_id', $ws->id)
                ->with('grantedBy:id,name')
                ->get()
                ->filter(fn ($o) => $o->isActive())
                ->map->toPayload()
                ->values();

            return [
                'id'        => $ws->public_id,
                'name'      => $ws->name,
                'is_active' => $ws->is_active,
                'overrides' => $overrides,
            ];
        });

        $sub = $account->subscription;

        return [
            'id'               => $account->public_id,
            'name'             => $account->name,
            'slug'             => $account->slug,
            'status'           => $account->status,
            'country'          => $account->country,
            'base_currency'    => $account->base_currency,
            'trial_ends_at'    => $account->trial_ends_at?->toIso8601String(),
            'suspended_at'     => $account->suspended_at?->toIso8601String(),
            'suspended_reason' => $account->suspended_reason,
            'created_at'       => $account->created_at?->toIso8601String(),
            'user_count'       => $userCount,
            'subscription'     => $sub ? [
                'status'             => $sub->status,
                'plan_code'          => $sub->plan?->code,
                'plan_name'          => $sub->plan?->name,
                'price_minor'        => $sub->plan?->price_minor,
                'currency'           => $sub->plan?->currency,
                'interval'           => $sub->plan?->interval,
                'trial_ends_at'      => $sub->trial_ends_at?->toIso8601String(),
                'current_period_end' => $sub->current_period_end?->toIso8601String(),
            ] : null,
            'workspaces' => $workspaces,
        ];
    }

    /**
     * Suspend an account. Idempotent — suspending an already-suspended account
     * updates the reason without changing the timestamp.
     */
    public function suspend(Account $account, string $reason, User $operator): void
    {
        if (empty($reason)) {
            throw new RuntimeException('A suspension reason is required — it is the only record of why this happened.');
        }

        $account->update([
            'status'           => Account::STATUS_SUSPENDED,
            'suspended_at'     => $account->suspended_at ?? now(),
            'suspended_reason' => $reason,
        ]);
    }

    /**
     * Lift a suspension and return the account to its previous billing status.
     */
    public function unsuspend(Account $account, User $operator): void
    {
        if ($account->suspended_at === null) {
            throw new RuntimeException("{$account->name} is not currently suspended.");
        }

        // Restore to the subscription's status, or trialing if there is none.
        $sub = Subscription::withoutGlobalScopes()
            ->where('account_id', $account->id)
            ->latest('id')
            ->first();

        $status = $sub?->isLive() ? Account::STATUS_ACTIVE : Account::STATUS_TRIALING;

        $account->update([
            'status'           => $status,
            'suspended_at'     => null,
            'suspended_reason' => null,
        ]);
    }

    /**
     * Push the trial end date forward.
     */
    public function extendTrial(Account $account, int $days, User $operator): void
    {
        if ($days < 1 || $days > 365) {
            throw new RuntimeException('Trial extension must be between 1 and 365 days.');
        }

        $current = $account->trial_ends_at ?? now();
        $newEnd  = $current->isFuture() ? $current->addDays($days) : now()->addDays($days);

        $account->update([
            'status'        => Account::STATUS_TRIALING,
            'trial_ends_at' => $newEnd,
        ]);
    }

    /**
     * Move an account to a different plan.
     *
     * Creates a new subscription row rather than updating the existing one,
     * so the history is preserved. The old subscription is left as-is; the
     * application always reads the latest one.
     */
    public function changePlan(Account $account, string $planCode, User $operator): Subscription
    {
        $plan = Plan::where('code', $planCode)->first();

        if ($plan === null) {
            throw new RuntimeException("No plan with code \"{$planCode}\" exists.");
        }

        $sub = Subscription::create([
            'account_id'           => $account->id,
            'plan_id'              => $plan->id,
            'status'               => Subscription::STATUS_ACTIVE,
            'started_at'           => now(),
            'current_period_start' => now(),
            'current_period_end'   => now()->addMonth(),
        ]);

        $account->update(['status' => Account::STATUS_ACTIVE]);

        PlanEntitlement::forgetAccount($account->id);

        return $sub;
    }

    /**
     * Grant a module key to a workspace regardless of plan.
     */
    public function grantModule(
        Workspace $workspace,
        string $moduleKey,
        string $reason,
        User $operator,
        ?\DateTimeInterface $expiresAt = null,
    ): EntitlementOverride {
        if (empty($reason)) {
            throw new RuntimeException('A reason is required for every entitlement override.');
        }

        $override = EntitlementOverride::updateOrCreate(
            ['workspace_id' => $workspace->id, 'module_key' => $moduleKey],
            [
                'account_id' => $workspace->account_id,
                'kind'       => EntitlementOverride::GRANT,
                'granted_by' => $operator->id,
                'reason'     => $reason,
                'expires_at' => $expiresAt,
            ],
        );

        PlanEntitlement::forgetAccount($workspace->account_id);

        return $override;
    }

    /**
     * Revoke a module key from a workspace regardless of plan.
     */
    public function revokeModule(
        Workspace $workspace,
        string $moduleKey,
        string $reason,
        User $operator,
    ): void {
        if (empty($reason)) {
            throw new RuntimeException('A reason is required for every entitlement override.');
        }

        EntitlementOverride::updateOrCreate(
            ['workspace_id' => $workspace->id, 'module_key' => $moduleKey],
            [
                'account_id' => $workspace->account_id,
                'kind'       => EntitlementOverride::REVOKE,
                'granted_by' => $operator->id,
                'reason'     => $reason,
                'expires_at' => null,
            ],
        );

        PlanEntitlement::forgetAccount($workspace->account_id);
    }

    /**
     * Remove an override entirely, restoring the plan's own answer.
     */
    public function removeOverride(Workspace $workspace, string $moduleKey): void
    {
        EntitlementOverride::where('workspace_id', $workspace->id)
            ->where('module_key', $moduleKey)
            ->delete();

        PlanEntitlement::forgetAccount($workspace->account_id);
    }

    /** All plans available to assign. */
    public function plans(): Collection
    {
        return Plan::orderBy('sort_order')->get();
    }
}
