<?php

namespace App\Models;

use App\Domain\Tenancy\Concerns\BelongsToAccount;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use App\Domain\Shared\Concerns\HasPublicId;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One run of "allocate the profit earned between these dates among the partners".
 */
class ProfitDistribution extends Model
{
    use BelongsToAccount, BelongsToBusiness, HasPublicId;

    protected $fillable = [
        'account_id',
        'business_id',
        'reference',
        'period_from',
        'period_to',
        'net_profit_minor',
        'currency',
        'distributed_minor',
        'note',
        'segments',
        'reversed_at',
        'reversal_reason',
        'recorded_by_user_id',
    ];

    protected $casts = [
        'period_from' => 'date',
        'period_to' => 'date',
        'segments' => 'array',
        'reversed_at' => 'datetime',
    ];

    // ── Relationships ───────────────────────────────────────────────────

    public function lines(): HasMany
    {
        return $this->hasMany(ProfitDistributionLine::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    // ── Scopes ──────────────────────────────────────────────────────────

    /**
     * Only distributions that have not been reversed.
     */
    public function scopeLive($query)
    {
        return $query->whereNull('reversed_at');
    }

    // ── Money accessors ─────────────────────────────────────────────────

    public function getNetProfitAttribute(): Money
    {
        return new Money($this->net_profit_minor, $this->currency);
    }

    public function setNetProfitAttribute(Money $money): void
    {
        $this->net_profit_minor = $money->minor;
        $this->currency = $money->currency;
    }

    public function getDistributedAttribute(): Money
    {
        return new Money($this->distributed_minor, $this->currency);
    }

    public function setDistributedAttribute(Money $money): void
    {
        $this->distributed_minor = $money->minor;
    }

    // ── Helper methods ──────────────────────────────────────────────────

    /**
     * Check if this distribution has been reversed.
     */
    public function isReversed(): bool
    {
        return $this->reversed_at !== null;
    }

    /**
     * Generate the next distribution reference.
     */
    public static function nextReference(int $businessId, string $periodEnd): string
    {
        // Format: DIST-YYYYMM
        $yearMonth = substr(str_replace('-', '', $periodEnd), 0, 6); // YYYYMM
        $base = "DIST-{$yearMonth}";

        $exists = self::where('business_id', $businessId)
            ->where('reference', 'like', "{$base}%")
            ->count();

        if ($exists === 0) {
            return $base;
        }

        // Add suffix: DIST-202407-2, DIST-202407-3, etc.
        return "{$base}-" . ($exists + 1);
    }
}
