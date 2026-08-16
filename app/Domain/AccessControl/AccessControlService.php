<?php

declare(strict_types=1);

namespace App\Domain\AccessControl;

use App\Domain\Tenancy\TenantContext;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\Role;
use App\Models\UserRoleAssignment;
use App\Models\PermissionPolicy;
use App\Models\PermissionAuditLog;
use App\Models\AccessControlSession;
use App\Support\Capabilities;
use Illuminate\Support\Collection;
use Carbon\Carbon;

/**
 * Enhanced access control service integrating with existing capabilities system
 *
 * Provides comprehensive access control management while maintaining compatibility
 * with the existing role and capability system. Adds advanced features like
 * business-scoped permissions, time-limited access, policy-based control,
 * and comprehensive audit trails.
 *
 * ── Wiring decision (task 31 fix pass) ────────────────────────────────────────
 *
 * This class, its four tables (user_role_assignments, permission_policies,
 * permission_audit_log, access_control_sessions), and its capability mapping
 * had zero controller, zero route, and zero Gate::define() reference anywhere
 * in the app — every real authorisation check goes through
 * `AuthorizationServiceProvider` / `Gate::define()` and `App\Support\Capabilities`,
 * wired into every route via `middleware('can:...')`, and that system works.
 *
 * Ripping this out and replacing the working Gate system, or wiring it in as a
 * second live enforcement path with no caller and no test coverage, would be a
 * far bigger and riskier change than a fix pass should make on its own
 * judgement. The decision here is narrower: leave this as correct, self-
 * consistent infrastructure for a business-scoped, time-limited, policy-driven
 * override layer that a future task can wire behind a real UI — but make sure
 * nothing in it is actually broken (the dangling `permissions` table FK, the
 * lazy-loading violations below) so that whoever wires it in next inherits
 * working code rather than a second bug hunt.
 */
class AccessControlService
{
    public function __construct(
        private TenantContext $tenantContext
    ) {}

    /**
     * Assign role to user with enhanced options
     */
    public function assignRole(
        int $userId,
        int $roleId,
        ?int $businessId = null,
        array $options = []
    ): UserRoleAssignment {
        $assignment = UserRoleAssignment::createWithAudit([
            'account_id' => $this->tenantContext->account()->id,
            'user_id' => $userId,
            'role_id' => $roleId,
            'business_id' => $businessId,
            'assignment_scope' => $options['scope'] ?? 'business',
            'scope_id' => $options['scope_id'] ?? null,
            'effective_from' => $options['effective_from'] ?? now(),
            'effective_until' => $options['effective_until'] ?? null,
            'scope_restrictions' => $options['restrictions'] ?? null,
            'assignment_reason' => $options['reason'] ?? null,
            'is_emergency_access' => $options['emergency'] ?? false,
            'emergency_expires_at' => $options['emergency_expires_at'] ?? null,
            'emergency_justification' => $options['emergency_justification'] ?? null,
        ], auth()->id());

        // Update user's primary role if this is their first role
        $user = User::find($userId);
        if (!$user->role_id) {
            $user->update(['role_id' => $roleId]);
        }

        // Clear any cached permissions for this user
        $this->clearUserPermissionCache($userId);

        return $assignment;
    }

    /**
     * Remove role assignment
     */
    public function removeRoleAssignment(int $assignmentId, ?string $reason = null): bool
    {
        $assignment = UserRoleAssignment::findOrFail($assignmentId);
        
        // Log the removal
        PermissionAuditLog::logEvent(
            'role_removed',
            'user_role_assignment',
            (string) $assignment->id,
            $assignment->toArray(),
            null,
            $reason,
            $assignment->user_id,
            $assignment->role_id,
            null,
            $assignment->business_id,
            auth()->id(),
            'medium'
        );

        $userId = $assignment->user_id;
        $removed = $assignment->delete();

        if ($removed) {
            $this->clearUserPermissionCache($userId);
        }

        return $removed;
    }

