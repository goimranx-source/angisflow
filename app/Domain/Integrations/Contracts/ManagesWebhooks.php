<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Contracts;

use App\Domain\Integrations\Models\Integration;
use App\Domain\Integrations\Support\RemoteWebhook;

/**
 * A platform whose webhooks we can set up for the person, rather than at them.
 *
 * ── Why this is a capability and not a page of instructions ──────────────────
 *
 * The alternative — and what this replaced — is telling somebody to open their
 * shop's admin, find Settings → Advanced → Webhooks, create one per event,
 * paste a URL, invent a secret, and paste that same secret back into a second
 * screen here. Every one of those steps is a place to make a typo that produces
 * no error and no orders, only silence.
 *
 * It is also a configuration that rots. The delivery URL changes when the
 * application moves; the shop disables a webhook of its own accord after a run
 * of failed deliveries and never mentions it. Both leave a connection that
 * still says "connected", still tests green, and has quietly stopped receiving
 * anything.
 *
 * Where a platform's API can create and repair its own webhooks — Woo and
 * Shopify both can — none of that needs to be a person's problem. Opt-in rather
 * than on PlatformDriver, for the same reason as PullsRecords: a platform that
 * cannot do this should not carry a method that throws.
 */
interface ManagesWebhooks
{
    /**
     * The events worth being told about, in this platform's own vocabulary.
     *
     * Deliberately not "everything the platform offers". Each one costs the
     * shop an HTTP call per occurrence, and an event nothing here acts on is
     * load on somebody's shop bought for nothing.
     *
     * @return list<string>
     */
    public function webhookTopics(): array;

    /**
     * Every webhook the shop currently has, ours and other people's.
     *
     * All of them, not just ours: telling the difference is the caller's job
     * and it does it by looking for this connection's token in the delivery
     * URL. A driver that filtered would have to know that rule, and would get
     * it wrong for the case that matters — a webhook of ours whose host has
     * changed and so no longer looks like ours at a glance.
     *
     * @return list<RemoteWebhook>
     */
    public function listWebhooks(Integration $integration): array;

    /** The new webhook, or null if the shop refused to make one. */
    public function createWebhook(Integration $integration, string $topic, string $url, string $secret): ?RemoteWebhook;

    /**
     * Change one that already exists.
     *
     * @param  array{url?: string, secret?: string, active?: bool}  $changes
     */
    public function updateWebhook(Integration $integration, string $id, array $changes): bool;
}
