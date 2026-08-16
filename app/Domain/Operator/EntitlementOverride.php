<?php

declare(strict_types=1);

namespace App\Domain\Operator;

use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Workspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An operator-granted exception to what the plan permits.
 *
 * kind = 'grant'  — this module key is permitted regardless of plan
 * kind = 'revoke' — this module key is blocked regardless of plan
 *
 * Checked by PlanEntitlement after the plan's own feature list, so a grant
 * adds to what the plan allows and a revocation removes from it.
 */
class EntitlementOverride extends Model
{
    public const GRANT  = 'grant';
    public const REVOKE = 'revoke';

    protected $table = 'workspace_entitlement_overrides';

    protected $fillable = [
        'workspace_id', 'account_id', 'module_key',
        'kind', 'granted_by', 'reason', 'expires_at',
    ];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    public function isActive(): bool
    {
        return $this->expires_at === null || $this->expires_at->isFuture();
    }

    public function toPayload(): array
    {
        return [
            'module_key' => $this->module_key,
            'kind'       => $this->kind,
            'reason'     => $this->reason,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'granted_by' => $this->grantedBy->name ?? null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
