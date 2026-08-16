<?php

namespace App\Models;

use App\Domain\Identity\Models\Role;
use App\Domain\Tenancy\Concerns\BelongsToAccount;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A job title, carrying its pay band.
 *
 * Kept apart from department because a packer and a senior packer share a
 * department and not a salary. The band is not anyone's actual pay — it seeds
 * a new hire's figure and shows when someone has drifted outside what the role
 * is worth.
 */
class Position extends Model
{
    use BelongsToAccount, BelongsToBusiness, SoftDeletes;

    protected $fillable = [
        'account_id', 'business_id', 'department_id', 'job_level_id', 'role_id',
        'title', 'salary_min', 'salary_max', 'description', 'is_active',
    ];

    protected $casts = [
        'salary_min' => 'decimal:2',
        'salary_max' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function jobLevel(): BelongsTo
    {
        return $this->belongsTo(JobLevel::class);
    }

    /**
     * What somebody in this job may do in the tool.
     *
     * On the job rather than on the person, because that is the fact that
     * lasts: hire a second Sales Representative and they get the same access
     * without manual setup.
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Is this salary outside what the role is banded at?
     */
    public function isOutsideBand(float $salary): bool
    {
        return ($this->salary_min !== null && $salary < (float) $this->salary_min)
            || ($this->salary_max !== null && $salary > (float) $this->salary_max);
    }

    /**
     * @param  string|null  $currency  ISO code for the symbol — pass the
     *                                 business's `base_currency`. Not read
     *                                 off a relation here to avoid a lazy
     *                                 load; every business can trade in a
     *                                 different currency, and `$` was wrong
     *                                 for all of them but the one it was
     *                                 written for.
     */
    public function bandLabel(?string $currency = null): ?string
    {
        if ($this->salary_min === null && $this->salary_max === null) {
            return null;
        }

        $symbol = \App\Domain\Money\Currencies::symbol($currency ?: 'USD');
        $min = number_format((float) $this->salary_min, 0);
        $max = number_format((float) $this->salary_max, 0);

        return "{$symbol}{$min} – {$symbol}{$max}";
    }
}
