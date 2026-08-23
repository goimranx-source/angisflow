<?php

declare(strict_types=1);

namespace App\Domain\Integrations;

use App\Domain\Catalogue\Models\Product;
use App\Domain\Integrations\Jobs\PushIntegrationRecord;
use App\Domain\Integrations\Models\Integration;
use App\Domain\Integrations\Models\IntegrationLink;
use App\Domain\Sales\Models\Order;

final class PushDispatcher
{
    public function __construct(private readonly QueueHeartbeat $workers) {}

    /**
     * The pushes this order needs, without sending any of them.
     *
     * ── Why a caller would want them un-sent ─────────────────────────────────
     *
     * So that a bulk change can put them all in one batch. Sent one at a time
     * they are unrelated jobs: nothing can say how many there were, how many
     * are done, or whether the whole thing finished — which is exactly what
     * somebody who just changed two hundred orders wants to know, and the
     * reason they otherwise sit and watch a spinner instead of getting on with
     * their work.
     *
     * @return list<PushIntegrationRecord>
     */
    public function jobsFor(Order $order): array
    {
        $jobs = [];

        foreach ($this->integrationsFor($order) as $integration) {
            /*
             * The debt is written here, before anything is sent.
             *
             * ── Why not when the send fails ──────────────────────────────────
             *
             * Because the sends that hurt are the ones that never happen. A
             * queue with no worker, a batch that throws after the orders are
             * already saved, a process killed mid-request — none of those
             * produce a failure to record, and all three have left this
             * application quietly disagreeing with a shop.
             *
             * Marked first, the order carries "the shop has not taken this"
             * from the moment it changes until a push actually succeeds. The
             * worst case becomes a visible backlog instead of silence.
             *
             * A link that does not exist yet is not marked: there is nothing to
             * mark, and the push will create it.
             */
            IntegrationLink::query()
                ->where('integration_id', $integration->id)
                ->where('entity', IntegrationLink::ORDER)
                ->where('linkable_id', $order->id)
                ->first()
                ?->markPushPending();

            $jobs[] = new PushIntegrationRecord($integration->id, IntegrationLink::ORDER, (int) $order->id);
        }

        return $jobs;
    }

    /** Whether a worker is available, so a caller can choose how to send. */
    public function hasWorker(): bool
    {
        return $this->workers->alive();
    }

    public function order(Order $order): void
    {
        foreach ($this->jobsFor($order) as $job) {
            $this->send($job);
        }
    }

    /**
     * The pushes a product needs, without sending them.
     *
     * ── Why a product is not routed like an order ────────────────────────────
     *
     * An order belongs to exactly one shop — the one it was placed in — so a
     * push has one destination and sending it anywhere else would be inventing
     * an order in a shop that never took it.
     *
     * A product is the opposite: the same thing can be listed in every shop a
     * business runs, and each listing is its own row in integration_links.
     * Changing the price here means changing it everywhere it is sold, so this
     * goes to every connected shop rather than to one.
     *
     * @return list<PushIntegrationRecord>
     */
    public function jobsForProduct(Product $product): array
    {
        $jobs = [];

        $integrations = Integration::query()
            ->where('business_id', $product->business_id)
            ->where('bidirectional', true)
            ->where('is_active', true)
            ->get();

        foreach ($integrations as $integration) {
            // The debt, before the attempt — see jobsFor(). A product with no
            // link yet has nothing to mark; the push will create the listing
            // and the link with it.
            IntegrationLink::query()
                ->where('integration_id', $integration->id)
                ->where('entity', IntegrationLink::PRODUCT)
                ->where('linkable_id', $product->id)
                ->first()
                ?->markPushPending();

            $jobs[] = new PushIntegrationRecord($integration->id, IntegrationLink::PRODUCT, (int) $product->id);
        }

        return $jobs;
    }

    public function product(Product $product): void
    {
        foreach ($this->jobsForProduct($product) as $job) {
            $this->send($job);
        }
    }

    /**
     * The shops this order should be sent to.
     *
     * @return iterable<Integration>
     */
    private function integrationsFor(Order $order): iterable
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

            yield $integration;
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
