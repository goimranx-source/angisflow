<?php

declare(strict_types=1);

namespace App\Domain\Crm\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One move between stages.
 *
 * Kept so cycle time and drop-off are measurements rather than guesses. The
 * current stage tells you where a deal is; only the history tells you how long
 * deals normally take to get there, which is the number a forecast needs.
 */
class DealStageEvent extends Model
{
    protected $fillable = [
        'deal_id', 'from_stage_id', 'to_stage_id', 'days_in_previous', 'moved_by', 'moved_at',
    ];

    protected function casts(): array
    {
        return ['moved_at' => 'datetime', 'days_in_previous' => 'integer'];
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function fromStage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'from_stage_id');
    }

    public function toStage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'to_stage_id');
    }
}
