<?php

declare(strict_types=1);

namespace App\Domain\Risk\Models;

use App\Domain\Sales\Models\Customer;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What we believe about a customer, and why.
 *
 * The `signals` array is the important part. A score with no explanation is a
 * number a shop owner will ignore; the sentence "4 of their last 6 parcels came
 * back undelivered" is one they act on before despatch.
 */
class CustomerRiskProfile extends Model
{
    use BelongsToBusiness;

    public const TRUSTED = 'trusted';
    public const NORMAL = 'normal';
    public const WATCH = 'watch';
    public const HIGH = 'high';

    protected $attributes = ['score' => 0, 'band' => self::NORMAL];

    protected $fillable = [
        'account_id', 'business_id', 'customer_id', 'score', 'band', 'signals',
        'orders_total', 'orders_delivered', 'orders_rto', 'orders_returned',
        'failed_attempts', 'cod_exposure_minor', 'computed_at',
    ];

    protected function casts(): array
    {
        return [
            'signals' => 'array',
            'computed_at' => 'datetime',
            'score' => 'integer',
            'orders_total' => 'integer',
            'orders_delivered' => 'integer',
            'orders_rto' => 'integer',
            'orders_returned' => 'integer',
            'failed_attempts' => 'integer',
            'cod_exposure_minor' => 'integer',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * How much of what they were sent never arrived.
     *
     * Null below three parcels. One failure out of one is 100% and means
     * nothing at all — and a figure that meaningless will still be sorted on
     * and acted upon if it is offered.
     */
    public function failureRate(): ?float
    {
        $settled = $this->orders_delivered + $this->orders_rto;

        return $settled < 3 ? null : round($this->orders_rto / $settled * 100, 1);
    }

    public function codExposure(): Money
    {
        return new Money($this->cod_exposure_minor, $this->customer?->currency ?? 'USD');
    }

    /** The reasons, worst first — what a person is actually shown. */
    public function reasons(): array
    {
        $signals = $this->signals ?? [];
        usort($signals, fn ($a, $b) => ($b['points'] ?? 0) <=> ($a['points'] ?? 0));

        return array_map(fn ($s) => $s['evidence'] ?? $s['signal'] ?? '', $signals);
    }

    public function scopeRisky(Builder $q): Builder
    {
        return $q->whereIn('band', [self::WATCH, self::HIGH]);
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'score' => $this->score,
            'band' => $this->band,
            'failure_rate' => $this->failureRate(),
            'orders_total' => $this->orders_total,
            'orders_rto' => $this->orders_rto,
            'orders_returned' => $this->orders_returned,
            'failed_attempts' => $this->failed_attempts,
            'cod_exposure' => $this->codExposure()->jsonSerialize(),
            'reasons' => $this->reasons(),
            'signals' => $this->signals ?? [],
            'computed_at' => $this->computed_at?->toIso8601String(),
        ];
    }
}
