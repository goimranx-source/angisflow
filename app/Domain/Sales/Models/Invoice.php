<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Domain\Ledger\Models\JournalEntry;
use App\Domain\Shared\Concerns\HasPublicId;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A demand for payment, and what it was for.
 *
 * ── The four states ──────────────────────────────────────────────────────────
 *
 *   draft   Not a document yet. Editable, invisible to the ledger and to any
 *           figure about what is owed.
 *   issued  Sent to somebody. Totals frozen, posted to the ledger, and from
 *           here on the only permitted changes are payments against it.
 *   paid    Settled in full. Not a separate fact from `issued` so much as a
 *           derived one, but stored because "show me what is unpaid" is the
 *           single most-asked question of this table and it should not be a
 *           computation over every allocation.
 *   void    Cancelled. The ledger posting is reversed rather than deleted, so
 *           an invoice that was issued and cancelled leaves both facts on the
 *           record — which is what somebody looking at a gap in the numbering
 *           needs to find.
 */
class Invoice extends Model
{
    use BelongsToBusiness, HasPublicId;

    public const DRAFT = 'draft';

    public const ISSUED = 'issued';

    public const PAID = 'paid';

    public const VOID = 'void';

    /**
     * The same defaults the columns carry.
     *
     * Stated here as well because a model built with Invoice::create() and not
     * re-read holds null for anything the caller left out — so `$invoice->status`
     * was null on a row the database had quite correctly stored as 'draft', and
     * issue() refused it with "already issued". The column default is what the
     * database does; this is what the object does, and they have to agree.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => self::DRAFT,
        'subtotal_minor' => 0,
        'discount_minor' => 0,
        'tax_minor' => 0,
        'shipping_minor' => 0,
        'total_minor' => 0,
        'paid_minor' => 0,
    ];

    protected $fillable = [
        'public_id', 'account_id', 'business_id', 'customer_id',
        'number', 'issue_date', 'due_date', 'status', 'currency',
        'subtotal_minor', 'discount_minor', 'tax_minor', 'shipping_minor',
        'total_minor', 'paid_minor',
        'reference', 'notes', 'terms',
        'journal_entry_id', 'issued_at', 'voided_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'due_date' => 'date',
            'issued_at' => 'datetime',
            'voided_at' => 'datetime',
            'subtotal_minor' => 'integer',
            'discount_minor' => 'integer',
            'tax_minor' => 'integer',
            'shipping_minor' => 'integer',
            'total_minor' => 'integer',
            'paid_minor' => 'integer',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('line_no');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function total(): Money
    {
        return new Money($this->total_minor, $this->currency);
    }

    public function paid(): Money
    {
        return new Money($this->paid_minor, $this->currency);
    }

    /** What is still owed. Never negative — an overpayment is credit, not a negative debt. */
    public function outstanding(): Money
    {
        return new Money(max(0, $this->total_minor - $this->paid_minor), $this->currency);
    }

    public function isSettled(): bool
    {
        return $this->paid_minor >= $this->total_minor;
    }

    public function isEditable(): bool
    {
        return $this->status === self::DRAFT;
    }

    /**
     * Overdue means owed and past its date — not merely past its date.
     *
     * A paid invoice whose due date has gone is not overdue, and showing it as
     * such is how an aging report loses the trust of the person chasing it.
     */
    public function isOverdue(): bool
    {
        return $this->status === self::ISSUED
            && $this->due_date !== null
            && $this->due_date->isPast()
            && ! $this->isSettled();
    }

    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->where('status', self::ISSUED)->whereColumn('paid_minor', '<', 'total_minor');
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->outstanding()->whereNotNull('due_date')->whereDate('due_date', '<', now()->toDateString());
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'id' => $this->public_id,
            'number' => $this->number,
            'status' => $this->status,
            'issue_date' => $this->issue_date?->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'currency' => $this->currency,
            'total' => $this->total()->jsonSerialize(),
            'paid' => $this->paid()->jsonSerialize(),
            'outstanding' => $this->outstanding()->jsonSerialize(),
            'is_overdue' => $this->isOverdue(),
            'customer' => $this->relationLoaded('customer') ? $this->customer?->toPayload() : null,
            'lines' => $this->relationLoaded('lines') ? $this->lines->map->toPayload()->all() : [],
        ];
    }
}
