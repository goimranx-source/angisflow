<?php

declare(strict_types=1);

namespace App\Domain\Inbox;

use App\Domain\Delivery\Models\WebhookDelivery;
use App\Domain\Inbox\Adapters\GenericMessageAdapter;
use App\Domain\Inbox\Adapters\MessageAdapter;
use App\Domain\Inbox\Adapters\MetaAdapter;
use App\Domain\Inbox\Models\InboxChannel;
use App\Domain\Inbox\Models\Message;
use App\Domain\Tenancy\Models\Account;
use App\Domain\Tenancy\Models\Business;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Taking in what the messaging platforms send.
 *
 * ── Store first, parse second — the same rule as the couriers ────────────────
 *
 * Written down before anything tries to understand it, answered 200, then
 * parsed. A platform that renames a field must not turn into a 500 that
 * exhausts their retry queue and loses a day of customer messages. The stored
 * body is what makes that recoverable.
 *
 * ── Echoes are recorded, not discarded ───────────────────────────────────────
 *
 * An outbound message coming back could be our own agent's reply bouncing home,
 * or it could be somebody answering from the Facebook Page inbox on their
 * phone. The second genuinely belongs in the thread and is invisible to us
 * otherwise. So an echo is stored as an outbound message, and the unique index
 * on the platform's id makes the first case a silent no-op — one mechanism
 * handling both, with no heuristic to get wrong.
 */
final class MessageWebhookReceiver
{
    /** @var list<class-string<MessageAdapter>> */
    private const ADAPTERS = [
        MetaAdapter::class,
        GenericMessageAdapter::class,
    ];

