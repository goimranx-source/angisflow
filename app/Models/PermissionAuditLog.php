<?php

namespace App\Models;

use App\Domain\Tenancy\Concerns\BelongsToAccount;
use App\Domain\Shared\Concerns\HasPublicId;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\Role;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Comprehensive audit log for all access control changes
 *
 * Tracks all permission-related activities including role assignments,
 * permission grants/revokes, policy changes, and security events.
 * Provides complete audit trail for compliance and security analysis.
 */
class PermissionAuditLog extends Model
{
    use HasFactory, BelongsToAccount, HasPublicId;

    protected $table = 'permission_audit_log';

    protected $fillable = [
        'account_id',
        'public_id',
        'event_type',
        'entity_type',
        'entity_id',
        'user_id',
        'role_id',
        'permission_key',
        'business_id',
        'old_values',
        'new_values',
        'change_reason',
        'performed_by',
        'performed_via',
        'ip_address',
        'user_agent',
        'risk_level',
        'requires_review',
        'is_privileged_operation',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'requires_review' => 'boolean',
        'is_privileged_operation' => 'boolean',
    ];

    public const EVENT_TYPES = [
        'role_assigned' => 'Role Assigned',
        'role_removed' => 'Role Removed',
        'role_assignment_extended' => 'Role Assignment Extended',
        'role_assignment_deactivated' => 'Role Assignment Deactivated',
        'permission_granted' => 'Permission Granted',
        'permission_revoked' => 'Permission Revoked',
        'policy_created' => 'Policy Created',
        'policy_updated' => 'Policy Updated',
        'policy_activated' => 'Policy Activated',
        'policy_deactivated' => 'Policy Deactivated',
        'emergency_access_granted' => 'Emergency Access Granted',
        'emergency_access_expired' => 'Emergency Access Expired',
        'session_elevated' => 'Session Elevated',
        'session_elevation_expired' => 'Session Elevation Expired',
        'user_created' => 'User Created',
        'user_deactivated' => 'User Deactivated',
        'user_reactivated' => 'User Reactivated',
    ];

    public const ENTITY_TYPES = [
        'user' => 'User',
        'role' => 'Role',
        'permission' => 'Permission',
        'policy' => 'Policy',
        'user_role_assignment' => 'Role Assignment',
        'session' => 'Access Session',
    ];

    public const RISK_LEVELS = [
        'low' => 'Low Risk',
        'medium' => 'Medium Risk',
        'high' => 'High Risk',
        'critical' => 'Critical Risk',
    ];

