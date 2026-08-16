<?php

declare(strict_types=1);

namespace App\Domain\Returns\Models;

use App\Domain\Delivery\Models\Shipment;
use App\Domain\Ledger\Models\JournalEntry;
use App\Domain\Sales\Models\Customer;
use App\Domain\Sales\Models\Order;
use App\Domain\Sales\Models\Payment;
use App\Domain\Shared\Concerns\HasPublicId;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Stock\Models\StockLocation;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Goods coming back, either way.
 *
 * `is_rto` is the whole distinction: a parcel that never reached the customer
 * versus one they had and sent back. Everything else about the two is the same,
 * which is why they share a table — see the migration for why the money is not
 * the same at all.
 */
class GoodsReturn extends Model
{
    use BelongsToBusiness, HasPublicId;

    public const EXPECTED = 'expected';

    public const RECEIVED = 'received';

    public const SETTLED = 'settled';

    public const CANCELLED = 'cancelled';

    protected $table = 'goods_returns';

    protected $attributes = [
        'status' => self::EXPECTED,
        'is_rto' => false,
        'goods_minor' => 0,
        'restocking_fee_minor' => 0,
        'refund_minor' => 0,
        'cost_minor' => 0,
    ];

    protected $fillable = [
        'public_id', 'account_id', 'business_id',
        'order_id', 'shipment_id', 'customer_id',
        'number', 'returned_on', 'is_rto', 'status', 'reason', 'notes', 'currency',
        'goods_minor', 'restocking_fee_minor', 'refund_minor', 'cost_minor',
        'stock_location_id', 'journal_entry_id', 'refund_payment_id',
        'received_at', 'settled_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'returned_on' => 'date',
            'is_rto' => 'boolean',
            'received_at' => 'datetime',
            'settled_at' => 'datetime',
            'goods_minor' => 'integer',
            'restocking_fee_minor' => 'integer',
            'refund_minor' => 'integer',
            'cost_minor' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(GoodsReturnLine::class, 'goods_return_id')->orderBy('line_no');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'stock_location_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function refundPayment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'refund_payment_id');
    }

    public function goods(): Money
    {
        return new Money($this->goods_minor, $this->currency);
    }

    public function refund(): Money
    {
        return new Money($this->refund_minor, $this->currency);
    }

    public function cost(): Money
    {
        return new Money($this->cost_minor, $this->currency);
    }

    /**
     * What this return cost us: the margin given up, plus anything kept back.
     *
     * Negative when a restocking fee more than covers the margin lost, which is
     * exactly what a restocking fee is for.
     */
    public function lossOnReturn(): Money
    {
        return new Money(
            ($this->goods_minor - $this->cost_minor) - $this->restocking_fee_minor,
            $this->currency,
        );
    }

    public function isSettled(): bool
    {
        return $this->status === self::SETTLED;
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereIn('status', [self::EXPECTED, self::RECEIVED]);
    }

    public function scopeRto(Builder $q): Builder
    {
        return $q->where('is_rto', true);
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'id' => $this->public_id,
            'number' => $this->number,
            'returned_on' => $this->returned_on?->toDateString(),
            'is_rto' => $this->is_rto,
            'status' => $this->status,
            'reason' => $this->reason,
            'goods' => $this->goods()->jsonSerialize(),
            'refund' => $this->refund()->jsonSerialize(),
            'restocking_fee' => (new Money($this->restocking_fee_minor, $this->currency))->jsonSerialize(),
            'cost' => $this->cost()->jsonSerialize(),
            'loss' => $this->lossOnReturn()->jsonSerialize(),
            'order' => $this->relationLoaded('order') ? $this->order?->number : null,
            'lines' => $this->relationLoaded('lines') ? $this->lines->map->toPayload()->all() : [],
        ];
    }
}
