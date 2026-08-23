<?php

declare(strict_types=1);

namespace App\Domain\Activity;

use App\Domain\Activity\Models\ActivityEvent;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Recording what happened, without making every save wait for it.
 *
 * ── Why events are buffered rather than written where they happen ────────────
 *
 * Because an audit trail that inserts a row inline is a second write on the
 * critical path of every first one. Change ten orders in a bulk action and that
 * is ten extra INSERTs, each with its own round trip, interleaved with the
 * writes that actually matter. It is the reason activity-log packages are the
 * first thing pulled out of a Laravel application once it gets busy — and the
 * reason the table this writes to was designed with the expectation that
 * nothing would write to it directly.
 *
 * So events are collected in memory and flushed once, as a single multi-row
 * INSERT, when the request ends. Ten orders cost one insert rather than ten.
 *
 * ── What happens if the flush never runs ─────────────────────────────────────
 *
 * The events are lost, and that is the right trade. History is a description of
 * work rather than the work itself: losing the note that an order's status
 * changed is a gap in a screen, while failing the status change because its
 * note could not be written is a broken application. Nothing here is ever
 * allowed to throw into a caller.
 *
 * ── What this is not ─────────────────────────────────────────────────────────
 *
 * Not a queue, and not durable. Anything that must survive a crash — money
 * moving, stock leaving a warehouse — belongs in a real table with a real
 * transaction, not in a description of one.
 */
final class Activity
{
    /** Subject types, kept short and stable — see the migration's reasoning. */
    public const ORDER = 'order';

    public const PRODUCT = 'product';

    /**
     * Events waiting to be written.
     *
     * @var list<array<string, mixed>>
     */
    private static array $pending = [];

    /**
     * A ceiling, because one request should not be able to hold an unbounded
     * amount of memory in descriptions of itself. An import touching fifty
     * thousand rows would otherwise buffer fifty thousand arrays before writing
     * any of them; past this the flush happens early and the buffer starts over.
     */
    private const FLUSH_AT = 500;

    /**
     * Note that something happened.
     *
     * @param  array<string, mixed>  $context  Whatever a person reading this
     *                                         line in a year would need.
     */
    public static function record(string $subjectType, int $subjectId, string $verb, array $context = []): void
    {
        try {
            $tenant = app(TenantContext::class);
            $account = $tenant->account();

            /*
             * No account, no event.
             *
             * Every read of this table is scoped by account, so a row without
             * one could never be shown to anybody — it would sit in the table
             * for ever, invisible and unbillable. That happens in console
             * commands and tests, where there is no tenant to speak of, and
             * silently dropping the note is better than writing rubbish.
             */
            if ($account === null) {
                return;
            }

            $now = now();

            self::$pending[] = [
                'account_id' => $account->id,
                'business_id' => $tenant->business()?->id,

                // Null when the platform did it rather than a person: a webhook,
                // a scheduled sync, a queued push. The distinction is the first
                // thing anybody asks of a line in a history.
                'actor_user_id' => auth()->id(),
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'verb' => mb_substr($verb, 0, 48),
                'context' => $context === [] ? null : json_encode($context),
                'occurred_at' => $now->format('Y-m-d H:i:s.v'),
                'occurred_on' => $now->toDateString(),
            ];

            if (count(self::$pending) >= self::FLUSH_AT) {
                self::flush();
            }
        } catch (\Throwable $e) {
            // Never into the caller. See the class comment: a missing line in a
            // history is a gap on a screen, and a save that fails because its
            // description could not be written is a broken application.
            Log::warning('Activity event dropped', ['verb' => $verb, 'message' => $e->getMessage()]);
        }
    }

    /**
     * Write everything buffered, as one insert.
     *
     * Called from a terminating callback, so it runs after the response has been
     * sent and costs the person waiting nothing at all.
     */
    public static function flush(): void
    {
        if (self::$pending === []) {
            return;
        }

        $rows = self::$pending;
        self::$pending = [];

        try {
            DB::table('activity_events')->insert($rows);
        } catch (\Throwable $e) {
            Log::warning('Activity events could not be written', [
                'count' => count($rows),
                'message' => $e->getMessage(),
            ]);
        }
    }

    /** How many events are waiting. For tests, and for nothing else. */
    public static function pendingCount(): int
    {
        return count(self::$pending);
    }

    /**
     * Everything that happened to one record, oldest first.
     *
     * @return \Illuminate\Support\Collection<int, ActivityEvent>
     */
    public static function for(string $subjectType, int $subjectId, int $limit = 200): \Illuminate\Support\Collection
    {
        /*
         * Flushed first, so a screen opened straight after a change shows that
         * change. Without this, saving an order and looking at its history in
         * the same breath shows the history as it was before the save — which
         * reads as the feature being broken rather than as a buffer being
         * patient.
         */
        self::flush();

        return ActivityEvent::query()
            ->forSubject($subjectType, $subjectId)
            ->limit($limit)
            ->get();
    }
}
