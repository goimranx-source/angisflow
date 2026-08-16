<?php

declare(strict_types=1);

namespace App\Domain\Helpdesk\Models;

use App\Domain\Inbox\Models\Conversation;
use App\Domain\Sales\Models\Customer;
use App\Domain\Shared\Concerns\HasPublicId;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A promise about a conversation.
 *
 * It has no messages of its own — see the migration. What it adds is a
 * category, two clocks and a resolution, which is everything a conversation
 * lacks and a helpdesk needs.
 */
class Ticket extends Model
{
    use BelongsToBusiness, HasPublicId;

    public const OPEN = 'open';

    public const PENDING = 'pending';

    public const SOLVED = 'solved';

    public const CLOSED = 'closed';

    protected $attributes = [
        'status' => self::OPEN,
        'priority' => 'normal',
        'response_breached' => false,
        'resolution_breached' => false,
        'paused_seconds' => 0,
    ];

    protected $fillable = [
        'public_id', 'account_id', 'business_id', 'conversation_id', 'customer_id',
        'helpdesk_category_id', 'subject_type', 'subject_id',
        'number', 'title', 'status', 'priority', 'assigned_to', 'opened_by',
        'response_due_at', 'resolution_due_at', 'first_responded_at',
        'response_working_seconds', 'resolution_working_seconds',
        'response_breached', 'resolution_breached',
        'paused_at', 'paused_seconds',
        'resolution', 'resolution_note', 'satisfaction', 'satisfaction_comment',
        'solved_at', 'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'response_due_at' => 'datetime',
            'resolution_due_at' => 'datetime',
            'first_responded_at' => 'datetime',
            'paused_at' => 'datetime',
            'solved_at' => 'datetime',
            'closed_at' => 'datetime',
            'response_breached' => 'boolean',
            'resolution_breached' => 'boolean',
            'response_working_seconds' => 'integer',
            'resolution_working_seconds' => 'integer',
            'paused_seconds' => 'integer',
            'satisfaction' => 'integer',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(HelpdeskCategory::class, 'helpdesk_category_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::OPEN, self::PENDING], true);
    }

    /** Waiting on the customer, so the clock is not running. */
    public function isPaused(): bool
    {
        return $this->paused_at !== null;
    }

    /**
     * How long until the response is late, in minutes.
     *
     * Negative when it already is. Null while paused — a ticket waiting on a
     * customer has no meaningful countdown, and showing one that keeps ticking
     * is what makes agents stop believing the number.
     */
    public function responseMinutesLeft(): ?int
    {
        if ($this->response_due_at === null || $this->first_responded_at !== null || $this->isPaused()) {
            return null;
        }

        return (int) now()->diffInMinutes($this->response_due_at, false);
    }

    public function isResponseLate(): bool
    {
        return $this->response_breached
            || ($this->first_responded_at === null
                && $this->response_due_at !== null
                && ! $this->isPaused()
                && $this->response_due_at->isPast());
    }

    public function isResolutionLate(): bool
    {
        return $this->resolution_breached
            || ($this->isOpen()
                && $this->resolution_due_at !== null
                && ! $this->isPaused()
                && $this->resolution_due_at->isPast());
    }

    public function scopeOpenTickets(Builder $q): Builder
    {
        return $q->whereIn('status', [self::OPEN, self::PENDING]);
    }

    /** Running out of time, soonest first — the queue an agent should work. */
    public function scopeAtRisk(Builder $q): Builder
    {
        return $q->openTickets()
            ->whereNull('paused_at')
            ->whereNull('first_responded_at')
            ->whereNotNull('response_due_at')
            ->orderBy('response_due_at');
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'id' => $this->public_id,
            'number' => $this->number,
            'title' => $this->title,
            'status' => $this->status,
            'priority' => $this->priority,
            'is_paused' => $this->isPaused(),
            'response_due_at' => $this->response_due_at?->toIso8601String(),
            'response_minutes_left' => $this->responseMinutesLeft(),
            'response_late' => $this->isResponseLate(),
            'resolution_late' => $this->isResolutionLate(),
            'first_responded_at' => $this->first_responded_at?->toIso8601String(),
            'response_working_seconds' => $this->response_working_seconds,
            'resolution' => $this->resolution,
            'satisfaction' => $this->satisfaction,
            'category' => $this->relationLoaded('category') ? $this->category?->toPayload() : null,
            'customer' => $this->relationLoaded('customer') ? $this->customer?->toPayload() : null,
        ];
    }
}
