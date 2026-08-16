<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Models;

use App\Domain\Shared\ValueObjects\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One parcel on a courier's statement.
 *
 * shipment_id is nullable because a courier paying for a parcel we have no
 * record of is a real case and an important one. A line that could not be
 * stored would be a discrepancy nobody ever sees.
 */
class CourierSettlementLine extends Model
{
    public const MATCHED = 'matched';
    public const SHORT = 'short';
    public const OVER = 'over';
    public const UNKNOWN = 'unknown';
    public const DUPLICATE = 'duplicate';

    protected $attributes = [
        'collected_minor' => 0, 'fee_minor' => 0, 'net_minor' => 0,
        'match_status' => self::MATCHED,
    ];

    protected $fillable = [
        'courier_settlement_id', 'shipment_id', 'tracking_number',
        'collected_minor', 'fee_minor', 'net_minor', 'match_status', 'note',
    ];

    protected function casts(): array
    {
        return ['collected_minor' => 'integer', 'fee_minor' => 'integer', 'net_minor' => 'integer'];
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(CourierSettlement::class, 'courier_settlement_id');
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function isProblem(): bool
    {
        return $this->match_status !== self::MATCHED;
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        $currency = $this->settlement?->currency ?? 'USD';

        return [
            'tracking_number' => $this->tracking_number,
            'collected' => (new Money($this->collected_minor, $currency))->jsonSerialize(),
            'fee' => (new Money($this->fee_minor, $currency))->jsonSerialize(),
            'net' => (new Money($this->net_minor, $currency))->jsonSerialize(),
            'match_status' => $this->match_status,
            'is_problem' => $this->isProblem(),
            'note' => $this->note,
        ];
    }
}
