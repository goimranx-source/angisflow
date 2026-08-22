<?php

declare(strict_types=1);

namespace App\Domain\Integrations;

use App\Domain\Integrations\Jobs\PushIntegrationRecord;
use App\Domain\Integrations\Models\Integration;
use App\Domain\Integrations\Models\IntegrationLink;
use App\Domain\Sales\Models\Order;

final class PushDispatcher
{
    public function __construct(private readonly QueueHeartbeat $workers) {}

    public function order(Order $order): void
    {
        $integrations = Integration::query()
            ->where('business_id', $order->business_id)
            ->where('bidirectional', true)
            ->where('is_active', true)
            ->get();

        foreach ($integrations as $integration) {
            $link = IntegrationLink::query()
                ->where('integration_id', $integration->id)
                ->where('entity', IntegrationLink::ORDER)
                ->where('linkable_id', $order->id)
                ->first();

            // A new order can only be sent to the store it belongs to. Existing
            // linked orders already identify their destination unambiguously.
            if ($link === null && $order->storefront_id !== null && $integration->storefront_id !== $order->storefront_id) {
                continue;
            }

            $this->send(new PushIntegrationRecord($integration->id, IntegrationLink::ORDER, (int) $order->id));
        }
    }

    /**
     * Hand the push to a worker, or carry it out ourselves.
     *
     * ── Why this is not simply dispatch() ────────────────────────────────────
     *
     * Because dispatch() reports success whether or not anything will ever run
     * the job. With no worker consuming the queue the row lands in the jobs
     * table, the request returns cleanly, and the change never reaches the shop
     * — which is exactly the failure this application shipped with. Nothing
     * about it is visible from either screen: the order says it was updated,
     * because here it was.
     *
     * A queue is still the right home for this when one is being worked. It
     * gives retries with backoff, isolation from the request, and a place for a
     * failure to be seen. So that is used whenever a worker has been heard from
     * — see QueueHeartbeat.
     *
     * ── And why after the response, rather than during it ────────────────────
     *
     * When there is no worker the job has to run in this process, and the only
     * question is when. Doing it inline would hold somebody's save open for as
     * long as their shop takes to answer — seconds, on a bad day more — to
     * accomplish something they are not waiting for. Laravel runs
     * after-response jobs once the response has been sent, which keeps the save
     * as quick as it was while still doing the work.
     *
     * The trade is retries: an after-response job that fails is not tried again.
     * That is the correct trade against not running at all, and the failure is
     * recorded rather than swallowed — the link keeps no last_pushed_at, which
     * is what a screen can show.
     */
    private function send(PushIntegrationRecord $job): void
    {
        if ($this->workers->alive()) {
            dispatch($job)->afterCommit();

            return;
        }

        dispatch($job)->afterResponse();
    }
}
