<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Adapters;

use App\Domain\Inbox\Models\InboxChannel;
use App\Domain\Inbox\Models\Message;

/**
 * SMS, email, TikTok, and anything else.
 *
 * ── Why these share one adapter ──────────────────────────────────────────────
 *
 * Not because they are similar — they are not — but because what varies between
 * them is data rather than logic. An SMS gateway posts a form with `From` and
 * `Body`; an email relay posts one with `sender` and `text`; TikTok posts JSON
 * with its own names. In every case the job is the same: find the sender, find
 * the text, find the id.
 *
 * A named adapter per platform would be six copies of that with different
 * string constants, which is five more places to fix a bug. The field names
 * live in the channel's settings, so adding a gateway is filling in a form
 * rather than shipping a class.
 *
 * TikTok is here rather than in its own adapter for an honest reason: its
 * messaging API is limited, changes often, and is not available to most
 * merchants. When that changes it earns a class. Until then, pretending to
 * support it properly would be worse than routing it through something that
 * genuinely works for whatever it sends.
 */
final class GenericMessageAdapter implements MessageAdapter
{
    private const SENDER_KEYS = [
        'from', 'sender', 'msisdn', 'phone', 'phone_number', 'source',
        'user_id', 'sender_id', 'open_id', 'author', 'reply_to', 'email',
    ];

    private const BODY_KEYS = [
        'body', 'text', 'message', 'content', 'msg', 'message_text', 'plain',
    ];

    private const ID_KEYS = [
        'id', 'message_id', 'messageid', 'sid', 'msg_id', 'uuid', 'reference',
    ];

    private const NAME_KEYS = ['name', 'sender_name', 'from_name', 'nickname', 'display_name'];

    private const TIME_KEYS = ['timestamp', 'time', 'date', 'created_at', 'sent_at', 'occurred_at'];

    public static function handles(): array
    {
        return [InboxChannel::SMS, InboxChannel::EMAIL, InboxChannel::TIKTOK, 'generic'];
    }

    public function label(): string
    {
        return 'SMS, email or another gateway';
    }

    public function capabilities(): array
    {
        return [
            'free_reply' => true,
            'templates' => false,
            'attachments' => true,
            'read_receipts' => false,
            'typing' => false,
            'reply_window_hours' => null,
        ];
    }

    public function verifySubscription(InboxChannel $channel, array $query): ?string
    {
        return null;
    }

    public function verifyWebhook(InboxChannel $channel, string $body, array $headers): bool
    {
        $secret = $channel->webhook_secret;

        if ($secret === null || $secret === '') {
            // Deliberately permissive, unlike Meta. Plenty of SMS gateways
            // cannot sign at all, and refusing them would leave a subscriber
            // with a channel that receives nothing. The unguessable URL is the
            // protection, and the settings screen says so.
            return true;
        }

        foreach (['x-signature', 'x-webhook-signature', 'x-hub-signature-256', 'signature', 'x-api-key', 'authorization'] as $header) {
            $sent = trim(preg_replace('/^(sha256=|Bearer\s+)/i', '', (string) ($headers[$header] ?? '')) ?? '');

            if ($sent === '') {
                continue;
            }

            if (hash_equals($secret, $sent) || hash_equals(hash_hmac('sha256', $body, $secret), $sent)) {
                return true;
            }
        }

        return false;
    }

    public function parseInbound(InboxChannel $channel, array $payload): array
    {
        $map = $channel->settings['field_map'] ?? [];
        $out = [];

        foreach ($this->rowsIn($payload) as $row) {
            $flat = $this->flatten($row);

            $handle = $this->find($flat, $map['from'] ?? null, self::SENDER_KEYS);
            $body = $this->find($flat, $map['body'] ?? null, self::BODY_KEYS);

            // No sender means nothing can be answered, and a conversation keyed
            // on nothing would collect every unattributable message into one
            // thread.
            if ($handle === null || $handle === '') {
                continue;
            }

            $out[] = [
                'channel_external_id' => $channel->external_id,
                'handle' => $handle,
                'contact_name' => $this->find($flat, $map['name'] ?? null, self::NAME_KEYS),
                'external_id' => $this->find($flat, $map['id'] ?? null, self::ID_KEYS),
                'body' => $body,
                'kind' => 'text',
                'occurred_at' => $this->find($flat, $map['time'] ?? null, self::TIME_KEYS),
                'attachments' => [],
                'is_echo' => false,
                'delivery_update' => null,
            ];
        }

        return $out;
    }

    public function buildOutbound(InboxChannel $channel, string $handle, Message $message): array
    {
        $settings = $channel->settings ?? [];
        $map = $settings['send_map'] ?? [];

        return [
            'endpoint' => $settings['send_url'] ?? null,
            'method' => $settings['send_method'] ?? 'POST',
            'headers' => ['Content-Type' => 'application/json'],
            // Named the way the gateway wants, from the same settings that
            // taught it how to read inbound.
            'body' => [
                ($map['to'] ?? 'to') => $handle,
                ($map['body'] ?? 'body') => (string) $message->body,
                ...($settings['send_extra'] ?? []),
            ],
        ];
    }

    public function parseSendResult(array $response): array
    {
        $error = $response['error'] ?? $response['error_message'] ?? $response['errorMessage'] ?? null;

        if ($error !== null) {
            return [
                'external_id' => null,
                'status' => 'failed',
                'error' => is_string($error) ? $error : ($error['message'] ?? 'The gateway rejected it'),
            ];
        }

        $flat = $this->flatten($response);

        return [
            'external_id' => $this->find($flat, null, self::ID_KEYS),
            'status' => 'sent',
            'error' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function rowsIn(array $payload): array
    {
        if ($this->isBatch($payload)) {
            return array_values($payload);
        }

        foreach (['messages', 'events', 'data', 'items', 'results'] as $key) {
            $candidate = $payload[$key] ?? null;

            if (is_array($candidate) && $this->isBatch($candidate)) {
                return array_values($candidate);
            }
        }

        return [$payload];
    }

    /**
     * Every element an array, and the keys a list — the same check the courier
     * adapter needed, for the same reason: testing only the first element
     * mistakes a nested object for a batch.
     *
     * @param  array<mixed>  $value
     */
    private function isBatch(array $value): bool
    {
        if ($value === [] || ! array_is_list($value)) {
            return false;
        }

        foreach ($value as $row) {
            if (! is_array($row)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $flat
     * @param  list<string>  $candidates
     */
    private function find(array $flat, ?string $preferred, array $candidates): ?string
    {
        if ($preferred !== null && isset($flat[$preferred]) && ! is_array($flat[$preferred])) {
            return (string) $flat[$preferred];
        }

        $byLeaf = [];

        foreach ($flat as $path => $value) {
            if (is_array($value) || $value === null || $value === '') {
                continue;
            }

            $leaf = strtolower(str_contains($path, '.') ? substr(strrchr($path, '.'), 1) : $path);
            $byLeaf[$leaf] ??= $value;
        }

        foreach ($candidates as $name) {
            if (isset($byLeaf[$name])) {
                return (string) $byLeaf[$name];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function flatten(array $data, string $prefix = ''): array
    {
        $out = [];

        foreach ($data as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value) && $value !== [] && ! array_is_list($value)) {
                $out += $this->flatten($value, $path);

                continue;
            }

            $out[$path] = $value;
        }

        return $out;
    }
}
