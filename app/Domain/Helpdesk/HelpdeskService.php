<?php

declare(strict_types=1);

namespace App\Domain\Helpdesk;

use App\Domain\Helpdesk\Models\BotRule;
use App\Domain\Helpdesk\Models\HelpdeskCategory;
use App\Domain\Helpdesk\Models\KbArticle;
use App\Domain\Helpdesk\Models\Ticket;
use App\Domain\Inbox\InboxService;
use App\Domain\Inbox\Models\Conversation;
use App\Domain\Inbox\Models\Message;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Tickets, their clocks, and the bot that tries first.
 *
 * ── Working hours, not wall clock ────────────────────────────────────────────
 *
 * A four-hour response promise measured against the clock is breached before
 * anybody could have read a message that arrived at 5pm on Friday. Measured
 * against the hours the business actually works, it means what everybody
 * assumed. Both are stored: the due time so a queue can be sorted, and the
 * elapsed working seconds so a report is honest.
 *
 * ── Pausing is what makes the report believable ──────────────────────────────
 *
 * A ticket waiting on a photo from the customer is not a ticket the team is
 * ignoring. Without a pause, every one of those breaches, and the SLA report
 * becomes a ranking of which agents asked the fewest questions — which is
 * exactly the wrong incentive to create.
 */
final class HelpdeskService
{
    /** Fallback working hours when a business has not set any. */
    private const DEFAULT_HOURS = ['mon', 'tue', 'wed', 'thu', 'fri'];

    private const DEFAULT_FROM = '09:00';

