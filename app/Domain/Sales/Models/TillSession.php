<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Domain\Shared\Concerns\HasPublicId;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Stock\Models\StockLocation;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One shift at one till.
 *
 * The unit a cash drawer is reconciled over. Everything about it exists to
 * answer one question at the end of the day: is the money in the drawer the
 * money that should be in the drawer, and if not, by how much.
 */
class TillSession extends Model
{
    use BelongsToBusiness, HasPublicId;

    public const OPEN = 'open';

    public const CLOSED = 'closed';

    protected $attributes = [
        'status' => self::OPEN,
        'till_code' => 'T1',
        'opening_float_minor' => 0,
        'cash_sales_minor' => 0,
        'cash_refunds_minor' => 0,
        'cash_in_minor' => 0,
        'cash_out_minor' => 0,
        'other_tenders_minor' => 0,
    ];

    protected $fillable = [
        'public_id', 'account_id', 'business_id', 'stock_location_id',
        'till_code', 'number', 'opened_by', 'closed_by', 'opened_at', 'closed_at',
        'currency', 'opening_float_minor', 'cash_sales_minor', 'cash_refunds_minor',
        'cash_in_minor', 'cash_out_minor', 'other_tenders_minor',
        'counted_minor', 'variance_minor', 'status', 'notes', 'journal_entry_id',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'opening_float_minor' => 'integer',
            'cash_sales_minor' => 'integer',
            'cash_refunds_minor' => 'integer',
            'cash_in_minor' => 'integer',
            'cash_out_minor' => 'integer',
            'other_tenders_minor' => 'integer',
            'counted_minor' => 'integer',
            'variance_minor' => 'integer',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'stock_location_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * What should be in the drawer right now.
     *
     * Float, plus cash taken, less cash refunded and cash removed to the safe.
     * Card and mobile are deliberately absent — they never touched the drawer,
     * and including them is the commonest way a till appears wildly short.
     */
    public function expectedCash(): Money
    {
        return new Money(
            $this->opening_float_minor
                + $this->cash_sales_minor
                + $this->cash_in_minor
                - $this->cash_refunds_minor
                - $this->cash_out_minor,
            $this->currency,
        );
    }

    public function counted(): ?Money
    {
        return $this->counted_minor === null ? null : new Money($this->counted_minor, $this->currency);
    }

    /** Positive is over, negative is short. Null until somebody has counted. */
    public function variance(): ?Money
    {
        return $this->variance_minor === null ? null : new Money($this->variance_minor, $this->currency);
    }

    /** Everything taken this shift, however it was paid. */
    public function takings(): Money
    {
        return new Money($this->cash_sales_minor + $this->other_tenders_minor, $this->currency);
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->where('status', self::OPEN);
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'id' => $this->public_id,
            'number' => $this->number,
            'till_code' => $this->till_code,
            'status' => $this->status,
            'opened_at' => $this->opened_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'opening_float' => (new Money($this->opening_float_minor, $this->currency))->jsonSerialize(),
            'cash_sales' => (new Money($this->cash_sales_minor, $this->currency))->jsonSerialize(),
            'other_tenders' => (new Money($this->other_tenders_minor, $this->currency))->jsonSerialize(),
            'takings' => $this->takings()->jsonSerialize(),
            'expected_cash' => $this->expectedCash()->jsonSerialize(),
            'counted' => $this->counted()?->jsonSerialize(),
            'variance' => $this->variance()?->jsonSerialize(),
        ];
    }
}