    public const PERFORMED_VIA = [
        'web' => 'Web Interface',
        'api' => 'API',
        'system' => 'System Process',
        'import' => 'Data Import',
        'migration' => 'Database Migration',
        'cli' => 'Command Line',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    // No permission() relation: there is no `permissions` table. What
    // capability this event concerned is recorded as a plain string in
    // permission_key (see App\Support\Capabilities), not a foreign key —
    // see 2026_08_15_120000_fix_permission_audit_log_permission_column.php.

    public function business(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Tenancy\Models\Business::class);
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    /**
     * Get formatted event description
     */
    public function getEventDescription(): string
    {
        $base = $this->getEventTypeLabel();
        
        if ($this->user) {
            $base .= " for {$this->user->name}";
        }
        
        if ($this->role) {
            $base .= " ({$this->role->name} role)";
        }
        
        if ($this->business) {
            $base .= " in {$this->business->name}";
        }
        
        return $base;
    }

    /**
     * Get changes summary
     */
    public function getChangesSummary(): array
    {
        $changes = [];
        
        if ($this->old_values && $this->new_values) {
            foreach ($this->new_values as $field => $newValue) {
                $oldValue = $this->old_values[$field] ?? null;
                if ($oldValue !== $newValue) {
                    $changes[$field] = [
                        'old' => $oldValue,
                        'new' => $newValue,
                    ];
                }
            }
        } elseif ($this->new_values) {
            // New creation
            $changes = $this->new_values;
        }
        
        return $changes;
    }

    /**
     * Check if event is high risk
     */
    public function isHighRisk(): bool
    {
        return in_array($this->risk_level, ['high', 'critical'], true) || 
               $this->is_privileged_operation ||
               $this->event_type === 'emergency_access_granted';
    }

    /**
     * Check if event affects security
     */
    public function affectsSecurity(): bool
    {
        return in_array($this->event_type, [
            'role_assigned',
            'role_removed',
            'permission_granted',
            'permission_revoked',
            'emergency_access_granted',
            'session_elevated',
            'user_created',
            'user_deactivated',
        ], true);
    }

    /**
     * Get actor information
     */
    public function getActorInfo(): string
    {
        if ($this->performedBy) {
            return $this->performedBy->name;
        }
        
        return match ($this->performed_via) {
            'system' => 'System Process',
            'migration' => 'Database Migration',
            'import' => 'Data Import',
            default => 'Unknown',
        };
    }

    public function getEventTypeLabel(): string
    {
        return self::EVENT_TYPES[$this->event_type] ?? ucfirst(str_replace('_', ' ', $this->event_type));
    }

    public function getEntityTypeLabel(): string
    {
        return self::ENTITY_TYPES[$this->entity_type] ?? ucfirst(str_replace('_', ' ', $this->entity_type));
    }

    public function getRiskLevelLabel(): string
    {
        return self::RISK_LEVELS[$this->risk_level] ?? ucfirst($this->risk_level);
    }

    public function getPerformedViaLabel(): string
    {
        return self::PERFORMED_VIA[$this->performed_via] ?? ucfirst($this->performed_via);
    }

    /**
     * Scope for high-risk events
     */
    public function scopeHighRisk($query)
    {
        return $query->whereIn('risk_level', ['high', 'critical'])
                    ->orWhere('is_privileged_operation', true);
    }

    /**
     * Scope for events needing review
     */
    public function scopeNeedsReview($query)
    {
        return $query->where('requires_review', true);
    }

    /**
     * Scope for specific user events
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId)
                    ->orWhere('performed_by', $userId);
    }

    /**
     * Scope for specific business events
     */
    public function scopeForBusiness($query, int $businessId)
    {
        return $query->where('business_id', $businessId);
    }

    /**
     * Scope for date range
     */
    public function scopeInDateRange($query, \Carbon\Carbon $startDate, \Carbon\Carbon $endDate)
    {
        return $query->whereBetween('created_at', [
            $startDate->startOfDay(),
            $endDate->endOfDay(),
        ]);
    }

    /**
     * Log a permission event
     */
    public static function logEvent(
        string $eventType,
        string $entityType,
        string $entityId,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $reason = null,
        ?int $userId = null,
        ?int $roleId = null,
        ?string $permissionKey = null,
        ?int $businessId = null,
        ?int $performedBy = null,
        string $riskLevel = 'medium',
        bool $requiresReview = false
    ): self {
        $request = request();

        return self::create([
            // account_id is deliberately omitted, not defaulted to 1: the
            // BelongsToAccount trait fills it from app(TenantContext::class)
            // on create, which is correct for both an authenticated web
            // request and a console/system operation running under
            // TenantContext::runAs(). A hardcoded fallback here would have
            // written every system-originated audit row into account 1.
            'event_type' => $eventType,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'user_id' => $userId,
            'role_id' => $roleId,
            'permission_key' => $permissionKey,
            'business_id' => $businessId,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'change_reason' => $reason,
            'performed_by' => $performedBy ?? auth()->id(),
            'performed_via' => $request ? 'web' : 'system',
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'risk_level' => $riskLevel,
            'requires_review' => $requiresReview,
            'is_privileged_operation' => in_array($eventType, [
                'emergency_access_granted',
                'session_elevated',
                'user_created',
            ], true),
        ]);
    }

    /**
     * Get audit statistics
     */
    public static function getAuditStats(?\Carbon\Carbon $startDate = null, ?\Carbon\Carbon $endDate = null): array
    {
        $query = self::query();
        
        if ($startDate && $endDate) {
            $query->inDateRange($startDate, $endDate);
        }
        
        $stats = $query->selectRaw('
            event_type,
            risk_level,
            COUNT(*) as count,
            COUNT(CASE WHEN requires_review THEN 1 END) as needs_review_count
        ')
        ->groupBy(['event_type', 'risk_level'])
        ->get();
        
        return [
            'total_events' => $stats->sum('count'),
            'high_risk_events' => $stats->whereIn('risk_level', ['high', 'critical'])->sum('count'),
            'needs_review_count' => $stats->sum('needs_review_count'),
            'event_breakdown' => $stats->groupBy('event_type')->map(function ($group) {
                return [
                    'total' => $group->sum('count'),
                    'high_risk' => $group->whereIn('risk_level', ['high', 'critical'])->sum('count'),
                    'needs_review' => $group->sum('needs_review_count'),
                ];
            })->toArray(),
        ];
    }
}