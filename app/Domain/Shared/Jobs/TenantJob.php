<?php

declare(strict_types=1);

namespace App\Domain\Shared\Jobs;

use App\Domain\Tenancy\Models\Account;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * The base every background job extends.
 *
 * ── Why work goes to a queue rather than being done in the request ───────────
 *
 * A request that does its work inline holds a PHP worker for as long as the
 * work takes. Ten users doing something slow at the same moment occupy ten
 * workers; a hundred occupy a hundred, and the hundred-and-first user waits
 * behind them for something entirely unrelated. That is how a busy afternoon
 * becomes an outage — not because the server ran out of capacity, but because
 * capacity was tied up in work nobody was waiting to see.
 *
 * Queued, the request writes a row and returns in milliseconds. The work is
 * then done by a pool of workers that can be scaled on its own, on machines
 * that are not serving anybody's screen, at whatever rate the database can
 * comfortably take. A spike lengthens the queue instead of lengthening every
 * page in the system.
 *
 * ── Serialising work on the same thing ───────────────────────────────────────
 *
 * Two people editing the same order at the same moment is the case that
 * silently corrupts data: both read, both compute, the second write overwrites
 * the first, and nothing anywhere reports it. `WithoutOverlapping` keyed on the
 * entity makes the second job wait for the first — so work on *one* order is
 * strictly ordered, while work on a million different orders still runs
 * completely in parallel. That distinction is the whole point: this serialises
 * per-entity, never globally.
 *
 * ── Tenancy ──────────────────────────────────────────────────────────────────
 *
 * A worker is a long-lived process handling jobs from every subscriber in turn.
 * It has no session and no request, so nothing has resolved an account — and if
 * a job simply inherited whatever the previous one left behind, one
 * subscriber's job would write into another's books. The account id travels
 * with the job and is re-established before handle() runs, then torn down after.
 */
abstract class TenantJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** Give up after this long, however many attempts are left. */
    public int $timeout = 120;

    /**
     * Stop retrying a job whose subscriber no longer exists rather than
     * failing it every few minutes for a week.
     */
    public bool $deleteWhenMissingModels = true;

    public function __construct(public readonly int $accountId) {}

    /**
     * Back off rather than hammering something that is already struggling.
     *
     * Three seconds, then fifteen, then a minute. A fixed one-second retry on a
     * database under load is how a brief wobble becomes a sustained outage: the
     * retries themselves become the load.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [3, 15, 60];
    }

    /**
     * The key work on the same thing queues behind.
     *
     * Return null — the default — and jobs run fully in parallel, which is
     * right for anything that touches nothing shared. Return something specific
     * ("order:5512") and every job with that key runs one at a time.
     */
    public function serialisationKey(): ?string
    {
        return null;
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        $key = $this->serialisationKey();

        if ($key === null) {
            return [];
        }

        return [
            // Scoped by account as well, so two subscribers whose entity ids
            // happen to collide do not queue behind each other.
            (new WithoutOverlapping("t{$this->accountId}:{$key}"))
                // Waits rather than skips: this job still has to happen, it
                // just has to happen second.
                ->releaseAfter(5)
                // The lock is dropped if a worker dies holding it, so one
                // crashed process cannot block an entity for ever.
                ->expireAfter(180),
        ];
    }

    /**
     * Adopt the subscriber this job belongs to, run, then put it back.
     *
     * Final on purpose. A subclass that overrode handle() would run outside the
     * tenancy this exists to establish, and the failure — writing into the
     * wrong account — is silent.
     */
    final public function handle(): void
    {
        $account = Account::withoutGlobalScopes()->find($this->accountId);

        if ($account === null) {
            // The subscriber was deleted between queueing and running. Nothing
            // to do, and nothing a retry could fix.
            $this->delete();

            return;
        }

        app(TenantContext::class)->runAs($account, fn () => $this->run($account));
    }

    /** The actual work. Runs with the tenant already established. */
    abstract protected function run(Account $account): void;

    /**
     * Where a job goes when it has run out of attempts.
     *
     * Reported rather than swallowed. A queue that quietly loses work is worse
     * than one that visibly backs up, because nobody finds out until somebody
     * asks why a figure is wrong.
     */
    public function failed(?Throwable $exception): void
    {
        report($exception ?? new \RuntimeException(static::class.' failed with no exception.'));
    }
}
