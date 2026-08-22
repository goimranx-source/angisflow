<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Models;

use App\Domain\Delivery\ShipmentStatus;
use App\Domain\Sales\Models\Order;
use App\Domain\Shared\Concerns\HasPublicId;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A parcel, in the one shape the whole tool understands.
 *
 * Whatever the courier is and whatever they call things, a shipment looks like
 * this. That is the entire point: the dashboard, the reports, the settlement
 * and the customer's tracking page are written once, against this, and adding a
 * courier never touches any of them.
 */
class Shipment extends Model
{
    use BelongsToBusiness, HasPublicId;

    protected $attributes = [
        'status' => 'draft',
        'is_cod' => false,
        'cod_amount_minor' => 0,
        'cod_settled_minor' => 0,
        'delivery_fee_minor' => 0,
        'parcel_count' => 1,
        'attempt_count' => 0,
    ];

    protected $fillable = [
        'public_id', 'account_id', 'business_id', 'order_id', 'courier_connection_id',
        'number', 'tracking_number', 'external_id', 'status', 'raw_status',
        'is_cod', 'currency', 'cod_amount_minor', 'cod_settled_minor', 'delivery_fee_minor',
        'recipient_name', 'recipient_phone', 'address', 'city', 'postcode', 'country',
        'weight_grams', 'parcel_count', 'notes',
        'booked_at', 'picked_up_at', 'delivered_at', 'returned_at', 'attempt_count',
        'created_by',
    ];

    protected static function booted(): void
    {
        static::creating(function (Shipment $shipment): void {
            if ($shipment->number === null || $shipment->number === '') {
                $shipment->number = 'SHP-'.strtoupper((string) Str::ulid());
            }
        });
    }

    protected function casts(): array
    {
        return [
            'is_cod' => 'boolean',
            'cod_amount_minor' => 'integer',
            'cod_settled_minor' => 'integer',
            'delivery_fee_minor' => 'integer',
            'parcel_count' => 'integer',
            'attempt_count' => 'integer',
            'booked_at' => 'datetime',
            'picked_up_at' => 'datetime',
            'delivered_at' => 'datetime',
            'returned_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Named courierConnection, not connection.
     *
     * Eloquent's Model already has a $connection property — the name of the
     * database connection — so a relation called `connection` is shadowed by
     * it, and $shipment->connection quietly returns the string "sqlite"
     * instead of the courier. It fails as a warning rather than an error,
     * which is the worst way for it to fail.
     */
    public function courierConnection(): BelongsTo
    {
        return $this->belongsTo(CourierConnection::class, 'courier_connection_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ShipmentEvent::class)->orderByDesc('occurred_at');
    }

    public function status(): ShipmentStatus
    {
        return ShipmentStatus::tryFrom($this->status) ?? ShipmentStatus::UNKNOWN;
    }

    public function codAmount(): Money
    {
        return new Money($this->cod_amount_minor, $this->currency);
    }

    /**
     * Cash the courier has collected and not yet handed over.
     *
     * The single most valuable number in a cash-on-delivery business, and the
     * one nobody can produce from a courier's own portal — it is money that has
     * left the customer, is not in the bank, and belongs to us.
     */
    public function codOutstanding(): Money
    {
        return new Money(
            $this->status() === ShipmentStatus::DELIVERED && $this->is_cod
                ? max(0, $this->cod_amount_minor - $this->cod_settled_minor)
                : 0,
            $this->currency,
        );
    }

    public function deliveryFee(): Money
    {
        return new Money($this->delivery_fee_minor, $this->currency);
    }

    /** How long it has been out, for a dashboard that sorts by "worry about this". */
    public function daysInFlight(): ?int
    {
        $from = $this->booked_at ?? $this->created_at;

        return $from === null || $this->status()->isFinal()
            ? null
            : (int) $from->diffInDays(now());
    }

    public function trackingUrl(): ?string
    {
        return $this->courierConnection?->courier?->trackingUrl($this->tracking_number);
    }

    public function scopeInFlight(Builder $q): Builder
    {
        return $q->whereIn('status', array_map(
            fn (ShipmentStatus $s) => $s->value,
            array_filter(ShipmentStatus::cases(), fn (ShipmentStatus $s) => $s->isInFlight()),
        ));
    }

    /** Delivered, cash on delivery, and not yet settled to us. */
    public function scopeAwaitingSettlement(Builder $q): Builder
    {
        return $q->where('is_cod', true)
            ->where('status', ShipmentStatus::DELIVERED->value)
            ->whereColumn('cod_settled_minor', '<', 'cod_amount_minor');
    }

    /** Anything a person needs to look at: unrecognised, or stuck. */
    public function scopeNeedsAttention(Builder $q, int $stuckDays = 5): Builder
    {
        return $q->where(fn ($w) => $w
            ->where('status', ShipmentStatus::UNKNOWN->value)
            ->orWhere(fn ($s) => $s->inFlight()->where('booked_at', '<', now()->subDays($stuckDays))));
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'id' => $this->public_id,
            'number' => $this->number,
            'tracking_number' => $this->tracking_number,
            'tracking_url' => $this->trackingUrl(),
            'status' => $this->status,
            'status_label' => $this->status()->label(),
            'raw_status' => $this->raw_status,
            'is_final' => $this->status()->isFinal(),
            'days_in_flight' => $this->daysInFlight(),
            'attempt_count' => $this->attempt_count,
            'is_cod' => $this->is_cod,
            'cod_amount' => $this->codAmount()->jsonSerialize(),
            'cod_outstanding' => $this->codOutstanding()->jsonSerialize(),
            'delivery_fee' => $this->deliveryFee()->jsonSerialize(),
            'recipient_name' => $this->recipient_name,
            'city' => $this->city,
            'courier' => $this->relationLoaded('courierConnection') ? $this->courierConnection?->toPayload() : null,
            'events' => $this->relationLoaded('events') ? $this->events->map->toPayload()->all() : [],
        ];
    }
}
