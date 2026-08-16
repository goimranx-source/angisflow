<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Models;

use App\Domain\Shared\Concerns\HasPublicId;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One line of the chart of accounts.
 *
 * ── Normal balance is derived, not stored ────────────────────────────────────
 *
 * Assets and expenses increase on the debit side; liabilities, equity and
 * revenue increase on the credit side. That is not a per-account preference, it
 * follows from the type — so it is computed rather than kept in a column that
 * could be set to disagree with the type beside it.
 */
class LedgerAccount extends Model
{
    use BelongsToBusiness, HasPublicId;

    public const TYPES = ['asset', 'liability', 'equity', 'revenue', 'expense'];

    /** Types whose balance grows on the debit side. */
    public const DEBIT_TYPES = ['asset', 'expense'];

    protected $fillable = [
        'public_id', 'account_id', 'business_id', 'parent_id',
        'code', 'name', 'type', 'subtype', 'description',
        'is_postable', 'is_spendable', 'is_system', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_postable' => 'boolean',
            'is_spendable' => 'boolean',
            'is_system' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('code');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    /** 'debit' or 'credit' — the side this account grows on. */
    public function normalBalance(): string
    {
        return in_array($this->type, self::DEBIT_TYPES, true) ? 'debit' : 'credit';
    }

    /**
     * Whether a positive balance on this account is shown as a debit.
     *
     * Contra accounts invert: sales returns is a revenue account that grows on
     * the debit side, because it is a deduction from sales rather than a cost.
     * Reporting it as an expense would understate both revenue and margin.
     */
    public function isContra(): bool
    {
        return str_starts_with((string) $this->subtype, 'contra_');
    }

    public function scopePostable(Builder $query): Builder
    {
        return $query->where('is_postable', true)->where('is_active', true);
    }

    public function scopeSpendable(Builder $query): Builder
    {
        return $query->where('is_spendable', true)->where('is_active', true);
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    /** Statement order: assets, liabilities, equity, revenue, expenses. */
    public function scopeInStatementOrder(Builder $query): Builder
    {
        return $query->orderBy('code');
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'id' => $this->public_id,
            'code' => $this->code,
            'name' => $this->name,
            'type' => $this->type,
            'subtype' => $this->subtype,
            'description' => $this->description,
            'normal_balance' => $this->normalBalance(),
            'is_postable' => $this->is_postable,
            'is_spendable' => $this->is_spendable,
            'is_system' => $this->is_system,
            'is_active' => $this->is_active,
        ];
    }
}
