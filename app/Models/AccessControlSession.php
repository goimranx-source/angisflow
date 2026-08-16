<?php

namespace App\Models;

use App\Domain\Tenancy\Concerns\BelongsToAccount;
use App\Domain\Shared\Concerns\HasPublicId;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

/**
 * Access control session for permission caching and elevated access
 *
 * Manages user sessions with cached permissions, role context, and
 * elevated access tracking. Provides performance optimization for
 * permission checks and security monitoring for privileged operations.
 */
class AccessControlSession extends Model
{
    use HasFactory, BelongsToAccount, HasPublicId;

    protected $fillable = [
        'account_id',
        'user_id',
        'business_id',
        'public_id',
        'session_id',
        'active_roles',
        'cached_permissions',
        'current_business_context',
        'permissions_loaded_at',
        'last_permission_check',
        'permission_checks_count',
        'ip_address',
        'user_agent',
        'is_elevated_session',
        'elevation_expires_at',
    ];

    protected $casts = [
        'active_roles' => 'array',
        'cached_permissions' => 'array',
        'permissions_loaded_at' => 'datetime',
        'last_permission_check' => 'datetime',
        'permission_checks_count' => 'integer',
        'is_elevated_session' => 'boolean',
        'elevation_expires_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Tenancy\Models\Business::class);
    }

    /**
     * Check if session has a specific permission cached
     */
    public function hasPermission(string $permission): bool
    {
        $this->incrementPermissionChecks();
        
        $permissions = $this->cached_permissions ?? [];
        return isset($permissions[$permission]) && $permissions[$permission] === true;
    }

