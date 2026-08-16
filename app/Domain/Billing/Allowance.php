<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Tenancy\Models\Account;
use App\Domain\Tenancy\Models\Business;
use App\Domain\Tenancy\Models\Workspace;
use Illuminate\Support\Facades\Cache;

/**
 * What this subscriber's plan lets them have, and how much of it is left.
 *
 * ── Why one place rather than a check at each call site ──────────────────────
 *
 * A limit enforced in the endpoint that creates the thing is a limit the next
 * endpoint forgets. Worse, the screen and the server then disagree: the button
 * is enabled because the client counted differently, the request fails, and the
 * subscriber concludes the tool is broken rather than that they need a bigger
 * plan. So the count, the ceiling, and the verdict are computed here, and both
 * the API that enforces it and the payload that draws the button read the same
 * answer.
 *
 * ── Why the counts are cached ────────────────────────────────────────────────
 *
 * This is read on every boot payload to decide whether "New workspace" is
 * enabled. Two COUNT queries on every page load, per subscriber, for a number
 * that changes only when somebody creates or deletes something, is work worth
 * not doing — so it is cached and dropped by the models themselves on write.
 */
class Allowance
{
    private const TTL = 300;

    /** No plan at all — a trial with nothing chosen yet. Deliberately generous
     *  on workspaces and tight on nothing: a trial that cannot be explored is a
     *  trial that does not convert. */
    private const TRIAL_LIMITS = [
        'workspaces' => 1,
        'businesses_per_workspace' => 2,
    ];

    public function __construct(private readonly Account $account) {}

    public static function for(Account $account): self
    {
        return new self($account);
    }

    private ?Plan $plan = null;

    private bool $planResolved = false;

    /**
     * The plan behind this account, or null while they are trialing without
     * having picked one.
     *
     * Read without the account scope and keyed explicitly by account_id. Going
     * through the scoped relation would work in a request and silently return
     * null in a queue worker or console command — where no tenant context is
     * set — which would quietly hand a paying subscriber the trial ceiling.
     * The account is already an explicit argument here; nothing about this
     * needs ambient state.
     */
    public function plan(): ?Plan
    {
        if (! $this->planResolved) {
            $this->plan = Subscription::withoutGlobalScopes()
                ->where('account_id', $this->account->id)
                ->latest('id')
                ->first()
                ?->plan;

            $this->planResolved = true;
        }

        return $this->plan;
    }

    public function limit(string $key): int
    {
        $plan = $this->plan();

        if ($plan === null) {
            return self::TRIAL_LIMITS[$key] ?? Plan::UNLIMITED;
        }

        return $plan->limit($key, self::TRIAL_LIMITS[$key] ?? Plan::UNLIMITED);
    }

    public function allows(string $feature): bool
    {
        // Without a plan every feature is open — this is a trial, and the point
        // of a trial is to see the thing you are being asked to pay for.
        return $this->plan()?->allows($feature) ?? true;
    }

    // ── Workspaces ──────────────────────────────────────────────────────────

    public function workspaceCount(): int
    {
        return Cache::remember(
            "allowance:{$this->account->id}:workspaces",
            self::TTL,
            fn () => Workspace::withoutGlobalScopes()
                ->where('account_id', $this->account->id)
                ->whereNull('deleted_at') // Exclude soft-deleted workspaces
                ->count(),
        );
    }

    public function canAddWorkspace(): bool
    {
        return $this->within($this->workspaceCount(), $this->limit('workspaces'));
    }

    // ── Businesses ──────────────────────────────────────────────────────────

    public function businessCount(int $workspaceId): int
    {
        return Cache::remember(
            "allowance:{$this->account->id}:businesses:{$workspaceId}",
            self::TTL,
            fn () => Business::withoutGlobalScopes()
                ->where('account_id', $this->account->id)
                ->where('workspace_id', $workspaceId)
                ->whereNull('deleted_at') // Exclude soft-deleted businesses
                ->count(),
        );
    }

    public function canAddBusiness(int $workspaceId): bool
    {
        return $this->within(
            $this->businessCount($workspaceId),
            $this->limit('businesses_per_workspace'),
        );
    }

    /**
     * What the shell needs to draw the create buttons honestly.
     *
     * @return array<string, mixed>
     */
    public function toPayload(?int $workspaceId = null): array
    {
        return [
            'plan' => $this->plan()?->code,
            'workspaces' => [
                'used' => $this->workspaceCount(),
                'limit' => self::publicLimit($this->limit('workspaces')),
                'can_add' => $this->canAddWorkspace(),
            ],
            'businesses' => $workspaceId === null ? null : [
                'used' => $this->businessCount($workspaceId),
                'limit' => self::publicLimit($this->limit('businesses_per_workspace')),
                'can_add' => $this->canAddBusiness($workspaceId),
            ],
        ];
    }

    /**
     * Unlimited as null rather than as a sentinel integer.
     *
     * -1 is meaningful here, where the constant is in scope. On the wire it is
     * a number that renders as "3 of -1" the moment anybody forgets to special-
     * case it — which is exactly what happened. null cannot be printed by
     * accident, and every client language already has a word for "no value".
     */
    private static function publicLimit(int $limit): ?int
    {
        return $limit === Plan::UNLIMITED ? null : $limit;
    }

    /**
     * Drop the cached counts for an account.
     *
     * Called by the models on write rather than by whoever did the writing —
     * the same reason account_id is stamped by a trait: a rule that depends on
     * being remembered is a rule that eventually is not.
     */
    public static function forget(int $accountId, ?int $workspaceId = null): void
    {
        Cache::forget("allowance:{$accountId}:workspaces");

        if ($workspaceId !== null) {
            Cache::forget("allowance:{$accountId}:businesses:{$workspaceId}");
        }
    }

    private function within(int $used, int $limit): bool
    {
        return $limit === Plan::UNLIMITED || $used < $limit;
    }
}
