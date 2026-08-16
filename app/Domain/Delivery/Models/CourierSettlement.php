<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Models;

use App\Domain\Shared\Concerns\HasPublicId;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One payment from a courier, and what it was for.
 *
 * Declared figures and matched figures are kept apart deliberately: when they
 * differ, that difference is the whole reason to do this. One column for both
 * would hide it.
 */
class CourierSettlement extends Model
{
    use BelongsToBusiness, HasPublicId;

    public const DRAFT = 'draft';
    public const CONFIRMED = 'confirmed';
    public const DISPUTED = 'disputed';

    protected $attributes = [
        'status' => self::DRAFT,
        'declared_gross_minor' => 0, 'declared_fee_minor' => 0, 'declared_net_minor' => 0,
        'matched_gross_minor' => 0, 'matched_fee_minor' => 0, 'matched_net_minor' => 0,
        'variance_minor' => 0,
    ];

    protected $fillable = [
        'public_id', 'account_id', 'business_id', 'courier_connection_id',
        'number', 'external_ref', 'settled_on', 'status', 'currency',
        'declared_gross_minor', 'declared_fee_minor', 'declared_net_minor',
        'matched_gross_minor', 'matched_fee_minor', 'matched_net_minor',
        'variance_minor', 'deposit_account_id', 'journal_entry_id',
        'notes', 'confirmed_at', 'confirmed_by', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'settled_on' => 'date',
            'confirmed_at' => 'datetime',
            'declared_gross_minor' => 'integer', 'declared_fee_minor' => 'integer',
            'declared_net_minor' => 'integer', 'matched_gross_minor' => 'integer',
            'matched_fee_minor' => 'integer', 'matched_net_minor' => 'integer',
            'variance_minor' => 'integer',
        ];
    }

    public function courierConnection(): BelongsTo
    {
        return $this->belongsTo(CourierConnection::class, 'courier_connection_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CourierSettlementLine::class, 'courier_settlement_id');
    }

    public function declaredNet(): Money
    {
        return new Money($this->declared_net_minor, $this->currency);
    }

    public function matchedNet(): Money
    {
        return new Money($this->matched_net_minor, $this->currency);
    }

    /** Positive means they paid more than our lines explain. */
    public function variance(): Money
    {
        return new Money(abs($this->variance_minor), $this->currency);
    }

    /**
     * Whether this statement agrees with our records.
     *
     * Two separate questions, and both have to be yes. The totals reconciling
     * only proves the courier's own arithmetic — of course their stated total
     * matches the sum of their own lines. The question that matters is whether
     * those lines agree with what we believe, and that is answered per line.
     *
     * Checking only the variance let a statement through carrying a parcel paid
     * short and a parcel we have never heard of, because their numbers added up
     * perfectly among themselves.
     */
    public function agrees(): bool
    {
        return $this->variance_minor === 0 && $this->problemCount() === 0;
    }

    /** Lines that do not match what we expected. */
    public function problemCount(): int
    {
        $lines = $this->relationLoaded('lines') ? $this->lines : $this->lines()->get();

        return $lines->filter(fn (CourierSettlementLine $l) => $l->isProblem())->count();
    }

    /**
     * @return array<string, int>  how many lines of each kind
     */
    public function matchSummary(): array
    {
        $lines = $this->relationLoaded('lines') ? $this->lines : $this->lines()->get();

        return $lines->groupBy('match_status')->map->count()->all();
    }

    public function isPosted(): bool
    {
        return $this->status === self::CONFIRMED;
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereIn('status', [self::DRAFT, self::DISPUTED]);
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'id' => $this->public_id,
            'number' => $this->number,
            'external_ref' => $this->external_ref,
            'settled_on' => $this->settled_on?->toDateString(),
            'status' => $this->status,
            'declared_net' => $this->declaredNet()->jsonSerialize(),
            'matched_net' => $this->matchedNet()->jsonSerialize(),
            'variance' => $this->variance()->jsonSerialize(),
            'variance_direction' => $this->variance_minor === 0 ? null : ($this->variance_minor > 0 ? 'over' : 'short'),
            'agrees' => $this->agrees(),
            'problem_lines' => $this->problemCount(),
            'match_summary' => $this->matchSummary(),
            'courier' => $this->relationLoaded('courierConnection') ? $this->courierConnection?->toPayload() : null,
            'lines' => $this->relationLoaded('lines') ? $this->lines->map->toPayload()->all() : [],
        ];
    }
}
