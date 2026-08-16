<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Domain\Ledger\Models\JournalEntry;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Shared\Concerns\HasPublicId;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Money that arrived, or went back.
 *
 * ── Why this exists apart from the invoice it settles ────────────────────────
 *
 * Because it frequently settles more than one, or none yet. A customer sends a
 * single transfer covering three invoices; a deposit arrives before anything
 * has been invoiced at all; a payment is received on the last day of the month
 * and applied on the first of the next. Each of those is ordinary, and each is
 * impossible to represent if a payment is a field on an invoice.
 *
 * The consequence worth stating: `amount_minor` is what arrived, and
 * `allocated_minor` is how much of it has been put against something. The
 * difference is credit the customer holds. That gap is a real balance a
 * business owes back, and having somewhere to see it is the difference between
 * books that reconcile and books that nearly do.
 */
class Payment extends Model
{
    use BelongsToBusiness, HasPublicId;

    public const IN = 'in';

    public const OUT = 'out';

    /** @var array<string, mixed> Matching the column defaults — see Invoice. */
    protected $attributes = [
        'direction' => self::IN,
        'method' => 'cash',
        'status' => 'cleared',
        'allocated_minor' => 0,
    ];

    protected $fillable = [
        'public_id', 'account_id', 'business_id', 'customer_id',
        'reference', 'received_on', 'direction', 'method', 'external_ref',
        'deposit_account_id', 'currency', 'amount_minor', 'allocated_minor',
        'status', 'notes', 'journal_entry_id', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'received_on' => 'date',
            'amount_minor' => 'integer',
            'allocated_minor' => 'integer',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function depositAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'deposit_account_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function amount(): Money
    {
        return new Money($this->amount_minor, $this->currency);
    }

    /** Received but not yet applied to anything — the customer's credit. */
    public function unallocated(): Money
    {
        return new Money($this->amount_minor - $this->allocated_minor, $this->currency);
    }

    public function isFullyAllocated(): bool
    {
        return $this->allocated_minor >= $this->amount_minor;
    }

    public function scopeUnapplied(Builder $query): Builder
    {
        return $query->whereColumn('allocated_minor', '<', 'amount_minor');
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'id' => $this->public_id,
            'reference' => $this->reference,
            'received_on' => $this->received_on?->toDateString(),
            'direction' => $this->direction,
            'method' => $this->method,
            'status' => $this->status,
            'amount' => $this->amount()->jsonSerialize(),
            'unallocated' => $this->unallocated()->jsonSerialize(),
            'customer' => $this->relationLoaded('customer') ? $this->customer?->toPayload() : null,
        ];
    }
}
