<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Integrations\Jobs\PushIntegrationRecord;
use App\Domain\Integrations\Models\Integration;
use App\Domain\Integrations\Models\IntegrationLink;
use Illuminate\Console\Command;

/**
 * Re-send the changes that never left.
 *
 * ── What this is for, and what it is deliberately not for ────────────────────
 *
 * A link marked push_pending_at with no push_error is a change that was written
 * here and then simply never delivered: the queue had no worker, the job was
 * lost, the process died between the save and the dispatch. Nothing failed —
 * nothing ran. There is no retry to trigger, because there was no attempt, and
 * so nothing in the queue will ever pick it up again on its own.
 *
 * Those are exactly the ones worth sweeping up automatically, and they are the
 * ones that produced every silent divergence this application has had.
 *
 * A link that carries push_error is left alone. The shop was asked and refused
 * — a rejected line item, an order it will not reopen — and asking again on a
 * timer just means being refused on a timer. That one wants a person, which is
 * why the row shows a warning rather than a spinner.
 *
 * ── Why an age threshold ─────────────────────────────────────────────────────
 *
 * Because a push queued a moment ago is not late, it is queued. Sweeping
 * immediately would duplicate work already in flight for every bulk change on
 * the system. Anything still owed several minutes later is genuinely stuck.
 */
final class RetryUnsentPushes extends Command
{
    protected $signature = 'integrations:retry-pushes
                            {--minutes=5 : How long a change must have been owed before it counts as stuck}
                            {--limit=200 : Most links to re-queue in one pass}
                            {--include-failed : Also re-send ones the shop refused, which normally need a person}';

    protected $description = 'Re-queue local changes that never reached their shop';

    public function handle(): int
    {
        $cutoff = now()->subMinutes((int) $this->option('minutes'));

        $links = IntegrationLink::withoutGlobalScopes()
            /*
             * Every kind of record, not only orders.
             *
             * This was written when orders were the only thing that pushed, and
             * silently stopped covering the catalogue the moment products began
             * to. A product whose push never ran would have stayed owed for
             * ever with a sweep running every five minutes past it.
             *
             * The job takes the entity as a parameter, so nothing else here has
             * to know which kinds exist.
             */
            ->whereIn('entity', [IntegrationLink::ORDER, IntegrationLink::PRODUCT])
            ->whereNotNull('push_pending_at')
            ->where('push_pending_at', '<=', $cutoff)
            ->when(! $this->option('include-failed'), fn ($q) => $q->whereNull('push_error'))
            ->orderBy('push_pending_at')
            ->limit((int) $this->option('limit'))
            ->get();

        if ($links->isEmpty()) {
            $this->info('Nothing is owed to a shop.');

            return self::SUCCESS;
        }

        /*
         * Integrations read once and kept, rather than per link. A stuck batch
         * is usually one shop's worth of orders, so this is one query instead
         * of two hundred identical ones.
         */
        $usable = Integration::withoutGlobalScopes()
            ->whereIn('id', $links->pluck('integration_id')->unique())
            ->get()
            ->filter(fn (Integration $i): bool => $i->isUsable() && $i->bidirectional)
            ->keyBy('id');

        $sent = 0;
        $skipped = 0;

        foreach ($links as $link) {
            if (! $usable->has($link->integration_id)) {
                // The shop was disconnected or paused since the change was
                // made. Not stuck — no longer wanted.
                $skipped++;

                continue;
            }

            dispatch(new PushIntegrationRecord(
                (int) $link->integration_id,
                (string) $link->entity,
                (int) $link->linkable_id,
            ));

            $sent++;
        }

        $this->info("Re-queued {$sent} change".($sent === 1 ? '' : 's').' that had not reached a shop.');

        if ($skipped > 0) {
            $this->line("Skipped {$skipped} for shops that are no longer connected.");
        }

        return self::SUCCESS;
    }
}