    public function __construct(
        private readonly InboxService $inbox,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * Meta's GET handshake, answered before any message can ever arrive.
     *
     * @param  array<string, mixed>  $query
     */
    public function verifySubscription(string $channelPublicId, array $query): ?string
    {
        $channel = $this->channelFor($channelPublicId);

        return $channel === null
            ? null
            : $this->adapterFor($channel)->verifySubscription($channel, $query);
    }

    /**
     * @param  array<string, mixed>  $headers
     * @return array{delivery: WebhookDelivery, status: int, message: string}
     */
    public function receive(
        string $channelPublicId,
        string $body,
        array $headers = [],
        ?string $sourceIp = null,
    ): array {
        $delivery = WebhookDelivery::create([
            'endpoint' => "messages/{$channelPublicId}",
            'source_ip' => $sourceIp,
            'headers' => $this->safeHeaders($headers),
            'body' => $body,
            'received_at' => now(),
        ]);

        $channel = $this->channelFor($channelPublicId);

        if ($channel === null) {
            return $this->finish($delivery, WebhookDelivery::IGNORED, 404, 'No channel with that id', 'Unknown endpoint');
        }

        $delivery->forceFill([
            'account_id' => $channel->account_id,
            'business_id' => $channel->business_id,
        ])->save();

        $adapter = $this->adapterFor($channel);

        if (! $adapter->verifyWebhook($channel, $body, $this->stringHeaders($headers))) {
            return $this->finish($delivery, WebhookDelivery::REJECTED, 401, 'Signature did not match', 'Rejected');
        }

        $delivery->forceFill(['status' => WebhookDelivery::VERIFIED])->save();

        $payload = json_decode($body, true);

        if (! is_array($payload)) {
            // Several SMS gateways post a form rather than JSON, which is not
            // an error — it is how they have always worked.
            parse_str($body, $form);
            $payload = $form !== [] ? $form : null;
        }

        if (! is_array($payload)) {
            return $this->finish($delivery, WebhookDelivery::FAILED, 202, 'Body was not readable', 'Stored for review');
        }

        try {
            return $this->process($delivery, $channel, $adapter, $payload);
        } catch (Throwable $e) {
            Log::warning('Inbound message could not be processed', [
                'delivery' => $delivery->public_id,
                'channel' => $channel->public_id,
                'error' => $e->getMessage(),
            ]);

            $channel->forceFill(['last_error' => $e->getMessage()])->save();

            return $this->finish($delivery, WebhookDelivery::FAILED, 202, $e->getMessage(), 'Stored for review');
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{delivery: WebhookDelivery, status: int, message: string}
     */
    private function process(
        WebhookDelivery $delivery,
        InboxChannel $channel,
        MessageAdapter $adapter,
        array $payload,
    ): array {
        $events = $adapter->parseInbound($channel, $payload);

        if ($events === []) {
            return $this->finish($delivery, WebhookDelivery::PARSED, 200, null, 'Nothing to apply');
        }

        // No middleware has resolved a tenant for a request from Meta.
        $this->tenant->setAccount(Account::withoutGlobalScopes()->find($channel->account_id));
        $this->tenant->setBusiness(Business::withoutGlobalScopes()->find($channel->business_id));

        $applied = 0;

        foreach ($events as $event) {
            // One app can serve several of a subscriber's numbers or pages, so
            // a payload may be about a channel other than the one the URL
            // named. Matched on the platform's own id rather than assumed.
            $target = $this->resolveChannel($channel, $event['channel_external_id'] ?? null);

            if ($event['delivery_update'] !== null) {
                $applied += $this->applyDeliveryUpdate($target, $event) ? 1 : 0;

                continue;
            }

            if (($event['handle'] ?? '') === '') {
                continue;
            }

            if ($event['is_echo'] === true) {
                $applied += $this->applyEcho($target, $event) ? 1 : 0;

                continue;
            }

            $before = $this->inbox->threadFor($target, $event['handle'], [
                // Carried through, or every WhatsApp thread shows a phone
                // number where the platform told us a name. It arrives in a
                // separate array from the messages, which is exactly why it is
                // easy to drop.
                'contact_name' => $event['contact_name'] ?? null,
            ])->message_count;

            $message = $this->inbox->receive($target, $event['handle'], $event['body'], [
                'contact_name' => $event['contact_name'] ?? null,
                'external_id' => $event['external_id'] ?? null,
                'kind' => $event['kind'] ?? 'text',
                'occurred_at' => $event['occurred_at'] ?? null,
                'attachments' => $event['attachments'] ?? [],
                'payload' => $payload,
            ]);

            // Only count it if it actually landed. A replayed webhook returns
            // the message already stored, and reporting that as applied makes
            // a duplicate delivery indistinguishable from a real one in the
            // log somebody reads when a message goes missing.
            if ($message->conversation->refresh()->message_count > $before) {
                $applied++;
            }
        }

        $delivery->forceFill([
            'event_count' => count($events),
            'applied_count' => $applied,
        ])->save();

        return $this->finish($delivery, WebhookDelivery::PARSED, 200, null,
            sprintf('%d event(s), %d applied', count($events), $applied));
    }

    /**
     * A message somebody sent from the platform's own inbox.
     *
     * Stored as outbound and marked automated, because no agent typed it here
     * and counting it as a human reply would flatter every response-time
     * figure. The unique index makes our own echo a no-op.
     *
     * @param  array<string, mixed>  $event
     */
    private function applyEcho(InboxChannel $channel, array $event): bool
    {
        $conversation = $this->inbox->threadFor($channel, $event['handle']);

        $exists = Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('external_id', $event['external_id'])
            ->exists();

        if ($exists || ($event['external_id'] ?? null) === null) {
            return false;
        }

        $this->inbox->reply($conversation, (string) $event['body'], [
            'automated' => true,
            'sent_by' => null,
            'delivery_status' => 'sent',
            'already_sent' => true,
        ]);

        Message::query()
            ->where('conversation_id', $conversation->id)
            ->latest('id')
            ->limit(1)
            ->update(['external_id' => $event['external_id']]);

        return true;
    }

    /**
     * A read or delivery receipt for something we sent.
     *
     * @param  array<string, mixed>  $event
     */
    private function applyDeliveryUpdate(InboxChannel $channel, array $event): bool
    {
        $update = $event['delivery_update'];
        $id = $event['external_id'] ?? null;

        if ($id === null) {
            return false;
        }

        $message = Message::query()
            ->whereHas('conversation', fn ($q) => $q->where('inbox_channel_id', $channel->id))
            ->where('external_id', $id)
            ->first();

        if ($message === null) {
            return false;
        }

        $message->forceFill(array_filter([
            'delivery_status' => $update['status'],
            'failure_reason' => $update['error'] ?? null,
            'delivered_at' => $update['status'] === 'delivered' ? now() : $message->delivered_at,
            'read_at' => $update['status'] === 'read' ? now() : $message->read_at,
        ], fn ($v) => $v !== null))->save();

        return true;
    }

    private function resolveChannel(InboxChannel $fallback, ?string $externalId): InboxChannel
    {
        if ($externalId === null || $externalId === $fallback->external_id) {
            return $fallback;
        }

        return InboxChannel::query()
            ->withoutGlobalScopes()
            ->where('business_id', $fallback->business_id)
            ->where('external_id', $externalId)
            ->first() ?? $fallback;
    }

    private function channelFor(string $publicId): ?InboxChannel
    {
        return InboxChannel::query()
            ->withoutGlobalScopes()
            ->where('public_id', $publicId)
            ->first();
    }

    private function adapterFor(InboxChannel $channel): MessageAdapter
    {
        foreach (self::ADAPTERS as $class) {
            if (in_array($channel->kind, $class::handles(), true)) {
                return new $class;
            }
        }

        return new GenericMessageAdapter;
    }

    /**
     * @return array{delivery: WebhookDelivery, status: int, message: string}
     */
    private function finish(
        WebhookDelivery $delivery,
        string $status,
        int $code,
        ?string $reason,
        string $message,
    ): array {
        $delivery->forceFill([
            'status' => $status,
            'failure_reason' => $reason,
            'processed_at' => now(),
        ])->save();

        return ['delivery' => $delivery->refresh(), 'status' => $code, 'message' => $message];
    }

    /**
     * @param  array<string, mixed>  $headers
     * @return array<string, string>
     */
    private function safeHeaders(array $headers): array
    {
        $redact = ['authorization', 'x-api-key', 'x-signature', 'x-hub-signature', 'x-hub-signature-256', 'cookie'];
        $out = [];

        foreach ($this->stringHeaders($headers) as $key => $value) {
            $out[$key] = in_array($key, $redact, true) ? '[redacted]' : substr($value, 0, 500);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $headers
     * @return array<string, string>
     */
    private function stringHeaders(array $headers): array
    {
        $out = [];

        foreach ($headers as $key => $value) {
            $out[strtolower((string) $key)] = is_array($value) ? (string) ($value[0] ?? '') : (string) $value;
        }

        return $out;
    }
}
