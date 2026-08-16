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

/**
 * An offer, which may expire and may be revised.
 *
 * Not an invoice. A quote posts nothing, may never be accepted, and can be
 * superseded three times before anybody decides. An accepted one creates an
 * order, and the order raises the invoice — see the migration for why sharing
 * a table with invoices breaks both.
 *
 * Revisions are new rows pointing at the original rather than edits, because
 * what was sent on Tuesday has to stay readable after Thursday's revision. The
 * customer is looking at Tuesday's.
 */
class Quote extends Model
{
    use BelongsToBusiness, HasPublicId;

    public const DRAFT = 'draft';

    public const SENT = 'sent';

    public const ACCEPTED = 'accepted';

    public const DECLINED = 'declined';

    public const EXPIRED = 'expired';

    public const SUPERSEDED = 'superseded';

    protected $attributes = [
        'status' => self::DRAFT, 'version' => 1,
        'subtotal_minor' => 0, 'discount_minor' => 0, 'tax_minor' => 0, 'total_minor' => 0,
    ];

    protected $fillable = [
        'public_id', 'account_id', 'business_id', 'deal_id', 'customer_id', 'lead_id',
        'number', 'supersedes_id', 'version', 'issued_on', 'valid_until', 'status',
        'currency', 'subtotal_minor', 'discount_minor', 'tax_minor', 'total_minor',
        'order_id', 'terms', 'notes', 'sent_at', 'decided_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'issued_on' => 'date',
            'valid_until' => 'date',
            'sent_at' => 'datetime',
            'decided_at' => 'datetime',
            'version' => 'integer',
            'subtotal_minor' => 'integer',
            'discount_minor' => 'integer',
            'tax_minor' => 'integer',
            'total_minor' => 'integer',
        ];
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(QuoteLine::class)->orderBy('line_no');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    public function total(): Money
    {
        return new Money($this->total_minor, $this->currency);
    }

    public function isEditable(): bool
    {
        return $this->status === self::DRAFT;
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::DRAFT, self::SENT], true);
    }

    /**
     * Past its date and still unanswered.
     *
     * Derived rather than relying on a job having run. A quote does not become
     * valid again because nobody swept the table this morning.
     */
    public function hasExpired(): bool
    {
        return $this->status === self::SENT
            && $this->valid_until !== null
            && $this->valid_until->isPast();
    }

    public function scopeAwaitingAnswer(Builder $q): Builder
    {
        return $q->where('status', self::SENT);
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'id' => $this->public_id,
            'number' => $this->number,
            'version' => $this->version,
            'status' => $this->hasExpired() ? self::EXPIRED : $this->status,
            'issued_on' => $this->issued_on?->toDateString(),
            'valid_until' => $this->valid_until?->toDateString(),
            'has_expired' => $this->hasExpired(),
            'total' => $this->total()->jsonSerialize(),
            'customer' => $this->relationLoaded('customer') ? $this->customer?->toPayload() : null,
            'lines' => $this->relationLoaded('lines') ? $this->lines->map->toPayload()->all() : [],
        ];
    }
}
