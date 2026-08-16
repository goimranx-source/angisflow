<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Models;

use App\Domain\Shared\ValueObjects\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One side of one posting.
 *
 * Exactly one of debit_minor and credit_minor is non-zero. A line that is both,
 * or neither, is meaningless — the Ledger service refuses to write one, and
 * nothing else may write these rows at all.
 */
class JournalLine extends Model
{
    protected $fillable = [
        'journal_entry_id', 'ledger_account_id', 'business_id', 'entry_date',
        'line_no', 'description',
        'debit_minor', 'credit_minor', 'currency',
        'base_debit_minor', 'base_credit_minor', 'base_currency', 'exchange_rate',
    ];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'line_no' => 'integer',
            'debit_minor' => 'integer',
            'credit_minor' => 'integer',
            'base_debit_minor' => 'integer',
            'base_credit_minor' => 'integer',
        ];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function ledgerAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class);
    }

    public function isDebit(): bool
    {
        return $this->debit_minor > 0;
    }

    public function side(): string
    {
        return $this->isDebit() ? 'debit' : 'credit';
    }

    /** What this line moved, in the entry's currency, whichever side it is. */
    public function amount(): Money
    {
        return new Money(
            $this->isDebit() ? $this->debit_minor : $this->credit_minor,
            $this->currency,
        );
    }

    /** The same, in the book's currency, at the rate used on the day. */
    public function baseAmount(): Money
    {
        return new Money(
            $this->isDebit() ? $this->base_debit_minor : $this->base_credit_minor,
            $this->base_currency,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'line_no' => $this->line_no,
            'account' => $this->relationLoaded('ledgerAccount')
                ? ['code' => $this->ledgerAccount->code, 'name' => $this->ledgerAccount->name]
                : null,
            'description' => $this->description,
            'side' => $this->side(),
            'amount' => $this->amount()->jsonSerialize(),
            'base_amount' => $this->baseAmount()->jsonSerialize(),
        ];
    }
}