    /**
     * Check if user has capability in business context
     */
    public function userCan(
        int $userId,
        string $capability,
        ?int $businessId = null,
        array $context = []
    ): bool {
        $user = User::with('role')->find($userId);
        if (!$user) {
            return false;
        }

        /*
         * Defer to the live capability system first, rather than re-deriving
         * the answer from role_id alone.
         *
         * An account owner has no role_id and no role assignments — ownership
         * is what grants them everything, and `hasCapability()` is where that
         * is expressed. Asking only about roles therefore denied the one user
         * who can never legitimately be denied, so this class disagreed with
         * the Gates the rest of the application actually enforces. A layer
         * meant to *extend* authorisation must never contradict its base.
         *
         * Policies still apply on top: this establishes that the capability is
         * held, not that every contextual restriction has been satisfied.
         */
        if ($user->hasCapability($capability)) {
            return $this->checkPolicies($user, $capability, $businessId, $context);
        }

        // Check primary role capability (existing system)
        if ($user->role && $user->role->grants($capability)) {
            return $this->checkPolicies($user, $capability, $businessId, $context);
        }

        // Check extended role assignments
        $assignments = $this->getUserEffectiveRoleAssignments($userId, $businessId);
        
        foreach ($assignments as $assignment) {
            if ($assignment->role->grants($capability)) {
                return $this->checkPolicies($user, $capability, $businessId, array_merge($context, [
                    'assignment_scope' => $assignment->assignment_scope,
                    'scope_restrictions' => $assignment->scope_restrictions,
                ]));
            }
        }

        return false;
    }

    /**
     * Get user's effective role assignments
     */
    public function getUserEffectiveRoleAssignments(int $userId, ?int $businessId = null): Collection
    {
        $query = UserRoleAssignment::where('user_id', $userId)
            ->with('role')
            ->effective();

        if ($businessId) {
            $query->where(function ($q) use ($businessId) {
                $q->where('business_id', $businessId)
                  ->orWhereNull('business_id'); // Account-wide assignments
            });
        }

        return $query->get();
    }

    /**
     * Get all capabilities for user in business context
     */
    public function getUserCapabilities(int $userId, ?int $businessId = null): array
    {
        $capabilities = [];

        $user = User::with('role')->find($userId);

        // The live system's answer is the floor, for the same reason userCan()
        // consults it: an owner's capabilities come from ownership, not a role
        // row, and reporting an empty set for them would be plainly wrong.
        if ($user) {
            $capabilities = array_merge($capabilities, $user->capabilityList());
        }

        if ($user && $user->role) {
            $capabilities = array_merge($capabilities, $user->role->capabilities ?? []);
        }

        // Add capabilities from extended role assignments
        $assignments = $this->getUserEffectiveRoleAssignments($userId, $businessId);
        
        foreach ($assignments as $assignment) {
            $roleCaps = $assignment->role->capabilities ?? [];
            $capabilities = array_merge($capabilities, $roleCaps);
        }

        return array_unique($capabilities);
    }

    /**
     * Check policies for additional access control
     */
    protected function checkPolicies(
        User $user,
        string $capability,
        ?int $businessId = null,
        array $context = []
    ): bool {
        $policies = PermissionPolicy::where('account_id', $user->account_id)
            ->active()
            ->byPriority()
            ->get();

        if ($businessId) {
            $policies = $policies->where('business_id', $businessId)
                                 ->concat($policies->whereNull('business_id'));
        }

        $fullContext = array_merge($context, [
            'user_id' => $user->id,
            'capability' => $capability,
            'business_id' => $businessId,
            'user_roles' => $this->getUserRoleNames($user->id, $businessId),
            'user_capabilities' => $this->getUserCapabilities($user->id, $businessId),
            'current_time' => now(),
        ]);

        foreach ($policies as $policy) {
            $effect = $policy->getAccessEffect($fullContext);
            
            if ($effect === 'deny') {
                return false;
            }
            
            if ($effect === 'require_approval') {
                // For now, treat as denied - could implement approval workflow
                return false;
            }
            
            if ($effect === 'require_mfa') {
                // Check if user has completed MFA (simplified)
                if (!($context['mfa_verified'] ?? false)) {
                    return false;
                }
            }
        }

        return true; // No denying policies found
    }

