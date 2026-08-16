<?php

namespace App\Models;

use App\Domain\Tenancy\Concerns\BelongsToAccount;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use App\Domain\Shared\Concerns\HasPublicId;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a partner's share became, and from which day.
 *
 * Rows are never edited once a distribution has used them — the terms that
 * applied to money already earned are a matter of record, not a setting.
 */
class PartnerSharePeriod extends Model
{
    use BelongsToAccount, BelongsToBusiness, HasPublicId;

    protected $fillable = [
        'account_id',
        'business_id',
        'partner_id',
        'share_percent',
        'effective_from',
        'note',
        'recorded_by_user_id',
    ];

    protected $casts = [
        'share_percent' => 'decimal:3',
        'effective_from' => 'date',
    ];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}
