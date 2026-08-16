<?php

declare(strict_types=1);

namespace App\Domain\Inbox;

use App\Domain\Inbox\Models\Conversation;
use App\Domain\Inbox\Models\InboxChannel;
use App\Domain\Inbox\Models\Message;
use App\Domain\Inbox\Models\MessageAttachment;
use App\Domain\Sales\CustomerDirectory;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Everything anybody said, in one place.
 *
 * ── The echo problem, and why the fix is a unique index ──────────────────────
 *
 * Every messaging platform sends your own outbound message back as an inbound
 * webhook. Handled naively, each agent reply appears twice, unread counts never
 * clear, and an auto-reply rule answers itself until the account is rate
 * limited.
 *
 * The instinct is a comparison — same text, roughly the same time, must be
 * ours. It is fragile in exactly the case it matters: two customers sending
 * "ok" within a minute. The reliable answer is the platform's own message id,
 * unique per conversation, so an echo or a retried webhook finds the row
 * already there and stops. No heuristics, no window, nothing to tune.
 *
 * ── The response clock runs on human replies ─────────────────────────────────
 *
 * An automated acknowledgement is not an answer, and neither is an internal
 * note. Counting them produces a response-time figure that looks excellent and
 * describes nothing — the worst kind of wrong, because nobody investigates a
 * good number.
 */
