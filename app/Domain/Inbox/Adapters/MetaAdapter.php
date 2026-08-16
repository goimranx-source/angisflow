<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Adapters;

use App\Domain\Inbox\Models\InboxChannel;
use App\Domain\Inbox\Models\Message;

/**
 * WhatsApp, Messenger and Instagram.
 *
 * ── Why one adapter for three products ───────────────────────────────────────
 *
 * They are three products with one gateway. All three arrive as a Graph API
 * webhook with the same envelope — object, entry[], changes[] or messaging[] —
 * signed the same way, sent the same way. What differs is the shape a few
 * levels down and the rules about when you may reply.
 *
 * Three adapters would mean three copies of the signature check, three copies
 * of the envelope walk, and three places to fix the next time Meta renames a
 * field. One adapter with three small branches is honest about how much is
 * actually shared.
 *
 * ── The three reply windows are the real difference ──────────────────────────
 *
 * WhatsApp gives 24 hours from the customer's last message, then templates
 * only. Messenger gives 24 with tags for a few exceptions. Instagram gives 7
 * days. Getting this wrong does not raise an error — the send is accepted and
 * the message is never delivered, which is the worst failure mode available.
 */
final class MetaAdapter implements MessageAdapter
{
    public static function handles(): array
    {
        return [
            InboxChannel::WHATSAPP,
            InboxChannel::MESSENGER,
            InboxChannel::INSTAGRAM,
        ];
    }

    public function label(): string
    {
        return 'WhatsApp, Messenger and Instagram';
    }

    public function capabilities(): array
    {
        return [
            'free_reply' => true,
            'templates' => true,
            'attachments' => true,
            'read_receipts' => true,
            'typing' => true,
            'reply_window_hours' => null,   // set per kind — see windowFor()
        ];
    }

    /** Hours of free-form reply, by product. */
    public function windowFor(string $kind): int
    {
        return match ($kind) {
            InboxChannel::INSTAGRAM => 24 * 7,
            default => 24,
        };
    }

    /**
     * Meta's subscription handshake.
     *
     * Nothing is delivered until this GET is answered with the challenge, which
     * makes it the commonest reason a freshly configured channel receives
     * silence rather than an error. The verify token is compared timing-safely
     * — it is a shared secret like any other.
     */
    public function verifySubscription(InboxChannel $channel, array $query): ?string
    {
        $mode = $query['hub_mode'] ?? $query['hub.mode'] ?? null;
        $token = $query['hub_verify_token'] ?? $query['hub.verify_token'] ?? null;
        $challenge = $query['hub_challenge'] ?? $query['hub.challenge'] ?? null;

        if ($mode !== 'subscribe' || $challenge === null) {
            return null;
        }

        $expected = ($channel->settings['verify_token'] ?? null) ?? $channel->webhook_secret;

        if ($expected === null || ! is_string($token) || ! hash_equals((string) $expected, $token)) {
            return null;
        }

        return (string) $challenge;
    }

