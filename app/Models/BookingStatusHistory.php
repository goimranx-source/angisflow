<?php

namespace App\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Concerns\BelongsToAccount;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Booking status change history
 *
 * Audit trail of all status changes for bookings,
 * tracking who made changes and when.
 */
class BookingStatusHistory extends Model
{
    use HasFactory, BelongsToAccount, BelongsToBusiness;

    protected $table = 'booking_status_history';

    protected $fillable = [
        'account_id',
        'business_id', 
        'booking_id',
        'from_status',
        'to_status',
        'reason',
        'changed_by',
        'changed_at',
        'metadata',
    ];

    protected $casts = [
        'changed_at' => 'datetime',
        'metadata' => 'array',
    ];

    // ── Relationships ──────────────────────────────────────────────────────

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    // ── Query Scopes ───────────────────────────────────────────────────────

    public function scopeForStatus($query, string $status)
    {
        return $query->where('to_status', $status);
    }

    public function scopeByUser($query, int $userId)
    {
        return $query->where('changed_by', $userId);
    }

    public function scopeRecent($query, int $days = 30)
    {
        return $query->where('changed_at', '>=', now()->subDays($days));
    }
}