final class InboxService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly CustomerDirectory $customers,
    ) {}

    /**
     * Record something a customer said.
     *
     * Idempotent on the platform's id. Safe to call with the same webhook
     * twenty times.
     *
     * @param  array<string, mixed>  $options
     */
    public function receive(
        InboxChannel $channel,
        string $handle,
        ?string $body,
        array $options = [],
    ): Message {
        $externalId = $options['external_id'] ?? null;
        $occurredAt = isset($options['occurred_at']) ? Carbon::parse($options['occurred_at']) : now();

        $conversation = $this->threadFor($channel, $handle, $options);

        // The echo and the retry both stop here.
        if ($externalId !== null) {
            $existing = Message::query()
                ->where('conversation_id', $conversation->id)
                ->where('external_id', $externalId)
                ->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        return DB::transaction(function () use ($conversation, $channel, $body, $options, $externalId, $occurredAt) {
            $message = Message::create([
                'conversation_id' => $conversation->id,
                'direction' => Message::IN,
                'kind' => $options['kind'] ?? 'text',
                'body' => $body,
                'external_id' => $externalId,
                'external_reply_to' => $options['reply_to'] ?? null,
                'occurred_at' => $occurredAt,
                'payload' => $options['payload'] ?? null,
            ]);

            foreach ($options['attachments'] ?? [] as $attachment) {
                MessageAttachment::create([
                    'message_id' => $message->id,
                    'remote_url' => $attachment['url'] ?? null,
                    'filename' => $attachment['filename'] ?? null,
                    'mime_type' => $attachment['mime_type'] ?? null,
                    'bytes' => $attachment['bytes'] ?? null,
                ]);
            }

            $this->afterInbound($conversation, $message);

            $channel->forceFill(['last_seen_at' => now()])->save();

            return $message->refresh();
        });
    }

    /**
     * Send a reply.
     *
     * Refuses outside the platform's window rather than queueing something that
     * will be silently dropped. An agent who is told now can use a template;
     * one who finds out tomorrow has lost the customer.
     */
    public function reply(Conversation $conversation, string $body, array $options = []): Message
    {
        $conversation->loadMissing('channel');

        $internal = $options['internal'] ?? false;

        // An echo is a record of something that has already been sent — from
        // the platform's own inbox, by somebody on their phone. Checking the
        // reply window against it asks whether we may do a thing that has
        // already happened, and refusing loses the message entirely.
        $isRecord = $options['already_sent'] ?? false;

        if (! $internal && ! $isRecord && ! $conversation->canReplyFreely() && ! ($options['template'] ?? false)) {
            throw new RuntimeException(sprintf(
                'The %s reply window closed. Send an approved template, or wait for them to message again.',
                $conversation->channel?->kind ?? 'channel',
            ));
        }

        return DB::transaction(function () use ($conversation, $body, $options, $internal) {
            $message = Message::create([
                'conversation_id' => $conversation->id,
                'direction' => Message::OUT,
                'kind' => $options['kind'] ?? ($options['template'] ?? false ? 'template' : 'text'),
                'body' => $body,
                'sent_by' => $options['sent_by'] ?? auth()->id(),
                'is_automated' => $options['automated'] ?? false,
                'is_internal' => $internal,
                'delivery_status' => $internal ? 'sent' : ($options['delivery_status'] ?? 'queued'),
                'occurred_at' => now(),
            ]);

            $this->afterOutbound($conversation, $message);

            return $message->refresh();
        });
    }

    /**
     * Find or open the thread a handle belongs to.
     *
     * Also the moment a channel handle becomes a customer identity — which is
     * what makes a WhatsApp number find the person who bought at the till last
     * month, instead of creating a stranger.
     */
    public function threadFor(InboxChannel $channel, string $handle, array $options = []): Conversation
    {
        $conversation = Conversation::query()
            ->where('inbox_channel_id', $channel->id)
            ->where('contact_handle', $handle)
            ->first();

        if ($conversation !== null) {
            if (($options['contact_name'] ?? null) !== null && $conversation->contact_name === null) {
                $conversation->forceFill(['contact_name' => $options['contact_name']])->save();
            }

            return $conversation;
        }

        return DB::transaction(function () use ($channel, $handle, $options) {
            $kind = $channel->identityKind();

            // Only a strong match reuses a customer — the same restraint as
            // everywhere else. A phone number alone is a weak signal, and
            // wrongly attaching a conversation to somebody's record is worse
            // than leaving it unattached.
            $customer = null;

            foreach ($this->customers->identify([$kind => $handle]) as $candidate) {
                if ($candidate['confidence'] === 'strong') {
                    $customer = $candidate['customer'];

                    break;
                }
            }

            $conversation = Conversation::create([
                'inbox_channel_id' => $channel->id,
                'customer_id' => $customer?->id,
                'contact_handle' => $handle,
                'contact_name' => $options['contact_name'] ?? null,
                'external_id' => $options['thread_id'] ?? null,
            ]);

            // Recorded whether or not it matched, so the next channel this
            // person appears on can find them.
            if ($customer !== null) {
                try {
                    $this->customers->addIdentity($customer, $kind, $handle);
                } catch (RuntimeException) {
                    // Already somebody else's. Left alone deliberately.
                }
            }

            return $conversation;
        });
    }

    /** Attach a conversation to a customer, and remember the handle. */
    public function link(Conversation $conversation, \App\Domain\Sales\Models\Customer $customer): Conversation
    {
        $conversation->loadMissing('channel');

        return DB::transaction(function () use ($conversation, $customer) {
            $conversation->forceFill(['customer_id' => $customer->id])->save();

            try {
                $this->customers->addIdentity(
                    $customer,
                    $conversation->channel?->identityKind() ?? 'external',
                    $conversation->contact_handle,
                );
            } catch (RuntimeException) {
                // Held by another customer — a merge, not a side effect.
            }

            return $conversation->refresh();
        });
    }

    public function assign(Conversation $conversation, ?int $userId): Conversation
    {
        $conversation->forceFill([
            'assigned_to' => $userId,
            'assigned_at' => $userId === null ? null : now(),
            'status' => $conversation->status === Conversation::CLOSED
                ? Conversation::OPEN
                : $conversation->status,
        ])->save();

        return $conversation->refresh();
    }

    public function close(Conversation $conversation): Conversation
    {
        $conversation->forceFill([
            'status' => Conversation::CLOSED,
            'closed_at' => now(),
            'unread_count' => 0,
        ])->save();

        return $conversation->refresh();
    }

    /**
     * Put it away until a time.
     *
     * Snoozing rather than closing, because a conversation waiting on a
     * delivery is not finished — and one that has to be remembered by a person
     * is one that will be forgotten.
     */
    public function snooze(Conversation $conversation, string $until): Conversation
    {
        $conversation->forceFill([
            'status' => Conversation::SNOOZED,
            'snoozed_until' => Carbon::parse($until),
        ])->save();

        return $conversation->refresh();
    }

    /** Bring back everything whose snooze has expired. */
    public function wakeSnoozed(): int
    {
        $count = 0;

        foreach (Conversation::query()->dueBack()->get() as $conversation) {
            $conversation->forceFill([
                'status' => Conversation::OPEN,
                'snoozed_until' => null,
            ])->save();
            $count++;
        }

        return $count;
    }

    public function markRead(Conversation $conversation): Conversation
    {
        $conversation->forceFill(['unread_count' => 0])->save();

        return $conversation->refresh();
    }

    private function afterInbound(Conversation $conversation, Message $message): void
    {
        $conversation->forceFill([
            'last_message_at' => $message->occurred_at,
            'last_message_preview' => Str::limit((string) $message->body, 180),
            'last_message_direction' => Message::IN,
            'customer_last_at' => $message->occurred_at,
            'unread_count' => $conversation->unread_count + 1,
            'message_count' => $conversation->message_count + 1,
            // A closed conversation that receives a message is open again.
            // Anything else means a customer talking to a thread nobody is
            // watching.
            'status' => in_array($conversation->status, [Conversation::CLOSED, Conversation::SNOOZED], true)
                ? Conversation::OPEN
                : $conversation->status,
            'snoozed_until' => null,
            'closed_at' => null,
        ])->save();
    }

    private function afterOutbound(Conversation $conversation, Message $message): void
    {
        // An internal note is not a reply to anybody. It must not clear the
        // waiting state or stop the response clock.
        if ($message->is_internal) {
            $conversation->forceFill(['message_count' => $conversation->message_count + 1])->save();

            return;
        }

        $changes = [
            'last_message_at' => $message->occurred_at,
            'last_message_preview' => Str::limit((string) $message->body, 180),
            'last_message_direction' => Message::OUT,
            'message_count' => $conversation->message_count + 1,
            'unread_count' => 0,
            'status' => $conversation->status === Conversation::OPEN
                ? Conversation::PENDING
                : $conversation->status,
        ];

        // Recorded once, on the first human answer only. An automated
        // acknowledgement arriving in two seconds would otherwise make every
        // conversation look answered instantly.
        if ($conversation->first_response_seconds === null
            && $message->isHumanReply()
            && $conversation->customer_last_at !== null) {
            $changes['first_response_seconds'] = (int) $conversation->customer_last_at
                ->diffInSeconds($message->occurred_at);
        }

        $conversation->forceFill($changes)->save();
    }
}
