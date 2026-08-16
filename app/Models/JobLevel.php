<?php

namespace App\Models;

use App\Domain\Shared\Concerns\HasPublicId;
use App\Domain\Tenancy\Concerns\BelongsToAccount;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A grade on the ladder.
 *
 * Job titles do not rank universally. "Officer" outranks "Executive" at one
 * company and the reverse at another. Every large employer therefore grades
 * jobs separately from naming them. This is that grade.
 *
 * Rank 1 is the top, so a new grade can always be added underneath without
 * renumbering everything above it.
 */
class JobLevel extends Model
{
    use BelongsToAccount, BelongsToBusiness, SoftDeletes;

    protected $fillable = [
        'account_id', 'business_id', 'name', 'band', 'rank', 'description', 'is_active',
    ];

    protected $casts = [
        'rank' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * The bands, most senior first.
     *
     * Deliberately industry-neutral. A garment factory's Band O is a machine
     * operator and a bank's is a teller; both are the level where the work
     * actually happens.
     */
    public const BANDS = [
        'executive' => 'Executive',
        'management' => 'Management',
        'professional' => 'Professional',
        'operations' => 'Operations',
    ];

    /**
     * A ladder to start from, which any business can rename or extend.
     *
     * Ten rungs — enough to tell a Deputy Manager from a Manager, few enough
     * that nobody has to look up what a grade means.
     */
    public const DEFAULTS = [
        ['rank' => 1, 'band' => 'executive', 'name' => 'Board', 'description' => 'Chairman, Directors — owns the strategy'],
        ['rank' => 2, 'band' => 'executive', 'name' => 'Chief Executive', 'description' => 'MD, CEO, CFO, COO'],
        ['rank' => 3, 'band' => 'executive', 'name' => 'Division Head', 'description' => 'General Manager, Head of a business unit'],
        ['rank' => 4, 'band' => 'management', 'name' => 'Department Head', 'description' => 'Senior Manager running a function'],
        ['rank' => 5, 'band' => 'management', 'name' => 'Manager', 'description' => 'Runs a team and its numbers'],
        ['rank' => 6, 'band' => 'management', 'name' => 'Assistant Manager', 'description' => 'Deputy Manager, Team Lead, Supervisor'],
        ['rank' => 7, 'band' => 'professional', 'name' => 'Senior Executive', 'description' => 'Senior Officer — works without supervision'],
        ['rank' => 8, 'band' => 'professional', 'name' => 'Executive', 'description' => 'Officer — the standard qualified role'],
        ['rank' => 9, 'band' => 'professional', 'name' => 'Junior Executive', 'description' => 'Trainee, Apprentice, Probationer'],
        ['rank' => 10, 'band' => 'operations', 'name' => 'Operations', 'description' => 'Operator, Packer, Rider, Support staff'],
    ];

    public function positions(): HasMany
    {
        return $this->hasMany(Position::class);
    }

    public function employees(): HasManyThrough
    {
        return $this->hasManyThrough(Employee::class, Position::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function bandLabel(): string
    {
        return self::BANDS[$this->band] ?? ucfirst($this->band);
    }

    /**
     * Whether this grade is a managing one.
     *
     * Used for what a grade may be asked to do — approve, sign off, see a
     * team's figures — never for who reports to whom. That is the reporting
     * line's job.
     */
    public function manages(): bool
    {
        return in_array($this->band, ['executive', 'management'], true);
    }

    public function label(): string
    {
        return "L{$this->rank} · {$this->name}";
    }
}
