<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Models;

use App\Domain\Shared\Concerns\HasPublicId;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One event in the books, and the lines that describe it.
 *
 * ── The three states, and why there is no fourth ─────────────────────────────
 *
 *   draft      Being written. May be edited or thrown away; nothing reads it,
 *              no balance includes it.
 *   posted     A statement about what happened. Immutable from here on.
 *   reversed   Still posted and still true — it happened — but a later entry
 *              has undone its effect, and the two point at each other.
 *
 * There is no "deleted". Removing a posted entry rewrites history, and every
 * figure anybody has already seen, printed, or filed a return against silently
 * becomes wrong with nothing to show what changed. A mistake is corrected by
 * reversing it, which leaves both the error and the correction on the record —
 * which is what an auditor, and a subscriber six months later, actually needs.
 */
class JournalEntry extends Model
{
    use BelongsToBusiness, HasPublicId;

    public const DRAFT = 'draft';

    public const POSTED = 'posted';

    public const REVERSED = 'reversed';

    protected $fillable = [
        'public_id', 'account_id', 'business_id', 'fiscal_year_id',
        'reference', 'entry_date', 'description', 'memo', 'status',
        'source', 'source_platform', 'source_ref',
        'subject_type', 'subject_id',
        'currency', 'base_currency',
        'reverses_entry_id', 'reversed_by_entry_id',
        'posted_at', 'posted_by', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'posted_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class)->orderBy('line_no');
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_entry_id');
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_by_entry_id');
    }

    public function isPosted(): bool
    {
        return in_array($this->status, [self::POSTED, self::REVERSED], true);
    }

    public function isEditable(): bool
    {
        return $this->status === self::DRAFT;
    }

    /** What the entry moved, in its own currency. Debits and credits agree, so either side is the total. */
    public function total(): Money
    {
        return new Money(
            (int) $this->lines->sum('debit_minor'),
            $this->currency,
        );
    }

    /**
     * Whether the lines agree, in both currencies.
     *
     * Checked in the book's currency as well as the entry's: converting each
     * line separately can leave the base amounts a unit apart even when the
     * originals balance exactly, and a trial balance that is off by one poisha
     * is just as unusable as one off by a million.
     */
    public function isBalanced(): bool
    {
        $lines = $this->relationLoaded('lines') ? $this->lines : $this->lines()->get();

        return (int) $lines->sum('debit_minor') === (int) $lines->sum('credit_minor')
            && (int) $lines->sum('base_debit_minor') === (int) $lines->sum('base_credit_minor');
    }

    public function scopePosted(Builder $query): Builder
    {
        return $query->whereIn('status', [self::POSTED, self::REVERSED]);
    }

    public function scopeBetween(Builder $query, string $from, string $to): Builder
    {
        return $query->whereBetween('entry_date', [$from, $to]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'id' => $this->public_id,
            'reference' => $this->reference,
            'date' => $this->entry_date?->toDateString(),
            'description' => $this->description,
            'memo' => $this->memo,
            'status' => $this->status,
            'source' => $this->source,
            'currency' => $this->currency,
            'total' => $this->relationLoaded('lines') ? $this->total()->jsonSerialize() : null,
            'reverses' => $this->reverses?->public_id,
            'reversed_by' => $this->reversedBy?->public_id,
            'posted_at' => $this->posted_at?->toIso8601String(),
            'lines' => $this->relationLoaded('lines')
                ? $this->lines->map->toPayload()->all()
                : [],
        ];
    }
}
