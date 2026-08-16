<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Shared\Concerns\HasPublicId;
use App\Domain\Tenancy\Models\Account;
use App\Domain\Tenancy\Models\Business;
use App\Support\Capabilities;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Laragear\WebAuthn\Contracts\WebAuthnAuthenticatable;
use Laragear\WebAuthn\WebAuthnAuthentication;
use Laravel\Sanctum\HasApiTokens;

/**
 * Somebody who can sign in.
 *
 * ── Deliberately not tenant-scoped ───────────────────────────────────────────
 *
 * Every other model in the system carries the account scope. This one cannot:
 * the tenant is resolved *from* the user during authentication, so a global
 * scope here would need the answer before it could find it. Isolation is
 * instead enforced at the two places that matter — the login lookup keys on the
 * email's unique index, and every query about a *different* user goes through
 * the account relation, which is scoped.
 *
 * ── One row per person per account ───────────────────────────────────────────
 *
 * The same human working for two subscribers has two rows. That is right: they
 * have two jobs, two roles, two sets of permissions and two audit trails, and
 * merging them into one identity would mean every query in the system had to
 * carry "…and which account are we talking about" forever. The email unique
 * index is therefore composite: (account_id, email).
 */
class User extends Authenticatable implements MustVerifyEmail, WebAuthnAuthenticatable
{
    use HasApiTokens, HasFactory, HasPublicId, Notifiable, SoftDeletes, WebAuthnAuthentication;

    /** How long a resolved capability set survives without being touched. */
    private const CACHE_TTL = 3600;

    protected $fillable = [
        'public_id',
        'account_id',
        'role_id',
        'current_business_id',
        'name',
        'email',
        'password',
        'avatar_path',
        'timezone',
        'locale',
        'is_owner',
        'is_active',
        'last_seen_at',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_confirmed_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'is_owner' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    // ── Relationships ────────────────────────────────────────────────────────

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function currentBusiness(): BelongsTo
    {
        return $this->belongsTo(Business::class, 'current_business_id');
    }

    // ── Authorisation ────────────────────────────────────────────────────────

    /**
     * Whether they may do something.
     *
     * An owner may do anything — owning the account is the permission, and a
     * capability list they could accidentally remove themselves from would be a
     * way to lock yourself out of your own business.
     *
     * The role is fetched by id from cache rather than through the relation, so
     * the common case costs no query at all. A page asking this thirty times
     * hits the same in-memory array thirty times.
     */
    public function hasCapability(string $capability): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->is_owner) {
            return true;
        }

        $set = $this->capabilitySet();

        return isset($set[Capabilities::ALL]) || isset($set[$capability]);
    }

    /**
     * Everything this login may do, as a hash map.
     *
     * Resolved once per request and memoised on the instance, so the thirty
     * checks a page makes share one lookup. Owners get the catch-all rather
     * than an expansion of every slug: the list can grow without anybody's
     * cached answer going stale.
     *
     * @return array<string, true>
     */
    public function capabilitySet(): array
    {
        if ($this->is_owner) {
            return [Capabilities::ALL => true];
        }

        if ($this->role_id === null) {
            return [];
        }

        /** @var array<string, true> */
        return Cache::remember(
            Role::cacheKey($this->role_id),
            self::CACHE_TTL,
            fn () => array_fill_keys(
                Role::withoutGlobalScopes()->find($this->role_id)?->capabilities ?? [],
                true,
            ),
        );
    }

    /**
     * The capability slugs, for handing to the client.
     *
     * The frontend needs these to decide what to draw. It is not a security
     * boundary — every one of them is checked again on the server — it is how
     * the interface avoids offering somebody a button that will refuse them.
     *
     * @return list<string>
     */
    public function capabilityList(): array
    {
        return array_keys($this->capabilitySet());
    }

    public function ownsAccount(): bool
    {
        return (bool) $this->is_owner;
    }

    // ── Second factor ────────────────────────────────────────────────────────

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    public function hasPassword(): bool
    {
        return $this->password !== null;
    }

    /**
     * The recovery codes, or an empty list.
     *
     * @return list<string>
     */
    public function recoveryCodes(): array
    {
        return $this->two_factor_recovery_codes ?? [];
    }

    /** Spend a recovery code — each works exactly once. */
    public function consumeRecoveryCode(string $code): bool
    {
        $codes = $this->recoveryCodes();
        $index = array_search($code, $codes, true);

        if ($index === false) {
            return false;
        }

        unset($codes[$index]);
        $this->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();

        return true;
    }

    /**
     * Get the avatar URL or null if no avatar is set.
     */
    public function getAvatarUrlAttribute(): ?string
    {
        if ($this->avatar_path === null) {
            return null;
        }

        // If it's already a full URL, return as-is
        if (str_starts_with($this->avatar_path, 'http')) {
            return $this->avatar_path;
        }

        // Otherwise, resolve through storage
        return asset("storage/{$this->avatar_path}");
    }

    // ── Time ─────────────────────────────────────────────────────────────────

    /**
     * The user's timezone, falling back to the account's and then the app's.
     *
     * Guarded against a stale identifier so a bad profile value cannot throw on
     * every date calculation the tool makes.
     */
    public function timezoneOrDefault(): string
    {
        foreach ([$this->timezone, $this->account?->timezone] as $candidate) {
            if ($candidate && in_array($candidate, \DateTimeZone::listIdentifiers(), true)) {
                return $candidate;
            }
        }

        return config('app.timezone', 'UTC');
    }

    /** "Now" as this user experiences it, not as the server does. */
    public function localNow(): Carbon
    {
        return Carbon::now($this->timezoneOrDefault());
    }
}
