<?php

namespace App\Models;

use App\Domain\Tenancy\Concerns\BelongsToAccount;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use App\Domain\Shared\Concerns\HasPublicId;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Historical record of project budget changes.
 *
 * Maintains audit trail of budget revisions for:
 * - Scope change tracking
 * - Budget approval workflows
 * - Project history analysis
 * - Client communication records
 */
class ProjectBudgetRevision extends Model
{
    use BelongsToAccount, BelongsToBusiness, HasPublicId;

    protected $fillable = [
        'account_id',
        'business_id',
        'project_id',
        'budget_hours',
        'budget_amount_minor',
        'currency',
        'reason',
        'effective_from',
        'revised_by_user_id',
    ];

    protected $casts = [
        'budget_hours' => 'decimal:2',
        'effective_from' => 'date',
    ];

    // ── Relationships ───────────────────────────────────────────────────

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function revisedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revised_by_user_id');
    }

    // ── Money accessors ─────────────────────────────────────────────────

    public function getBudgetAmount(): ?Money
    {
        if ($this->budget_amount_minor === null) {
            return null;
        }

        return new Money($this->budget_amount_minor, $this->currency);
    }

    public function setBudgetAmount(?Money $money): void
    {
        if ($money === null) {
            $this->budget_amount_minor = null;
        } else {
            $this->budget_amount_minor = $money->minor;
            $this->currency = $money->currency;
        }
    }
}