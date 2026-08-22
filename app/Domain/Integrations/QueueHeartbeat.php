<?php

declare(strict_types=1);

namespace App\Domain\Integrations;

use Illuminate\Contracts\Cache\Repository;

/**
 * Is anything actually consuming the queue?
 *
 * ── Why this needs asking at all ─────────────────────────────────────────────
 *
 * Queueing work is the right thing to do and says nothing about whether the
 * work happens. `dispatch()` succeeds identically whether a worker is running
 * or not: the row lands in the jobs table, the request returns, and the caller
 * is told everything went well. If nothing is consuming that table the job sits
 * there for ever, and the only visible symptom is that the thing the user asked
 * for did not occur.
 *
 * That is precisely how a shop stopped receiving order updates here — a change
 * made in this application queued a push, the push was never run, and both
 * screens went on reporting success.
 *
 * A worker announces itself on every poll of the queue, idle or not, so a
 * recent stamp is a reliable "somebody is listening". Its absence lets a caller
 * choose to do the work itself rather than hand it to nobody.
 *
 * ── Why a cache entry rather than a table ────────────────────────────────────
 *
 * Because it is a liveness signal, not a record. It is written on every poll —
 * several times a second under a running worker — and nothing needs its
 * history. A row would be a write amplification problem for a value whose only
 * question is "how old is this".
 */
final class QueueHeartbeat
{
    private const KEY = 'queue.worker.seen_at';

    /**
     * How long a stamp means anything.
     *
     * A worker polls far more often than this; the window is generous so that a
     * worker briefly busy with a long job is not mistaken for a dead one, and
     * mean enough that a worker stopped a few minutes ago is not trusted.
     */
    private const FRESH_SECONDS = 120;

    public function __construct(private readonly Repository $cache) {}

    /** Called by a running worker, on every poll. */
    public function beat(): void
    {
        $this->cache->put(self::KEY, time(), self::FRESH_SECONDS * 2);
    }

    /** Has a worker been heard from recently enough to hand it work? */
    public function alive(): bool
    {
        $seen = $this->cache->get(self::KEY);

        return is_int($seen) && (time() - $seen) <= self::FRESH_SECONDS;
    }
}