    private const DEFAULT_TO = '17:00';

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly InboxService $inbox,
    ) {}

    /**
     * Raise a ticket against a conversation.
     *
     * The conversation keeps its messages. This adds the promise.
     */
    public function open(?Conversation $conversation, string $title, array $options = []): Ticket
    {
        $category = $options['category'] ?? null;
        $openedAt = isset($options['opened_at']) ? Carbon::parse($options['opened_at']) : now();

        return DB::transaction(function () use ($conversation, $title, $options, $category, $openedAt) {
            $ticket = Ticket::create([
                'conversation_id' => $conversation?->id,
                'customer_id' => $options['customer_id'] ?? $conversation?->customer_id,
                'helpdesk_category_id' => $category?->id,
                'subject_type' => $options['subject_type'] ?? null,
                'subject_id' => $options['subject_id'] ?? null,
                'number' => $options['number'] ?? $this->nextNumber(),
                'title' => $title,
                'priority' => $options['priority'] ?? 'normal',
                'assigned_to' => $options['assigned_to'] ?? null,
                'opened_by' => $options['opened_by'] ?? auth()->id(),
                'response_due_at' => $category?->responseMinutes() === null
                    ? null
                    : $this->addWorkingMinutes($openedAt, $category->responseMinutes()),
                'resolution_due_at' => $category?->resolutionMinutes() === null
                    ? null
                    : $this->addWorkingMinutes($openedAt, $category->resolutionMinutes()),
            ]);

            // A conversation that already had a human reply has already been
            // responded to. Starting a fresh clock would make an answered
            // question look unanswered.
            if ($conversation?->first_response_seconds !== null) {
                $ticket->forceFill([
                    'first_responded_at' => $conversation->last_message_at,
                    'response_working_seconds' => $conversation->first_response_seconds,
                ])->save();
            }

            return $ticket->refresh();
        });
    }

    /**
     * Record that somebody answered.
     *
     * Called with the message so an automated reply cannot stop the clock — the
     * same rule as the inbox, for the same reason.
     */
    public function recordReply(Ticket $ticket, Message $message): Ticket
    {
        if (! $message->isHumanReply() || $ticket->first_responded_at !== null) {
            return $ticket;
        }

        $opened = $ticket->created_at ?? now();

        $ticket->forceFill([
            'first_responded_at' => $message->occurred_at,
            // Clamped at zero. The paused total can exceed the working span —
            // a ticket opened at 5pm and paused overnight accrues hours of
            // pause against minutes of working time — and a negative response
            // time is not a fast one, it is a number that will be averaged
            // into somebody's report and quietly improve it.
            'response_working_seconds' => max(0, $this->workingSecondsBetween($opened, $message->occurred_at)
                - $ticket->paused_seconds),
            'response_breached' => $ticket->response_due_at !== null
                && $message->occurred_at->gt($ticket->response_due_at),
        ])->save();

        return $ticket->refresh();
    }

    /** Waiting on the customer. The clock stops. */
    public function pause(Ticket $ticket): Ticket
    {
        if ($ticket->isPaused() || ! $ticket->isOpen()) {
            return $ticket;
        }

        $ticket->forceFill(['paused_at' => now(), 'status' => Ticket::PENDING])->save();

        return $ticket->refresh();
    }

    /**
     * They came back. The clock restarts, and the due dates move.
     *
     * Moving the due date is the part that is easy to forget: leaving it where
     * it was means a ticket paused for three days is instantly breached the
     * moment it resumes, through nobody's fault.
     */
    public function resume(Ticket $ticket): Ticket
    {
        if (! $ticket->isPaused()) {
            return $ticket;
        }

        $paused = (int) $ticket->paused_at->diffInSeconds(now());

        $ticket->forceFill([
            'paused_at' => null,
            'paused_seconds' => $ticket->paused_seconds + $paused,
            'status' => Ticket::OPEN,
            'response_due_at' => $ticket->response_due_at?->copy()->addSeconds($paused),
            'resolution_due_at' => $ticket->resolution_due_at?->copy()->addSeconds($paused),
        ])->save();

        return $ticket->refresh();
    }

    public function solve(Ticket $ticket, string $resolution, ?string $note = null): Ticket
    {
        if (! $ticket->isOpen()) {
            throw new RuntimeException("{$ticket->number} is already closed.");
        }

        $opened = $ticket->created_at ?? now();

        $ticket->forceFill([
            'status' => Ticket::SOLVED,
            'resolution' => $resolution,
            'resolution_note' => $note,
            'solved_at' => now(),
            'paused_at' => null,
            'resolution_working_seconds' => max(0, $this->workingSecondsBetween($opened, now()) - $ticket->paused_seconds),
            'resolution_breached' => $ticket->resolution_due_at !== null && now()->gt($ticket->resolution_due_at),
        ])->save();

        return $ticket->refresh();
    }

    /**
     * Let the bot try.
     *
     * ── The stopping rule is the whole design ────────────────────────────────
     *
     * A bot that cannot give up is worse than no bot. It traps a customer in a
     * loop, and the customer leaves and says so somewhere public. So every rule
     * carries a limit, the attempts are counted per conversation, and running
     * out means a person — announced plainly rather than by going quiet.
     *
     * @return array{answered: bool, rule: ?BotRule, handoff: bool, message: ?Message}
     */
    public function tryBot(Conversation $conversation, string $text): array
    {
        $conversation->loadMissing('channel');

        $attempts = Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('is_automated', true)
            ->where('is_internal', false)
            ->count();

        $rules = BotRule::query()
            ->active()
            ->where(fn ($q) => $q->whereNull('inbox_channel_id')
                ->orWhere('inbox_channel_id', $conversation->inbox_channel_id))
            ->with('article')
            ->get();

        foreach ($rules as $rule) {
            if ($rule->trigger === BotRule::KEYWORD && ! $rule->matches($text)) {
                continue;
            }

            if ($rule->trigger === BotRule::FALLBACK && $attempts === 0) {
                // A fallback is what happens when nothing else matched, not the
                // first thing anybody hears.
                continue;
            }

            $rule->increment('fired_count');

            // Out of patience. Handing off is the correct answer, and saying so
            // is better than silence — a customer who knows a person is coming
            // waits; one who thinks they are being ignored does not.
            if ($attempts >= $rule->max_attempts || $rule->action === BotRule::HANDOFF) {
                $rule->increment('handoff_count');

                return [
                    'answered' => false,
                    'rule' => $rule,
                    'handoff' => true,
                    'message' => $this->handoff($conversation),
                ];
            }

            $body = $rule->action === BotRule::ARTICLE
                ? ($rule->article?->summary ?? $rule->article?->body)
                : $rule->reply_body;

            if ($body === null || trim($body) === '') {
                continue;
            }

            if ($rule->article !== null) {
                $rule->article->increment('view_count');
            }

            return [
                'answered' => true,
                'rule' => $rule,
                'handoff' => false,
                'message' => $this->inbox->reply($conversation, $body, [
                    'automated' => true,
                    'sent_by' => null,
                ]),
            ];
        }

        return ['answered' => false, 'rule' => null, 'handoff' => false, 'message' => null];
    }

    /**
     * Find articles that might answer something.
     *
     * Deliberately a plain search over title, summary and keywords rather than
     * anything cleverer. It is honest about what it is, it needs no index to
     * maintain, and it can be replaced by something better without any caller
     * changing — which is the right shape for a piece nobody has measured yet.
     *
     * @return list<KbArticle>
     */
    public function search(string $query, array $options = []): array
    {
        $terms = array_filter(explode(' ', strtolower(trim($query))), fn ($t) => strlen($t) > 2);

        if ($terms === []) {
            return [];
        }

        return KbArticle::query()
            ->published()
            ->when($options['public_only'] ?? false, fn ($q) => $q->where('is_public', true))
            ->when($options['bot_only'] ?? false, fn ($q) => $q->where('is_bot_answer', true))
            ->where(function ($q) use ($terms) {
                foreach ($terms as $term) {
                    $q->orWhere('title', 'like', "%{$term}%")
                        ->orWhere('keywords', 'like', "%{$term}%")
                        ->orWhere('summary', 'like', "%{$term}%");
                }
            })
            // Most-read first, as a stand-in for relevance until there is
            // something real to rank by.
            ->orderByDesc('view_count')
            ->limit($options['limit'] ?? 5)
            ->get()
            ->all();
    }

    /**
     * Add working minutes to a moment, skipping closed hours and days.
     *
     * Walks hour by hour rather than doing arithmetic, because the arithmetic
     * has to handle a start outside hours, a span crossing a weekend, and a
     * business open on Sunday — and the loop is obviously correct where the
     * closed form is obviously not.
     */
    public function addWorkingMinutes(Carbon $from, int $minutes): Carbon
    {
        $at = $from->copy();
        $remaining = $minutes;
        $guard = 0;

        while ($remaining > 0 && $guard < 20000) {
            $guard++;

            if (! $this->isWorking($at)) {
                $at->addMinutes(15);

                continue;
            }

            $at->addMinute();
            $remaining--;
        }

        return $at;
    }

    /** How much of a span was inside working hours. */
    public function workingSecondsBetween(Carbon $from, Carbon $to): int
    {
        if ($to->lte($from)) {
            return 0;
        }

        $seconds = 0;
        $at = $from->copy();
        $guard = 0;

        // Fifteen-minute steps: an SLA report does not need the precision of a
        // per-second walk, and a year-long span at one second a step is a
        // million iterations.
        while ($at->lt($to) && $guard < 40000) {
            $guard++;

            if ($this->isWorking($at)) {
                $seconds += min(900, (int) $at->diffInSeconds($to));
            }

            $at->addMinutes(15);
        }

        return $seconds;
    }

    private function isWorking(Carbon $at): bool
    {
        $hours = $this->tenant->business()?->settings['working_hours'] ?? null;

        if ($hours === null) {
            return in_array(strtolower($at->format('D')), self::DEFAULT_HOURS, true)
                && $at->format('H:i') >= self::DEFAULT_FROM
                && $at->format('H:i') < self::DEFAULT_TO;
        }

        $today = $hours[strtolower($at->format('D'))] ?? null;

        if ($today === null || ($today['closed'] ?? false)) {
            return false;
        }

        $now = $at->format('H:i');

        return $now >= ($today['from'] ?? self::DEFAULT_FROM)
            && $now < ($today['to'] ?? self::DEFAULT_TO);
    }

    private function handoff(Conversation $conversation): Message
    {
        return $this->inbox->reply(
            $conversation,
            'Let me get somebody to help with this — one moment.',
            ['automated' => true, 'sent_by' => null],
        );
    }

    private function nextNumber(?string $date = null): string
    {
        $prefix = 'TKT-'.substr($date ?? now()->toDateString(), 0, 4).'-';

        $last = Ticket::query()
            ->where('business_id', $this->tenant->business()->id)
            ->where('number', 'like', $prefix.'%')
            ->orderByDesc('number')
            ->value('number');

        return $prefix.str_pad((string) ($last === null ? 1 : ((int) substr($last, strlen($prefix))) + 1), 4, '0', STR_PAD_LEFT);
    }
}
