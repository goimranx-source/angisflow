<?php

declare(strict_types=1);

namespace App\Domain\Crm\Models;

use App\Domain\Sales\Models\Customer;
use App\Domain\Sales\Models\Order;
use App\Domain\Shared\Concerns\HasPublicId;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An opportunity worth a number.
 *
 * The probability is copied from the stage when it moves and is then editable.
 * A salesperson who knows this particular deal is a long shot has to be able to
 * say so without changing the stage default for everybody else's deals.
 */
class Deal extends Model
{
    use BelongsToBusiness, HasPublicId, SoftDeletes;

    public const OPEN = 'open';

    public const WON = 'won';

    public const LOST = 'lost';

    protected $attributes = ['outcome' => self::OPEN, 'value_minor' => 0, 'probability' => 0];

    protected $fillable = [
        'public_id', 'account_id', 'business_id', 'pipeline_stage_id',
        'lead_id', 'customer_id', 'title', 'currency', 'value_minor', 'probability',
        'expected_close_on', 'closed_on', 'outcome', 'lost_reason', 'order_id',
        'owner_id', 'notes', 'stage_changed_at',
    ];

    protected function casts(): array
    {
        return [
            'expected_close_on' => 'date',
            'closed_on' => 'date',
            'stage_changed_at' => 'datetime',
            'value_minor' => 'integer',
            'probability' => 'integer',
        ];
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'pipeline_stage_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class);
    }

    public function stageEvents(): HasMany
    {
        return $this->hasMany(DealStageEvent::class)->orderBy('moved_at');
    }

    public function value(): Money
    {
        return new Money($this->value_minor, $this->currency);
    }

    /**
     * The value adjusted for how likely it is.
     *
     * What a forecast is actually made of. A pipeline reported at full face
     * value is a number every sales manager has learned to divide by three, and
     * doing that arithmetic honestly is better than making them guess a divisor.
     */
    public function weightedValue(): Money
    {
        return new Money(
            $this->outcome === self::WON
                ? $this->value_minor
                : ($this->outcome === self::LOST ? 0 : (int) round($this->value_minor * $this->probability / 100)),
            $this->currency,
        );
    }

    public function isOpen(): bool
    {
        return $this->outcome === self::OPEN;
    }

    /** How long it has sat in its current stage. */
    public function daysInStage(): ?int
    {
        $since = $this->stage_changed_at ?? $this->created_at;

        return $since === null || ! $this->isOpen() ? null : (int) $since->diffInDays(now());
    }

    /** Open, and nothing has happened to it for a while. */
    public function isStalled(int $days = 21): bool
    {
        return $this->isOpen() && ($this->daysInStage() ?? 0) >= $days;
    }

    /** Open and past the date it was meant to close. */
    public function isSlipping(): bool
    {
        return $this->isOpen()
            && $this->expected_close_on !== null
            && $this->expected_close_on->isPast();
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->where('outcome', self::OPEN);
    }

    public function scopeClosingBetween(Builder $q, string $from, string $to): Builder
    {
        return $q->whereBetween('expected_close_on', [$from, $to]);
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'id' => $this->public_id,
            'title' => $this->title,
            'value' => $this->value()->jsonSerialize(),
            'weighted_value' => $this->weightedValue()->jsonSerialize(),
            'probability' => $this->probability,
            'outcome' => $this->outcome,
            'expected_close_on' => $this->expected_close_on?->toDateString(),
            'days_in_stage' => $this->daysInStage(),
            'is_stalled' => $this->isStalled(),
            'is_slipping' => $this->isSlipping(),
            'lost_reason' => $this->lost_reason,
            'stage' => $this->relationLoaded('stage') ? $this->stage?->toPayload() : null,
            'customer' => $this->relationLoaded('customer') ? $this->customer?->toPayload() : null,
        ];
    }
}