    /**
     * Get user role names
     */
    protected function getUserRoleNames(int $userId, ?int $businessId = null): array
    {
        $roles = [];

        $user = User::with('role')->find($userId);

        // Ownership is a role in every sense that matters to a policy, even
        // though it is a flag rather than a row. Omitting it left policies
        // unable to name the one account holder they most often need to.
        if ($user && $user->is_owner) {
            $roles[] = 'owner';
        }

        if ($user && $user->role) {
            $roles[] = $user->role->slug;
        }

        $assignments = $this->getUserEffectiveRoleAssignments($userId, $businessId);
        foreach ($assignments as $assignment) {
            $roles[] = $assignment->role->slug;
        }

        return array_unique($roles);
    }

    /**
     * Create emergency access assignment
     */
    public function grantEmergencyAccess(
        int $userId,
        int $roleId,
        int $durationMinutes,
        string $justification,
        ?int $businessId = null
    ): UserRoleAssignment {
        return $this->assignRole($userId, $roleId, $businessId, [
            'emergency' => true,
            'emergency_expires_at' => now()->addMinutes($durationMinutes),
            'emergency_justification' => $justification,
            'reason' => "Emergency access: {$justification}",
            'effective_until' => now()->addMinutes($durationMinutes),
        ]);
    }

    /**
     * Elevate user session for temporary admin access
     */
    public function elevateSession(
        string $sessionId,
        int $durationMinutes = 15,
        ?string $reason = null
    ): bool {
        $session = AccessControlSession::findBySessionId($sessionId);
        
        if (!$session) {
            return false;
        }

        $session->elevate($durationMinutes, $reason);
        return true;
    }

    /**
     * Get or create access control session
     */
    public function getOrCreateSession(
        User $user,
        string $sessionId,
        ?int $businessId = null
    ): AccessControlSession {
        $session = AccessControlSession::findBySessionId($sessionId);
        
        if (!$session) {
            $session = AccessControlSession::createForUser(
                $user,
                $sessionId,
                $businessId
            );
        }

        // Refresh permissions if needed
        if ($session->needsPermissionRefresh()) {
            $this->refreshSessionPermissions($session);
        }

        return $session;
    }

    /**
     * Refresh session permissions cache
     */
    public function refreshSessionPermissions(AccessControlSession $session): void
    {
        $capabilities = $this->getUserCapabilities(
            $session->user_id,
            $session->business_id
        );

        // Convert capabilities to permission format for caching
        $permissions = [];
        foreach ($capabilities as $capability) {
            $permissions[$capability] = true;
        }

        // Add special permissions for elevated sessions
        if ($session->isElevated()) {
            $permissions[Capabilities::ALL] = true;
        }

        $session->updateCachedPermissions($permissions);
        
        // Update active roles
        $roleNames = $this->getUserRoleNames($session->user_id, $session->business_id);
        $session->updateActiveRoles($roleNames);
    }

    /**
     * Clear user permission cache
     */
    public function clearUserPermissionCache(int $userId): void
    {
        AccessControlSession::where('user_id', $userId)
            ->each(function ($session) {
                $session->clearPermissions();
            });
    }

    /**
     * Get permission audit trail for user
     */
    public function getUserAuditTrail(
        int $userId,
        ?Carbon $startDate = null,
        ?Carbon $endDate = null,
        int $limit = 100
    ): Collection {
        $query = PermissionAuditLog::forUser($userId)
            ->orderBy('created_at', 'desc')
            ->limit($limit);

        if ($startDate && $endDate) {
            $query->inDateRange($startDate, $endDate);
        }

        return $query->get();
    }

