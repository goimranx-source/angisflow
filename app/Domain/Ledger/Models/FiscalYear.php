<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Models;

use App\Domain\Shared\Concerns\HasPublicId;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The period the books are kept in, and eventually closed for.
 *
 * Closing is what makes a reported figure stay reported. Without it, a posting
 * dated last March quietly changes a profit somebody has already filed a return
 * against — and nothing anywhere says it moved.
 */
class FiscalYear extends Model
{
    use BelongsToBusiness, HasPublicId;

    protected $fillable = [
        'public_id', 'account_id', 'business_id',
        'name', 'starts_on', 'ends_on', 'is_closed', 'closed_at', 'closed_by',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'is_closed' => 'boolean',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * The year a given date falls in, if one has been set up.
     *
     * Bounds are inclusive on both ends and compared as dates, so a time
     * component on either side cannot push the last day of the year out of it.
     */
    public static function covering(string $date): ?self
    {
        return static::query()
            ->whereDate('starts_on', '<=', $date)
            ->whereDate('ends_on', '>=', $date)
            ->first();
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('is_closed', false);
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'starts_on' => $this->starts_on?->toDateString(),
            'ends_on' => $this->ends_on?->toDateString(),
            'is_closed' => $this->is_closed,
            'closed_at' => $this->closed_at?->toIso8601String(),
        ];
    }
}
