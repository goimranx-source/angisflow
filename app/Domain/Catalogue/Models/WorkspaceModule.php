<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Workspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one workspace actually has switched on.
 *
 * ── Why this is a model and not just a pivot ─────────────────────────────────
 *
 * It carries its own history. `changed_at` and `changed_by` turn "why has this
 * disappeared from my sidebar?" from a support investigation into a lookup —
 * and once the operator panel can toggle modules on somebody's behalf, a
 * change made by us rather than by them has to be attributable to a person.
 *
 * ── Seeded once, then owned by the subscriber ────────────────────────────────
 *
 * Rows here are created at workspace creation from the category's presets and
 * never re-derived from them again. The subscriber is allowed to disagree with
 * our defaults, and re-running the preset would quietly overrule them every
 * time we edited it.
 */
class WorkspaceModule extends Model
{
    protected $fillable = [
        'workspace_id',
        'module_id',
        'is_enabled',
        'changed_at',
        'changed_by',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'changed_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
