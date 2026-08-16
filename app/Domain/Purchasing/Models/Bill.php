<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Models;

use App\Domain\Ledger\Models\JournalEntry;
use App\Domain\Shared\Concerns\HasPublicId;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Something the business has been charged for.
 *
 * ── Why "approved" and not "issued" ──────────────────────────────────────────
 *
 * An invoice becomes real when you send it, because you are the one making the
 * claim. A bill arrives already made, and the decision on this side is whether
 * you accept it — that somebody looked at what was delivered against what was
 * charged and agreed. Approval is that moment, and it is what posts the
 * liability. A bill entered and not yet approved is a document in a tray, not
 * a debt.
 */
class Bill extends Model
{
    use BelongsToBusiness, HasPublicId;

    public const DRAFT = 'draft';

    public const APPROVED = 'approved';

    public const PAID = 'paid';

    public const VOID = 'void';

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => self::DRAFT,
        'subtotal_minor' => 0,
        'tax_minor' => 0,
        'total_minor' => 0,
        'paid_minor' => 0,
    ];

    protected $fillable = [
        'public_id', 'account_id', 'business_id', 'supplier_id',
        'number', 'supplier_reference', 'bill_date', 'due_date', 'status', 'currency',
        'subtotal_minor', 'tax_minor', 'total_minor', 'paid_minor',
        'notes', 'journal_entry_id', 'approved_at', 'approved_by',
        'voided_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'bill_date' => 'date',
            'due_date' => 'date',
            'approved_at' => 'datetime',
            'voided_at' => 'datetime',
            'subtotal_minor' => 'integer',
            'tax_minor' => 'integer',
            'total_minor' => 'integer',
            'paid_minor' => 'integer',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BillLine::class)->orderBy('line_no');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function total(): Money
    {
        return new Money($this->total_minor, $this->currency);
    }

    public function outstanding(): Money
    {
        return new Money(max(0, $this->total_minor - $this->paid_minor), $this->currency);
    }

    public function isSettled(): bool
    {
        return $this->paid_minor >= $this->total_minor;
    }

    public function isOverdue(): bool
    {
        return $this->status === self::APPROVED
            && $this->due_date !== null
            && $this->due_date->isPast()
            && ! $this->isSettled();
    }

    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->where('status', self::APPROVED)->whereColumn('paid_minor', '<', 'total_minor');
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'id' => $this->public_id,
            'number' => $this->number,
            'supplier_reference' => $this->supplier_reference,
            'status' => $this->status,
            'bill_date' => $this->bill_date?->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'currency' => $this->currency,
            'total' => $this->total()->jsonSerialize(),
            'outstanding' => $this->outstanding()->jsonSerialize(),
            'is_overdue' => $this->isOverdue(),
            'supplier' => $this->relationLoaded('supplier') ? $this->supplier?->toPayload() : null,
        ];
    }
}
