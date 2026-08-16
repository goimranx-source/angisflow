<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Adapters;

use App\Domain\Inbox\Models\InboxChannel;
use App\Domain\Inbox\Models\Message;

/**
 * How to talk to one messaging platform.
 *
 * ── The same contract shape as the couriers, deliberately ────────────────────
 *
 * Verify a webhook, parse it into canonical events, build an outbound payload.
 * Capabilities declared rather than assumed, because the platforms differ in
 * ways that matter to the person typing: some allow free-form replies at any
 * time, some only inside a window, some only via a template somebody else
 * approved a fortnight ago.
 *
 * An interface that assumed the loosest of those would have agents writing
 * replies that silently never arrive.
 *
 * ── Adapters translate; they never write ─────────────────────────────────────
 *
 * Turning a payload into a canonical message is this layer's job. Deciding
 * whether it is an echo, which conversation it belongs to, and whether it
 * starts the response clock belongs to InboxService, which knows about all
 * three. An adapter that saved would have to duplicate that reasoning, and the
 * duplicate would drift.
 */
interface MessageAdapter
{
    /** Channel kinds this adapter claims. */
    public static function handles(): array;

    public function label(): string;

    /**
     * What this platform actually permits.
     *
     * @return array{
     *   free_reply: bool, templates: bool, attachments: bool,
     *   read_receipts: bool, typing: bool, reply_window_hours: ?int
     * }
     */
    public function capabilities(): array;

    /**
     * Answer a platform's subscription handshake, if it has one.
     *
     * Meta will not deliver a single webhook until you have echoed back a
     * challenge string from a GET request — a step that has nothing to do with
     * messages and blocks everything until it is done. Returning null means
     * this platform has no such dance.
     *
     * @param  array<string, mixed>  $query
     */
    public function verifySubscription(InboxChannel $channel, array $query): ?string;

    /**
     * Prove an inbound request came from the platform.
     *
     * @param  array<string, string>  $headers
     */
    public function verifyWebhook(InboxChannel $channel, string $body, array $headers): bool;

    /**
     * Turn one payload into the messages inside it.
     *
     * A list, because these platforms batch: a single request routinely carries
     * several messages, for several conversations, sometimes across several of
     * the subscriber's own accounts.
     *
     * @param  array<string, mixed>  $payload
     * @return list<array{
     *   channel_external_id: ?string, handle: string, contact_name: ?string,
     *   external_id: ?string, body: ?string, kind: string,
     *   occurred_at: ?string, attachments: list<array<string, mixed>>,
     *   is_echo: bool, delivery_update: ?array<string, mixed>
     * }>
     */
    public function parseInbound(InboxChannel $channel, array $payload): array;

    /**
     * Build what this platform expects for an outbound message.
     *
     * @return array{endpoint: ?string, method: string, body: array<string, mixed>, headers: array<string, string>}
     */
    public function buildOutbound(InboxChannel $channel, string $handle, Message $message): array;

    /**
     * Read the platform's response to a send, so a failure is a failure.
     *
     * A 200 with an error object in the body is common enough that treating the
     * status code as the answer marks undelivered messages as sent.
     *
     * @param  array<string, mixed>  $response
     * @return array{external_id: ?string, status: string, error: ?string}
     */
    public function parseSendResult(array $response): array;
}
