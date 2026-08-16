<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Shared\ValueObjects\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing being charged for.
 *
 * The ledger account is per line, not per invoice: goods, delivery and a
 * service call are three different kinds of income and belong in three
 * different revenue accounts. An invoice that posts entirely to "Sales" makes
 * every margin report afterwards a guess.
 */
class InvoiceLine extends Model
{
    protected $fillable = [
        'invoice_id', 'ledger_account_id', 'line_no', 'description',
        'quantity', 'unit', 'unit_price_minor', 'discount_minor',
        'tax_rate', 'tax_minor', 'total_minor', 'currency',
    ];

    protected function casts(): array
    {
        return [
            'line_no' => 'integer',
            'unit_price_minor' => 'integer',
            'discount_minor' => 'integer',
            'tax_minor' => 'integer',
            'total_minor' => 'integer',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function ledgerAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class);
    }

    /** The line's charge before tax. */
    public function net(): Money
    {
        return new Money($this->total_minor - $this->tax_minor, $this->currency);
    }

    public function tax(): Money
    {
        return new Money($this->tax_minor, $this->currency);
    }

    public function total(): Money
    {
        return new Money($this->total_minor, $this->currency);
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'line_no' => $this->line_no,
            'description' => $this->description,
            'quantity' => (float) $this->quantity,
            'unit' => $this->unit,
            'unit_price' => (new Money($this->unit_price_minor, $this->currency))->jsonSerialize(),
            'discount' => (new Money($this->discount_minor, $this->currency))->jsonSerialize(),
            'tax_rate' => (float) $this->tax_rate,
            'tax' => $this->tax()->jsonSerialize(),
            'total' => $this->total()->jsonSerialize(),
        ];
    }
}