    /**
     * Check if session has any of the given permissions
     */
    public function hasAnyPermission(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->hasPermission($permission)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if session has all of the given permissions
     */
    public function hasAllPermissions(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (!$this->hasPermission($permission)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Update cached permissions
     */
    public function updateCachedPermissions(array $permissions): void
    {
        $this->update([
            'cached_permissions' => $permissions,
            'permissions_loaded_at' => now(),
        ]);
    }

    /**
     * Add permission to cache
     */
    public function addPermission(string $permission): void
    {
        $permissions = $this->cached_permissions ?? [];
        $permissions[$permission] = true;
        
        $this->updateCachedPermissions($permissions);
    }

    /**
     * Remove permission from cache
     */
    public function removePermission(string $permission): void
    {
        $permissions = $this->cached_permissions ?? [];
        unset($permissions[$permission]);
        
        $this->updateCachedPermissions($permissions);
    }

    /**
     * Clear all cached permissions
     */
    public function clearPermissions(): void
    {
        $this->updateCachedPermissions([]);
    }

    /**
     * Check if permissions need refresh
     */
    public function needsPermissionRefresh(?int $maxAgeMinutes = 30): bool
    {
        if (!$this->permissions_loaded_at) {
            return true;
        }
        
        return $this->permissions_loaded_at->addMinutes($maxAgeMinutes)->isPast();
    }

    /**
     * Update active roles
     */
    public function updateActiveRoles(array $roles): void
    {
        $this->update([
            'active_roles' => $roles,
        ]);
        
        // Clear permissions cache when roles change
        $this->clearPermissions();
    }

    /**
     * Switch business context
     */
    public function switchBusinessContext(?int $businessId): void
    {
        $this->update([
            'business_id' => $businessId,
            'current_business_context' => $businessId ? (string) $businessId : null,
        ]);
        
        // Clear permissions cache when business context changes
        $this->clearPermissions();
    }

    /**
     * Elevate session with temporary admin privileges
     */
    public function elevate(int $durationMinutes = 15, ?string $reason = null): void
    {
        $this->update([
            'is_elevated_session' => true,
            'elevation_expires_at' => now()->addMinutes($durationMinutes),
        ]);
        
        // Log elevation
        PermissionAuditLog::logEvent(
            'session_elevated',
            'session',
            $this->session_id,
            null,
            [
                'duration_minutes' => $durationMinutes,
                'expires_at' => $this->elevation_expires_at,
            ],
            $reason,
            $this->user_id,
            null,
            null,
            $this->business_id,
            $this->user_id,
            'high',
            true
        );
    }

    /**
     * Check if session is currently elevated
     */
    public function isElevated(): bool
    {
        if (!$this->is_elevated_session) {
            return false;
        }
        
        if ($this->elevation_expires_at && $this->elevation_expires_at->isPast()) {
            $this->expireElevation();
            return false;
        }
        
        return true;
    }

    /**
     * Expire session elevation
     */
    public function expireElevation(): void
    {
        if ($this->is_elevated_session) {
            $this->update([
                'is_elevated_session' => false,
                'elevation_expires_at' => null,
            ]);
            
            // Log expiration
            PermissionAuditLog::logEvent(
                'session_elevation_expired',
                'session',
                $this->session_id,
                null,
                ['expired_at' => now()],
                'Session elevation expired',
                $this->user_id,
                null,
                null,
                $this->business_id,
                null,
                'medium'
            );
        }
    }

    /**
     * Get session statistics
     */
    public function getStatistics(): array
    {
        $sessionDuration = $this->created_at->diffInMinutes(now());
        $checksPerMinute = $sessionDuration > 0 ? $this->permission_checks_count / $sessionDuration : 0;
        
        return [
            'session_duration_minutes' => $sessionDuration,
            'total_permission_checks' => $this->permission_checks_count,
            'checks_per_minute' => round($checksPerMinute, 2),
            'permissions_cache_age_minutes' => $this->permissions_loaded_at ? 
                $this->permissions_loaded_at->diffInMinutes(now()) : null,
            'active_roles_count' => count($this->active_roles ?? []),
            'cached_permissions_count' => count($this->cached_permissions ?? []),
            'is_elevated' => $this->isElevated(),
            'elevation_remaining_minutes' => $this->elevation_expires_at && $this->isElevated() ?
                now()->diffInMinutes($this->elevation_expires_at) : 0,
        ];
    }

    /**
     * Increment permission check counter
     */
    protected function incrementPermissionChecks(): void
    {
        $this->increment('permission_checks_count');
        $this->update(['last_permission_check' => now()]);
    }

    /**
     * Create session for user
     */
    public static function createForUser(
        User $user,
        string $sessionId,
        ?int $businessId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): self {
        return self::create([
            'account_id' => $user->account_id,
            'user_id' => $user->id,
            'business_id' => $businessId,
            'session_id' => $sessionId,
            'active_roles' => [],
            'cached_permissions' => [],
            'current_business_context' => $businessId ? (string) $businessId : null,
            'ip_address' => $ipAddress ?? request()?->ip(),
            'user_agent' => $userAgent ?? request()?->userAgent(),
        ]);
    }

    /**
     * Find session by Laravel session ID
     */
    public static function findBySessionId(string $sessionId): ?self
    {
        return self::where('session_id', $sessionId)->first();
    }

    /**
     * Clean up expired sessions
     */
    public static function cleanupExpiredSessions(): int
    {
        $count = 0;
        
        // Clean up sessions with expired elevation
        $expiredElevations = self::where('is_elevated_session', true)
            ->where('elevation_expires_at', '<', now())
            ->get();
        
        foreach ($expiredElevations as $session) {
            $session->expireElevation();
            $count++;
        }
        
        // Clean up old sessions (older than 30 days)
        $deleted = self::where('created_at', '<', now()->subDays(30))->delete();
        $count += $deleted;
        
        return $count;
    }

    /**
     * Get sessions needing permission refresh
     */
    public static function needingRefresh(?int $maxAgeMinutes = 30): \Illuminate\Database\Eloquent\Collection
    {
        return self::where(function ($query) use ($maxAgeMinutes) {
            $query->whereNull('permissions_loaded_at')
                  ->orWhere('permissions_loaded_at', '<', now()->subMinutes($maxAgeMinutes));
        })->get();
    }

    /**
     * Scope for active sessions
     */
    public function scopeActive($query)
    {
        return $query->where('created_at', '>', now()->subHours(24));
    }

    /**
     * Scope for elevated sessions
     */
    public function scopeElevated($query)
    {
        return $query->where('is_elevated_session', true)
                    ->where(function ($q) {
                        $q->whereNull('elevation_expires_at')
                          ->orWhere('elevation_expires_at', '>', now());
                    });
    }
}