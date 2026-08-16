<?php

namespace App\Models;

use App\Domain\Tenancy\Concerns\BelongsToAccount;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use App\Domain\Shared\Concerns\HasPublicId;
use App\Domain\Shared\ValueObjects\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Employee-specific billing rate for a project.
 *
 * Overrides the project's default rate for specific employees. Useful when:
 * - Senior developers bill at higher rates
 * - Different roles have different value
 * - Special project arrangements
 *
 * Rates are dated to handle changes over project lifetime.
 */
class ProjectEmployeeRate extends Model
{
    use BelongsToAccount, BelongsToBusiness, HasPublicId;

    protected $fillable = [
        'account_id',
        'business_id',
        'project_id',
        'employee_id',
        'rate_minor',
        'effective_from',
        'effective_to',
        'is_active',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'effective_to' => 'date',
        'is_active' => 'boolean',
    ];

    // ── Relationships ───────────────────────────────────────────────────

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    // ── Scopes ──────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeEffectiveOn($query, string $date)
    {
        return $query->where('effective_from', '<=', $date)
            ->where(function ($q) use ($date) {
                $q->whereNull('effective_to')
                  ->orWhere('effective_to', '>=', $date);
            });
    }

    // ── Money accessors ─────────────────────────────────────────────────

    public function getRate(): Money
    {
        // Get currency from project
        $currency = $this->project ? $this->project->currency : 'USD';
        return new Money($this->rate_minor, $currency);
    }

    public function setRate(Money $money): void
    {
        $this->rate_minor = $money->minor;
    }

    // ── Helper methods ──────────────────────────────────────────────────

    /**
     * Check if this rate is effective on a given date.
     */
    public function isEffectiveOn(string $date): bool
    {
        if ($this->effective_from > $date) {
            return false;
        }

        if ($this->effective_to && $this->effective_to < $date) {
            return false;
        }

        return $this->is_active;
    }
}