    public function verifyWebhook(InboxChannel $channel, string $body, array $headers): bool
    {
        $secret = $channel->settings['app_secret'] ?? $channel->webhook_secret;

        if ($secret === null || $secret === '') {
            // No secret configured. Refused rather than waved through: unlike a
            // small courier, Meta can always sign, so a missing signature here
            // means a misconfiguration or somebody else's request.
            return false;
        }

        $sent = $headers['x-hub-signature-256'] ?? $headers['x-hub-signature'] ?? '';
        $sent = trim(preg_replace('/^sha(256|1)=/', '', (string) $sent) ?? '');

        if ($sent === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $body, (string) $secret), $sent)
            || hash_equals(hash_hmac('sha1', $body, (string) $secret), $sent);
    }

    public function parseInbound(InboxChannel $channel, array $payload): array
    {
        $out = [];

        foreach ($payload['entry'] ?? [] as $entry) {
            // WhatsApp puts messages under changes[].value; Messenger and
            // Instagram put them under messaging[]. Both are walked, because a
            // business with all three on one app receives both shapes here.
            foreach ($entry['changes'] ?? [] as $change) {
                $out = [...$out, ...$this->fromWhatsAppValue($change['value'] ?? [])];
            }

            foreach ($entry['messaging'] ?? [] as $event) {
                $item = $this->fromMessengerEvent($entry, $event);

                if ($item !== null) {
                    $out[] = $item;
                }
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $value
     * @return list<array<string, mixed>>
     */
    private function fromWhatsAppValue(array $value): array
    {
        $out = [];
        $phoneId = $value['metadata']['phone_number_id'] ?? null;

        // Names arrive in a separate array from the messages, keyed by number.
        $names = [];

        foreach ($value['contacts'] ?? [] as $contact) {
            $names[$contact['wa_id'] ?? ''] = $contact['profile']['name'] ?? null;
        }

        foreach ($value['messages'] ?? [] as $message) {
            $from = (string) ($message['from'] ?? '');
            $type = (string) ($message['type'] ?? 'text');

            $out[] = [
                'channel_external_id' => $phoneId,
                'handle' => $from,
                'contact_name' => $names[$from] ?? null,
                'external_id' => $message['id'] ?? null,
                'body' => $this->whatsappBody($message, $type),
                'kind' => $this->normaliseKind($type),
                'occurred_at' => isset($message['timestamp'])
                    ? date('Y-m-d H:i:s', (int) $message['timestamp'])
                    : null,
                'attachments' => $this->whatsappAttachments($message, $type),
                'is_echo' => false,
                'delivery_update' => null,
            ];
        }

        // Delivery and read receipts for messages we sent. Not messages, so
        // they carry no body — the receiver updates the existing row instead of
        // creating a new one, which is what delivery_update signals.
        foreach ($value['statuses'] ?? [] as $status) {
            $out[] = [
                'channel_external_id' => $phoneId,
                'handle' => (string) ($status['recipient_id'] ?? ''),
                'contact_name' => null,
                'external_id' => $status['id'] ?? null,
                'body' => null,
                'kind' => 'system',
                'occurred_at' => isset($status['timestamp'])
                    ? date('Y-m-d H:i:s', (int) $status['timestamp'])
                    : null,
                'attachments' => [],
                'is_echo' => false,
                'delivery_update' => [
                    'status' => match ($status['status'] ?? '') {
                        'delivered' => 'delivered',
                        'read' => 'read',
                        'failed' => 'failed',
                        default => 'sent',
                    },
                    'error' => $status['errors'][0]['title'] ?? null,
                ],
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>|null
     */
    private function fromMessengerEvent(array $entry, array $event): ?array
    {
        $message = $event['message'] ?? null;

        if ($message === null) {
            return null;
        }

        // Our own outbound message coming back. Flagged rather than dropped:
        // a message sent from the Facebook Page inbox by somebody on their
        // phone is also an echo, and it genuinely belongs in the thread.
        $isEcho = (bool) ($message['is_echo'] ?? false);

        // On an echo the customer is the recipient, not the sender.
        $handle = $isEcho
            ? (string) ($event['recipient']['id'] ?? '')
            : (string) ($event['sender']['id'] ?? '');

        return [
            'channel_external_id' => $entry['id'] ?? null,
            'handle' => $handle,
            'contact_name' => null,
            'external_id' => $message['mid'] ?? null,
            'body' => $message['text'] ?? null,
            'kind' => ($message['attachments'] ?? []) === [] ? 'text' : 'image',
            'occurred_at' => isset($event['timestamp'])
                ? date('Y-m-d H:i:s', (int) ($event['timestamp'] / 1000))
                : null,
            'attachments' => array_map(fn (array $a) => [
                'url' => $a['payload']['url'] ?? null,
                'mime_type' => null,
            ], $message['attachments'] ?? []),
            'is_echo' => $isEcho,
            'delivery_update' => null,
        ];
    }

    public function buildOutbound(InboxChannel $channel, string $handle, Message $message): array
    {
        $version = $channel->settings['graph_version'] ?? 'v21.0';
        $id = $channel->external_id;

        if ($channel->kind === InboxChannel::WHATSAPP) {
            return [
                'endpoint' => "https://graph.facebook.com/{$version}/{$id}/messages",
                'method' => 'POST',
                'headers' => ['Content-Type' => 'application/json'],
                'body' => $message->kind === 'template'
                    ? [
                        'messaging_product' => 'whatsapp',
                        'to' => $handle,
                        'type' => 'template',
                        // The name and language of an approved template, which
                        // the sender must supply — there is no way to invent
                        // one at send time.
                        'template' => $message->payload['template'] ?? ['name' => '', 'language' => ['code' => 'en']],
                    ]
                    : [
                        'messaging_product' => 'whatsapp',
                        'to' => $handle,
                        'type' => 'text',
                        'text' => ['body' => (string) $message->body],
                    ],
            ];
        }

        return [
            'endpoint' => "https://graph.facebook.com/{$version}/{$id}/messages",
            'method' => 'POST',
            'headers' => ['Content-Type' => 'application/json'],
            'body' => [
                'recipient' => ['id' => $handle],
                'message' => ['text' => (string) $message->body],
                'messaging_type' => 'RESPONSE',
            ],
        ];
    }

    public function parseSendResult(array $response): array
    {
        // Meta returns 200 with an error object often enough that trusting the
        // status code marks undelivered messages as sent.
        if (isset($response['error'])) {
            return [
                'external_id' => null,
                'status' => 'failed',
                'error' => $response['error']['message'] ?? 'The platform rejected it',
            ];
        }

        return [
            'external_id' => $response['messages'][0]['id'] ?? $response['message_id'] ?? null,
            'status' => 'sent',
            'error' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function whatsappBody(array $message, string $type): ?string
    {
        return match ($type) {
            'text' => $message['text']['body'] ?? null,
            'button' => $message['button']['text'] ?? null,
            'interactive' => $message['interactive']['button_reply']['title']
                ?? $message['interactive']['list_reply']['title']
                ?? null,
            'location' => trim(sprintf(
                '%s %s',
                $message['location']['name'] ?? '',
                $message['location']['address'] ?? '',
            )) ?: 'Shared a location',
            // A caption where there is one, so a photo with "is this the right
            // size?" does not arrive in the inbox as an empty bubble.
            default => $message[$type]['caption'] ?? null,
        };
    }

    /**
     * @param  array<string, mixed>  $message
     * @return list<array<string, mixed>>
     */
    private function whatsappAttachments(array $message, string $type): array
    {
        if (! in_array($type, ['image', 'audio', 'video', 'document', 'sticker'], true)) {
            return [];
        }

        $media = $message[$type] ?? [];

        return [[
            // WhatsApp gives a media id, not a URL — the file has to be fetched
            // in a second authenticated call. Recorded as an id so the fetcher
            // knows which kind of lookup it is doing.
            'media_id' => $media['id'] ?? null,
            'url' => null,
            'mime_type' => $media['mime_type'] ?? null,
            'filename' => $media['filename'] ?? null,
        ]];
    }

    private function normaliseKind(string $type): string
    {
        return match ($type) {
            'image', 'sticker' => 'image',
            'audio', 'voice' => 'audio',
            'video' => 'video',
            'document' => 'file',
            'location' => 'location',
            default => 'text',
        };
    }
}
