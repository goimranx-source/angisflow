<?php

declare(strict_types=1);

namespace App\Domain\Activity\Models;

use App\Domain\Tenancy\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;

/**
 * One thing that happened, written once and never touched again.
 *
 * ── Why this model does so little ────────────────────────────────────────────
 *
 * Because the table it sits on is the one designed to survive billions of rows,
 * and every convenience Eloquent normally adds costs something at that size.
 *
 * There are no timestamps: `created_at` would duplicate `occurred_at` and
 * `updated_at` would describe an event that never happens, since a row here is
 * a statement about the past and the past does not get amended.
 *
 * There is no soft delete. Deleting history to tidy it up is the one thing an
 * audit trail must not permit, and a `deleted_at` column is an invitation.
 *
 * And nothing writes through this model on the request path. Rows arrive in one
 * multi-row insert per request through Activity, which is the difference
 * between an audit trail and a tax on every write in the system.
 */
class ActivityEvent extends Model
{
    use BelongsToAccount;

    protected $table = 'activity_events';

    public $timestamps = false;

    protected $fillable = [
        'account_id',
        'business_id',
        'actor_user_id',
        'subject_type',
        'subject_id',
        'verb',
        'context',
        'occurred_at',
        'occurred_on',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'occurred_at' => 'datetime',
            'occurred_on' => 'date',
        ];
    }

    /**
     * Everything that ever happened to one record, oldest first.
     *
     * Ordered by id rather than by occurred_at: several events written in the
     * same request share a timestamp to the millisecond, and a timeline that
     * shows a status change above the save that caused it reads as nonsense.
     * The id is the order they were recorded in, which is the order they
     * happened in.
     */
    public function scopeForSubject(mixed $query, string $type, int $id): mixed
    {
        /*
         * By when it happened, and by id only to break ties.
         *
         * Ordering by id alone worked for as long as every row was appended in
         * the order things happened, which was true until a line could be
         * written for something that happened before it — a dispatch recovered
         * from the shipment that recorded it. Sorted by id, that line lands at
         * the foot of the timeline dated three days earlier, which reads as the
         * timeline being broken rather than as the row being late.
         *
         * The id still decides between two events in the same millisecond,
         * where it is the only thing that knows which came first.
         */
        return $query
            ->where('subject_type', $type)
            ->where('subject_id', $id)
            ->orderBy('occurred_at')
            ->orderBy('id');
    }
}
