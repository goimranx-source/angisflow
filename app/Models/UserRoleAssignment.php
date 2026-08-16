<?php

namespace App\Models;

use App\Domain\Tenancy\Concerns\BelongsToAccount;
use App\Domain\Shared\Concerns\HasPublicId;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\Role;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Carbon\Carbon;

/**
 * Extended role assignment with business context and advanced features
 *
 * Provides enhanced role assignments with business-specific scope, time limits,
 * approval workflows, and emergency access capabilities. Complements the existing
 * simple user.role_id relationship with full audit trail and governance.
 */
class UserRoleAssignment extends Model
{
    use HasFactory, BelongsToAccount, SoftDeletes;

    protected $fillable = [
        'account_id',
        'user_id',
        'role_id',
        'business_id',
        'assignment_scope',
        'scope_id',
        'is_active',
        'effective_from',
        'effective_until',
        'scope_restrictions',
        'assigned_by',
        'approved_by',
        'assigned_at',
        'approved_at',
        'assignment_reason',
        'is_emergency_access',
        'emergency_expires_at',
        'emergency_justification',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'effective_from' => 'date',
        'effective_until' => 'date',
        'scope_restrictions' => 'array',
        'assigned_at' => 'datetime',
        'approved_at' => 'datetime',
        'is_emergency_access' => 'boolean',
        'emergency_expires_at' => 'datetime',
    ];