    /**
     * Get access control statistics
     */
    public function getAccessControlStats(?int $businessId = null): array
    {
        $baseQuery = UserRoleAssignment::where('account_id', $this->tenantContext->account()->id);
        
        if ($businessId) {
            $baseQuery->where('business_id', $businessId);
        }

        $effectiveAssignments = $baseQuery->effective()->count();
        $expiredAssignments = $baseQuery->where('effective_until', '<', now())->count();
        $emergencyAssignments = $baseQuery->emergencyAccess()->effective()->count();

        $activeSessions = AccessControlSession::where('account_id', $this->tenantContext->account()->id)
            ->active()
            ->count();

        $elevatedSessions = AccessControlSession::where('account_id', $this->tenantContext->account()->id)
            ->elevated()
            ->count();

        $auditEvents = PermissionAuditLog::where('account_id', $this->tenantContext->account()->id)
            ->where('created_at', '>', now()->subDays(7))
            ->count();

        return [
            'effective_role_assignments' => $effectiveAssignments,
            'expired_assignments' => $expiredAssignments,
            'emergency_assignments' => $emergencyAssignments,
            'active_sessions' => $activeSessions,
            'elevated_sessions' => $elevatedSessions,
            'recent_audit_events' => $auditEvents,
        ];
    }

    /**
     * Cleanup expired access
     */
    public function cleanupExpiredAccess(): array
    {
        $results = [
            'expired_assignments' => 0,
            'expired_sessions' => 0,
            'expired_emergencies' => 0,
        ];

        // Deactivate expired role assignments
        $expiredAssignments = UserRoleAssignment::where('effective_until', '<', now())
            ->where('is_active', true)
            ->get();

        foreach ($expiredAssignments as $assignment) {
            $assignment->deactivate(0, 'Automatic expiration');
            $results['expired_assignments']++;
        }

        // Handle expired emergency access
        $expiredEmergencies = UserRoleAssignment::emergencyAccess()
            ->where('emergency_expires_at', '<', now())
            ->where('is_active', true)
            ->get();

        foreach ($expiredEmergencies as $assignment) {
            $assignment->deactivate(0, 'Emergency access expired');
            $results['expired_emergencies']++;
        }

        // Clean up expired sessions
        $results['expired_sessions'] = AccessControlSession::cleanupExpiredSessions();

        return $results;
    }

    /**
     * Generate capability-permission mapping
     */
    public function getCapabilityPermissionMapping(): array
    {
        return [
            // Map existing capabilities to new permission structure
            'orders.view' => ['sales.orders.view'],
            'orders.create' => ['sales.orders.create'],
            'orders.edit' => ['sales.orders.edit'],
            'orders.status' => ['sales.orders.update_status'],
            'orders.payment' => ['sales.payments.create', 'sales.payments.edit'],
            'orders.delete' => ['sales.orders.delete'],
            
            'catalogue.view' => ['inventory.products.view'],
            'catalogue.edit' => ['inventory.products.create', 'inventory.products.edit'],
            
            'stock.view' => ['inventory.stock.view'],
            'stock.edit' => ['inventory.stock.adjust', 'inventory.stock.move'],
            
            'transactions.view' => ['finance.transactions.view'],
            'transactions.create' => ['finance.transactions.create'],
            'transactions.edit' => ['finance.transactions.edit'],
            
            'reports.view' => ['finance.reports.view'],
            
            'people.view' => ['people.employees.view', 'sales.customers.view'],
            'people.edit' => ['people.employees.create', 'people.employees.edit'],
            
            'payroll.run' => ['people.payroll.run'],
            
            'partners.view' => ['people.partners.view'],
            'partners.edit' => ['people.partners.edit'],
            
            'dashboard.view' => ['dashboard.view'],
            
            'stores.view' => ['platform.stores.view'],
            'stores.edit' => ['platform.stores.edit'],
            
            'settings.view' => ['settings.view'],
            'settings.edit' => ['settings.edit'],
            
            'users.manage' => ['settings.users.manage', 'settings.roles.manage'],
        ];
    }
}