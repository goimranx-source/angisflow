<?php

namespace App\Models;

use App\Domain\Tenancy\Concerns\BelongsToAccount;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Annual leave entitlements and usage.
 *
 * Separate from attendance so balances persist across years and can be queried
 * independently.
 */
class LeaveBalance extends Model
{
    use BelongsToAccount, BelongsToBusiness;

    protected $fillable = [
        'account_id', 'business_id', 'employee_id', 'year', 'entitled_days',
        'taken_days', 'carried_forward',
    ];

    protected $casts = [
        'year' => 'integer',
        'entitled_days' => 'decimal:2',
        'taken_days' => 'decimal:2',
        'carried_forward' => 'decimal:2',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function scopeForYear($query, int $year)
    {
        return $query->where('year', $year);
    }

    /**
     * How many days remain?
     */
    public function remaining(): float
    {
        return round(
            (float) $this->entitled_days
            + (float) $this->carried_forward
            - (float) $this->taken_days,
            2
        );
    }

    /**
     * Has this person exceeded their entitlement?
     */
    public function isOverdrawn(): bool
    {
        return $this->remaining() < 0;
    }

    /**
     * Total available (entitlement + carried forward).
     */
    public function total(): float
    {
        return round((float) $this->entitled_days + (float) $this->carried_forward, 2);
    }
}
