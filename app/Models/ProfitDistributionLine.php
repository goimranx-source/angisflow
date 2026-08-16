<?php

namespace App\Models;

use App\Domain\Tenancy\Concerns\BelongsToAccount;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use App\Domain\Shared\Concerns\HasPublicId;
use App\Domain\Shared\ValueObjects\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one partner received in one distribution run, and the working behind it.
 */
class ProfitDistributionLine extends Model
{
    use BelongsToAccount, BelongsToBusiness, HasPublicId;

    protected $fillable = [
        'account_id',
        'business_id',
        'profit_distribution_id',
        'partner_id',
        'amount_minor',
        'share_percent',
        'profit_used_minor',
        'transaction_id',
        'segments',
    ];

    protected $casts = [
        'share_percent' => 'decimal:3',
        'segments' => 'array',
    ];

    // ── Relationships ───────────────────────────────────────────────────

    public function distribution(): BelongsTo
    {
        return $this->belongsTo(ProfitDistribution::class, 'profit_distribution_id');
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Ledger\Models\JournalEntry::class, 'transaction_id');
    }

    // ── Money accessors ─────────────────────────────────────────────────

    public function getAmountAttribute(): Money
    {
        return new Money(
            $this->amount_minor,
            $this->distribution->currency ?? 'USD'
        );
    }

    public function setAmountAttribute(Money $money): void
    {
        $this->amount_minor = $money->minor;
    }
}
