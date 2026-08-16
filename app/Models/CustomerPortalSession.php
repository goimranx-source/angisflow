<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tenancy\Concerns\BelongsToAccount;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use App\Domain\Shared\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Customer Portal Session Model
 *
 * Manages authenticated sessions for customers accessing their portal.
 * Provides secure session management with device tracking and security features.
 */
class CustomerPortalSession extends Model
{
    use BelongsToAccount, BelongsToBusiness, HasPublicId;

    protected $fillable = [
        'customer_id',
        'session_token',
        'device_fingerprint',
        'ip_address',
        'user_agent',
        'preferences',
        'cart_data',
        'last_activity_at',
        'expires_at',
        'is_active',
        'failed_attempts',
        'locked_until',
    ];

    protected $casts = [
        'preferences' => 'array',
        'cart_data' => 'array',
        'last_activity_at' => 'datetime',
        'expires_at' => 'datetime',
        'is_active' => 'boolean',
        'locked_until' => 'datetime',
    ];

    protected $hidden = [
        'session_token',
    ];

    // ── Relationships ────────────────────────────────────────────────────────

    /**
     * The customer this session belongs to
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    // ── Business Logic ────────────────────────────────────────────────────────

    /**
     * Generate a new session token
     */
    public static function generateToken(): string
    {
        return 'cps_' . Str::random(40);
    }

    /**
     * Create a new session for customer
     */
    public static function createForCustomer(Customer $customer, array $data = []): self
    {
        $token = self::generateToken();
        $expiresAt = now()->addDays(30); // 30-day sessions

        return self::create([
            'account_id' => $customer->account_id,
            'business_id' => $customer->business_id,
            'customer_id' => $customer->id,
            'session_token' => $token,
            'device_fingerprint' => $data['device_fingerprint'] ?? null,
            'ip_address' => $data['ip_address'] ?? request()->ip(),
            'user_agent' => $data['user_agent'] ?? request()->userAgent(),
            'last_activity_at' => now(),
            'expires_at' => $expiresAt,
            'is_active' => true,
        ]);
    }

    /**
     * Find session by token
     */
    public static function findByToken(string $token): ?self
    {
        return self::where('session_token', $token)
            ->active()
            ->notExpired()
            ->first();
    }

    /**
     * Update session activity
     */
    public function updateActivity(): void
    {
        $this->update([
            'last_activity_at' => now(),
            'failed_attempts' => 0,
        ]);
    }

    /**
     * Check if session is valid
     */
    public function isValid(): bool
    {
        return $this->is_active && 
               !$this->isExpired() && 
               !$this->isLocked();
    }

    /**
     * Check if session is expired
     */
    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    /**
     * Check if session is locked due to failed attempts
     */
    public function isLocked(): bool
    {
        return $this->locked_until && $this->locked_until->isFuture();
    }

    /**
     * Record failed authentication attempt
     */
    public function recordFailedAttempt(): void
    {
        $this->increment('failed_attempts');
        
        // Lock session after 5 failed attempts
        if ($this->failed_attempts >= 5) {
            $this->update([
                'locked_until' => now()->addMinutes(30),
            ]);
        }
    }

    /**
     * Revoke session
     */
    public function revoke(): void
    {
        $this->update(['is_active' => false]);
    }

    /**
     * Update session preferences
     */
    public function updatePreferences(array $preferences): void
    {
        $current = $this->preferences ?? [];
        $this->update([
            'preferences' => array_merge($current, $preferences),
        ]);
    }

    /**
     * Update shopping cart data
     */
    public function updateCart(array $cartData): void
    {
        $this->update(['cart_data' => $cartData]);
    }

    /**
     * Get cart item count
     */
    public function getCartItemCount(): int
    {
        if (empty($this->cart_data)) {
            return 0;
        }

        return array_sum(array_column($this->cart_data['items'] ?? [], 'quantity'));
    }

    /**
     * Get session duration in minutes
     */
    public function getSessionDuration(): int
    {
        return $this->last_activity_at->diffInMinutes($this->created_at);
    }

    /**
     * Get device information
     */
    public function getDeviceInfo(): array
    {
        $userAgent = $this->user_agent ?? '';
        
        return [
            'browser' => $this->extractBrowser($userAgent),
            'platform' => $this->extractPlatform($userAgent),
            'device_type' => $this->extractDeviceType($userAgent),
            'is_mobile' => $this->isMobileDevice($userAgent),
        ];
    }

    /**
     * Extract browser from user agent
     */
    private function extractBrowser(string $userAgent): string
    {
        $browsers = [
            'Chrome' => '/Chrome\/[\d.]+/',
            'Firefox' => '/Firefox\/[\d.]+/',
            'Safari' => '/Safari\/[\d.]+/',
            'Edge' => '/Edg\/[\d.]+/',
            'Opera' => '/OPR\/[\d.]+/',
        ];

        foreach ($browsers as $browser => $pattern) {
            if (preg_match($pattern, $userAgent)) {
                return $browser;
            }
        }

        return 'Unknown';
    }

    /**
     * Extract platform from user agent
     */
    private function extractPlatform(string $userAgent): string
    {
        $platforms = [
            'Windows' => '/Windows NT/',
            'macOS' => '/Mac OS X/',
            'Linux' => '/Linux/',
            'iOS' => '/iPhone|iPad/',
            'Android' => '/Android/',
        ];

        foreach ($platforms as $platform => $pattern) {
            if (preg_match($pattern, $userAgent)) {
                return $platform;
            }
        }

        return 'Unknown';
    }

    /**
     * Extract device type from user agent
     */
    private function extractDeviceType(string $userAgent): string
    {
        if (preg_match('/Mobile|Android|iPhone/', $userAgent)) {
            return 'mobile';
        }

        if (preg_match('/iPad|Tablet/', $userAgent)) {
            return 'tablet';
        }

        return 'desktop';
    }

    /**
     * Check if user agent indicates mobile device
     */
    private function isMobileDevice(string $userAgent): bool
    {
        return (bool) preg_match('/Mobile|Android|iPhone/', $userAgent);
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    /**
     * Scope to active sessions only
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to non-expired sessions
     */
    public function scopeNotExpired($query)
    {
        return $query->where('expires_at', '>', now());
    }

    /**
     * Scope to non-locked sessions
     */
    public function scopeNotLocked($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('locked_until')
              ->orWhere('locked_until', '<=', now());
        });
    }

    /**
     * Scope to sessions for specific customer
     */
    public function scopeForCustomer($query, int $customerId)
    {
        return $query->where('customer_id', $customerId);
    }

    // ── Cleanup Methods ──────────────────────────────────────────────────────

    /**
     * Clean up expired sessions
     */
    public static function cleanup(): int
    {
        return self::where('expires_at', '<', now()->subDays(7))->delete();
    }

    /**
     * Revoke all sessions for customer
     */
    public static function revokeAllForCustomer(int $customerId): int
    {
        return self::where('customer_id', $customerId)
            ->update(['is_active' => false]);
    }
}