    public const ASSIGNMENT_SCOPES = [
        'account' => 'Account-wide Access',
        'business' => 'Business-specific Access',
        'location' => 'Location-specific Access',
        'department' => 'Department-specific Access',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Tenancy\Models\Business::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Check if assignment is currently effective
     */
    public function isEffective(?Carbon $at = null): bool
    {
        $at = $at ?? now();
        
        if (!$this->is_active) {
            return false;
        }

        if ($this->effective_from && $at->isBefore($this->effective_from)) {
            return false;
        }

        if ($this->effective_until && $at->isAfter($this->effective_until)) {
            return false;
        }

        // Check emergency access expiry
        if ($this->is_emergency_access && $this->emergency_expires_at && $at->isAfter($this->emergency_expires_at)) {
            return false;
        }

        return true;
    }

    /**
     * Check if assignment is expired
     */
    public function isExpired(?Carbon $at = null): bool
    {
        $at = $at ?? now();
        
        if ($this->effective_until && $at->isAfter($this->effective_until)) {
            return true;
        }

        if ($this->is_emergency_access && $this->emergency_expires_at && $at->isAfter($this->emergency_expires_at)) {
            return true;
        }

        return false;
    }

    /**
     * Check if assignment needs approval
     */
    public function needsApproval(): bool
    {
        return $this->assigned_at && !$this->approved_at;
    }

    /**
     * Approve the assignment
     */
    public function approve(int $approvedById, ?string $reason = null): void
    {
        $this->update([
            'approved_by' => $approvedById,
            'approved_at' => now(),
            'assignment_reason' => $reason ? 
                ($this->assignment_reason ? $this->assignment_reason . "\nApproval: " . $reason : "Approval: " . $reason) :
                $this->assignment_reason,
        ]);
    }

    /**
     * Extend assignment duration
     */
    public function extend(Carbon $newEndDate, int $extendedById, ?string $reason = null): void
    {
        $this->update([
            'effective_until' => $newEndDate,
            'assignment_reason' => $reason ? 
                ($this->assignment_reason ? $this->assignment_reason . "\nExtended: " . $reason : "Extended: " . $reason) :
                $this->assignment_reason,
        ]);

        // Log the extension
        PermissionAuditLog::create([
            'account_id' => $this->account_id,
            'event_type' => 'role_assignment_extended',
            'entity_type' => 'user_role_assignment',
            'entity_id' => (string) $this->id,
            'user_id' => $this->user_id,
            'role_id' => $this->role_id,
            'business_id' => $this->business_id,
            'new_values' => ['effective_until' => $newEndDate->toDateString()],
            'change_reason' => $reason,
            'performed_by' => $extendedById,
        ]);
    }

    /**
     * Deactivate assignment
     */
    public function deactivate(int $deactivatedById, ?string $reason = null): void
    {
        $this->update([
            'is_active' => false,
            'assignment_reason' => $reason ? 
                ($this->assignment_reason ? $this->assignment_reason . "\nDeactivated: " . $reason : "Deactivated: " . $reason) :
                $this->assignment_reason,
        ]);

        // Log the deactivation
        PermissionAuditLog::create([
            'account_id' => $this->account_id,
            'event_type' => 'role_assignment_deactivated',
            'entity_type' => 'user_role_assignment',
            'entity_id' => (string) $this->id,
            'user_id' => $this->user_id,
            'role_id' => $this->role_id,
            'business_id' => $this->business_id,
            'change_reason' => $reason,
            'performed_by' => $deactivatedById,
        ]);
    }

    /**
     * Get formatted assignment duration
     */
    public function getDuration(): string
    {
        if (!$this->effective_until) {
            return 'Permanent';
        }

        if ($this->effective_until->isPast()) {
            return 'Expired';
        }

        return 'Until ' . $this->effective_until->format('M j, Y');
    }

    /**
     * Get assignment scope label
     */
    public function getScopeLabel(): string
    {
        $base = self::ASSIGNMENT_SCOPES[$this->assignment_scope] ?? ucfirst($this->assignment_scope);
        
        if ($this->scope_id) {
            $base .= " (ID: {$this->scope_id})";
        }
        
        return $base;
    }

    /**
     * Check if assignment has restrictions
     */
    public function hasRestrictions(): bool
    {
        return !empty($this->scope_restrictions);
    }

    /**
     * Scope for effective assignments only
     */
    public function scopeEffective($query, ?Carbon $at = null)
    {
        $at = $at ?? now();
        
        return $query->where('is_active', true)
                    ->where(function ($q) use ($at) {
                        $q->whereNull('effective_from')
                          ->orWhere('effective_from', '<=', $at->toDateString());
                    })
                    ->where(function ($q) use ($at) {
                        $q->whereNull('effective_until')
                          ->orWhere('effective_until', '>=', $at->toDateString());
                    })
                    ->where(function ($q) use ($at) {
                        $q->where('is_emergency_access', false)
                          ->orWhereNull('emergency_expires_at')
                          ->orWhere('emergency_expires_at', '>=', $at->toDateTimeString());
                    });
    }

    /**
     * Scope for assignments needing approval
     */
    public function scopeNeedsApproval($query)
    {
        return $query->whereNotNull('assigned_at')
                    ->whereNull('approved_at');
    }

    /**
     * Scope for emergency access assignments
     */
    public function scopeEmergencyAccess($query)
    {
        return $query->where('is_emergency_access', true);
    }

    /**
     * Create assignment with audit trail
     */
    public static function createWithAudit(array $attributes, int $assignedBy): self
    {
        $attributes['assigned_by'] = $assignedBy;
        $attributes['assigned_at'] = now();
        
        $assignment = self::create($attributes);
        
        // Log the assignment
        PermissionAuditLog::create([
            'account_id' => $assignment->account_id,
            'event_type' => 'role_assigned',
            'entity_type' => 'user_role_assignment',
            'entity_id' => (string) $assignment->id,
            'user_id' => $assignment->user_id,
            'role_id' => $assignment->role_id,
            'business_id' => $assignment->business_id,
            'new_values' => $assignment->only(['assignment_scope', 'effective_from', 'effective_until']),
            'change_reason' => $assignment->assignment_reason,
            'performed_by' => $assignedBy,
            'risk_level' => $assignment->is_emergency_access ? 'high' : 'medium',
        ]);
        
        return $assignment;
    }
}