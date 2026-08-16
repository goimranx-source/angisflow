<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use App\Domain\Tenancy\Models\Account;
use App\Domain\Tenancy\Models\Business;
use App\Domain\Tenancy\Models\Workspace;
use RuntimeException;

/**
 * Which subscriber's data this request is allowed to touch.
 *
 * ── The shape, and why this one ──────────────────────────────────────────────
 *
 * There are three ways to keep subscribers apart, and only one of them survives
 * the numbers this is built for.
 *
 *   A database each      Clean isolation, and completely impractical: a million
 *                        subscribers is a million schemas to migrate, a million
 *                        connection pools, and a deploy that takes a week.
 *
 *   A schema each        The same problem wearing a hat.
 *
 *   One schema, a key    Every row carries account_id, every index leads with
 *                        it. One migration, one pool, and the database only
 *                        ever reads the slice of an index that belongs to one
 *                        subscriber — which is why a table with a billion rows
 *                        answers a tenant's query as fast as a table with a
 *                        thousand.
 *
 * The third is what large multi-tenant products actually run, and it is what
 * this does. The cost is that isolation is now a discipline rather than a wall,
 * so it is enforced in one place — the BelongsToAccount trait — rather than
 * remembered at each query. Forgetting a where clause is not possible; you
 * would have to deliberately call withoutTenancy().
 *
 * ── Why a container singleton and not a static ───────────────────────────────
 *
 * Queue workers and Octane keep the process alive between jobs. A static would
 * leak one subscriber's context into the next job on the same worker, which is
 * the worst bug this system could have. Bound per-container, it dies with the
 * request, and a job that needs tenancy has to say so.
 */
final class TenantContext
{
    private ?Account $account = null;

    private ?Business $business = null;

    private ?Workspace $workspace = null;

    /** Set when a deliberate escape hatch is open — see runWithout(). */
    private bool $suspended = false;

    public function setAccount(?Account $account): void
    {
        $this->account = $account;

        // A business belonging to the previous account must never survive the
        // switch: that is precisely how one subscriber sees another's figures.
        if ($this->business && $this->business->account_id !== $account?->id) {
            $this->business = null;
        }

        if ($this->workspace && $this->workspace->account_id !== $account?->id) {
            $this->workspace = null;
        }
    }

    public function setWorkspace(?Workspace $workspace): void
    {
        if ($workspace && $this->account && $workspace->account_id !== $this->account->id) {
            throw new RuntimeException('That workspace does not belong to the current account.');
        }

        $this->workspace = $workspace;

        // Selecting a different workspace cannot leave the previous one's
        // business selected — every figure on screen would then belong to a
        // workspace the header no longer names.
        if ($this->business && $workspace && $this->business->workspace_id !== $workspace->id) {
            $this->business = null;
        }
    }

    public function setBusiness(?Business $business): void
    {
        if ($business && $this->account && $business->account_id !== $this->account->id) {
            throw new RuntimeException('That business does not belong to the current account.');
        }

        $this->business = $business;

        // The workspace follows the business rather than being set separately:
        // a business belongs to exactly one, so asking the caller to keep the
        // two in step is asking them to get it wrong eventually.
        if ($business && $business->workspace_id !== $this->workspace?->id) {
            $this->workspace = $business->relationLoaded('workspace')
                ? $business->workspace
                : Workspace::query()->find($business->workspace_id);
        }
    }

    public function account(): ?Account
    {
        return $this->account;
    }

    public function business(): ?Business
    {
        return $this->business;
    }

    public function workspace(): ?Workspace
    {
        return $this->workspace;
    }

    public function workspaceId(): ?int
    {
        return $this->workspace?->id;
    }

    public function accountId(): ?int
    {
        return $this->account?->id;
    }

    public function businessId(): ?int
    {
        return $this->business?->id;
    }

    public function hasAccount(): bool
    {
        return $this->account !== null;
    }

    /** The id, or a refusal — for code paths where operating tenant-less is a bug. */
    public function requireAccountId(): int
    {
        return $this->account?->id
            ?? throw new RuntimeException(
                'No account is set on this request, so there is no data to read. '
                .'A console command or queued job must set the tenant explicitly.'
            );
    }

    public function isSuspended(): bool
    {
        return $this->suspended;
    }

    /**
     * Run something with tenant scoping off — migrations, the future admin
     * panel, a cross-account report.
     *
     * Deliberately a closure rather than a flag somebody can set and forget:
     * the scope comes back on when the callback returns, including when it
     * throws.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function runWithout(callable $callback): mixed
    {
        $was = $this->suspended;
        $this->suspended = true;

        try {
            return $callback();
        } finally {
            $this->suspended = $was;
        }
    }

    /**
     * Run something as a given account, then put everything back.
     *
     * How a queued job adopts the tenancy of whoever queued it.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function runAs(Account $account, callable $callback): mixed
    {
        $wasAccount = $this->account;
        $wasBusiness = $this->business;

        $this->account = $account;
        $this->business = null;

        try {
            return $callback();
        } finally {
            $this->account = $wasAccount;
            $this->business = $wasBusiness;
        }
    }
}
