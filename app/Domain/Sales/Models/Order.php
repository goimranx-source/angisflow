<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Domain\Sales\OrderReference;
use App\Domain\Shared\Concerns\HasPublicId;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Stock\Models\StockLocation;
use App\Domain\Delivery\Models\Shipment;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use App\Models\Storefront;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Something a customer asked for.
 *
 * Three statuses rather than one — see the migration for why one is not enough.
 * The short version: prepaid-and-unshipped and shipped-and-unpaid are both
 * ordinary, and a single column can describe neither.
 */
class Order extends Model
{
    use BelongsToBusiness, HasPublicId, SoftDeletes;

    public const DRAFT = 'draft';

    public const CONFIRMED = 'confirmed';

    public const FULFILLED = 'fulfilled';

    public const COMPLETED = 'completed';

    public const CANCELLED = 'cancelled';

    public const UNFULFILLED = 'unfulfilled';

    public const PARTIAL = 'partial';

    public const UNPAID = 'unpaid';

    public const PAID = 'paid';

    public const REFUNDED = 'refunded';

    protected $attributes = [
        'status' => self::DRAFT,
        'fulfilment_status' => self::UNFULFILLED,
        'payment_status' => self::UNPAID,
        'channel' => 'manual',
        'is_cod' => false,
        'subtotal_minor' => 0,
        'discount_minor' => 0,
        'shipping_minor' => 0,
        'tax_minor' => 0,
        'total_minor' => 0,
        'paid_minor' => 0,
        'cost_minor' => 0,
    ];

    protected $fillable = [
        'public_id', 'account_id', 'business_id', 'customer_id',
        'number', 'reference', 'ordered_on', 'status', 'fulfilment_status', 'payment_status',
        'channel', 'external_ref', 'is_cod', 'currency', 'storefront_id',

        // The fields the eight-platform survey turned up. See the migration
        // that added them.
        'payment_method', 'transaction_ref', 'paid_at', 'promised_delivery_on',
        'shipping_method', 'source', 'staff_notes', 'refunded_minor',
        'subtotal_minor', 'discount_minor', 'shipping_minor', 'tax_minor',
        'total_minor', 'paid_minor', 'cost_minor',
        'stock_location_id', 'invoice_id', 'till_session_id', 'idempotency_key',
        'shipping_name', 'shipping_phone', 'shipping_address',
        'shipping_city', 'shipping_postcode', 'shipping_country',
        'notes', 'cancelled_reason', 'archived_at',
        'confirmed_at', 'fulfilled_at', 'cancelled_at', 'created_by',
    ];

    /**
     * Every order gets this business's own reference, wherever it was made.
     *
     * On the model rather than at each place an order is created. There are
     * four of those -- the counter, the importer, the shop reconciler, and
     * whatever is written next -- and a reference that depends on somebody
     * remembering to ask for it is one that will be missing from the fourth.
     */
    protected static function booted(): void
    {
        static::creating(function (self $order): void {
            OrderReference::assign($order);
        });
    }

    protected function casts(): array
    {
        return [
            'ordered_on' => 'date',

            // The promise, not the fact. What actually happened is the
            // shipment's delivered_at — see the migration that added this.
            'promised_delivery_on' => 'date',
            'paid_at' => 'datetime',
            'is_cod' => 'boolean',
            'confirmed_at' => 'datetime',
            'fulfilled_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'archived_at' => 'datetime',
            'subtotal_minor' => 'integer',
            'discount_minor' => 'integer',
            'shipping_minor' => 'integer',
            'tax_minor' => 'integer',
            'total_minor' => 'integer',
            'paid_minor' => 'integer',
            'cost_minor' => 'integer',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class)->orderBy('line_no');
    }

    /**
     * The shop this order came through, where it came through one.
     *
     * Null is meaningful rather than missing: an order taken at the counter or
     * over the phone belongs to no storefront, and that is what makes it a
     * walk-in. Filling it in with a default shop would hide the distinction the
     * business actually cares about — which of its channels is selling.
     */
    public function storefront(): BelongsTo
    {
        return $this->belongsTo(Storefront::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class)->latest('id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'stock_location_id');
    }

    public function total(): Money
    {
        return new Money($this->total_minor, $this->currency);
    }

    public function cost(): Money
    {
        return new Money($this->cost_minor, $this->currency);
    }

    public function outstanding(): Money
    {
        return new Money(max(0, $this->total_minor - $this->paid_minor), $this->currency);
    }

    /**
     * What was made on this order.
     *
     * Null before fulfilment rather than the whole revenue: nothing has been
     * shipped, so nothing has been costed, and reporting the total as profit is
     * a number somebody will believe.
     */
    public function margin(): ?Money
    {
        if ($this->cost_minor === 0 || $this->fulfilment_status === self::UNFULFILLED) {
            return null;
        }

        // Tax is not ours and shipping is usually a pass-through, so margin is
        // measured on the goods.
        $net = $this->total_minor - $this->tax_minor - $this->shipping_minor;

        return new Money($net - $this->cost_minor, $this->currency);
    }

    public function marginPercent(): ?float
    {
        $margin = $this->margin();
        $net = $this->total_minor - $this->tax_minor - $this->shipping_minor;

        return $margin === null || $net === 0 ? null : round($margin->minor / $net * 100, 2);
    }

    public function isOpen(): bool
    {
        return ! in_array($this->status, [self::COMPLETED, self::CANCELLED], true);
    }

    public function isEditable(): bool
    {
        return $this->status === self::DRAFT;
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereNotIn('status', [self::COMPLETED, self::CANCELLED]);
    }

    /** Confirmed, paid for or not, and still waiting to go out. */
    public function scopeToPick(Builder $q): Builder
    {
        return $q->where('status', self::CONFIRMED)
            ->whereIn('fulfilment_status', [self::UNFULFILLED, self::PARTIAL]);
    }

    public function scopeUnpaid(Builder $q): Builder
    {
        return $q->whereIn('payment_status', [self::UNPAID, self::PARTIAL])
            ->whereNotIn('status', [self::CANCELLED, self::DRAFT]);
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        $margin = $this->margin();

        return [
            'id' => $this->public_id,
            'number' => $this->number,
            'ordered_on' => $this->ordered_on?->toDateString(),
            'status' => $this->status,
            'fulfilment_status' => $this->fulfilment_status,
            'payment_status' => $this->payment_status,
            'channel' => $this->channel,
            'is_cod' => $this->is_cod,
            'currency' => $this->currency,
            'total' => $this->total()->jsonSerialize(),
            'outstanding' => $this->outstanding()->jsonSerialize(),
            'cost' => $this->cost()->jsonSerialize(),
            'margin' => $margin?->jsonSerialize(),
            'margin_pct' => $this->marginPercent(),
            'customer' => $this->relationLoaded('customer') ? $this->customer?->toPayload() : null,
            'lines' => $this->relationLoaded('lines') ? $this->lines->map->toPayload()->all() : [],
        ];
    }
}
