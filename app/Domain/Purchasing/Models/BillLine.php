<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Models;

use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Shared\ValueObjects\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One thing being charged for on a bill. */
class BillLine extends Model
{
    protected $attributes = [
        'line_no' => 1, 'quantity' => 1, 'unit_price_minor' => 0,
        'tax_rate' => 0, 'tax_minor' => 0, 'total_minor' => 0,
    ];

    protected $fillable = [
        'bill_id', 'ledger_account_id', 'line_no', 'description',
        'quantity', 'unit_price_minor', 'tax_rate', 'tax_minor', 'total_minor', 'currency',
    ];

    protected function casts(): array
    {
        return [
            'line_no' => 'integer',
            'unit_price_minor' => 'integer',
            'tax_minor' => 'integer',
            'total_minor' => 'integer',
        ];
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }

    public function ledgerAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class);
    }

    /** The cost before tax — what actually lands in the expense account. */
    public function net(): Money
    {
        return new Money($this->total_minor - $this->tax_minor, $this->currency);
    }

    public function total(): Money
    {
        return new Money($this->total_minor, $this->currency);
    }
}
