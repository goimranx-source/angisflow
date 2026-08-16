<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Domain\Shared\ValueObjects\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** How much of one payment settled one invoice. */
class PaymentAllocation extends Model
{
    protected $fillable = [
        'payment_id', 'invoice_id', 'amount_minor', 'currency', 'allocated_on', 'created_by',
    ];

    protected function casts(): array
    {
        return ['allocated_on' => 'date', 'amount_minor' => 'integer'];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function amount(): Money
    {
        return new Money($this->amount_minor, $this->currency);
    }
}
