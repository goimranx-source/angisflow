<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Shared\Concerns\HasPublicId;
use App\Domain\Tenancy\Concerns\BelongsToAccount;
use App\Support\Capabilities;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

/**
 * A named set of permissions, belonging to one subscriber.
 *
 * Per account, not per installation: one business calling its role "Manager"
 * says nothing about what another business means by it.
 *
 * ── The caching, and why it is not optional ──────────────────────────────────
 *
 * Every guarded thing on a page asks "may this person do X". A dashboard asks
 * it thirty times. Answering each from the database is thirty queries to prove
 * a fact that changes when somebody edits a role — which is to say, almost
 * never.
 *
 * So the resolved capability set is cached by role id and served from memory.
 * The cache is dropped when the role is saved or deleted, which is the only
 * moment the answer can change. At a million users this is the difference
 * between the authorisation layer being free and being the reason the site is
 * slow.
 */
class Role extends Model
{
    use BelongsToAccount, HasPublicId;

    /** How long a resolved capability set survives without being touched. */
    private const CACHE_TTL = 3600;

    /**
     * The sides of a business somebody can be put on.
     *
     * A family, not a job. Sales Representative and Distribution SR are two
     * roles doing the same work at different reach, and both want the same
     * screens. So the workspace hangs off the family and the roles inside it
     * differ only in what they may touch.
     */
    public const WORKSPACES = [
        'sales' => 'Orders & sales',
        'accounts' => 'Accounting',
        'inventory' => 'Stock & packaging',
        'marketing' => 'Marketing',
        'support' => 'Customer support',
        'management' => 'Management',
    ];

    /**
     * How much of the business a holder can see.
     *
     * Kept on the role rather than worked out from a grade, because the two
     * genuinely differ: a Senior and a Junior Accountant see the same ledger,
     * while two Managers on the same grade see different teams.
     */
    public const SCOPES = [
        'own' => 'Only their own work',
        'team' => 'Their own, and everyone reporting to them',
        'all' => 'Everything in the business',
    ];

    protected $fillable = [
        'public_id',
        'account_id',
        'parent_id',
        'name',
        'slug',
        'description',
        'capabilities',
        'landing',
        'workspace',
        'scope',
        'is_system',
    ];

    protected function casts(): array
    {
        return [
            'capabilities' => 'array',
            'is_system' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // The only two moments the answer to "what does this role allow" can
        // change. Anything else reads the cache.
        static::saved(fn (Role $role) => $role->forgetCachedCapabilities());
        static::deleted(fn (Role $role) => $role->forgetCachedCapabilities());
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Role::class, 'parent_id');
    }

    /** Only the capabilities that still exist, deduplicated. */
    public function setCapabilitiesAttribute(mixed $value): void
    {
        $this->attributes['capabilities'] = json_encode(
            Capabilities::sanitise(is_array($value) ? $value : [])
        );
    }

    /**
     * The role's capability set as a hash map, from cache.
     *
     * A map rather than a list so membership is a key lookup rather than a scan
     * — the difference does not matter at five capabilities and matters a great
     * deal when a page asks thirty times.
     *
     * @return array<string, true>
     */
    public function capabilitySet(): array
    {
        return Cache::remember(
            self::cacheKey($this->getKey()),
            self::CACHE_TTL,
            fn () => array_fill_keys($this->capabilities ?? [], true),
        );
    }

    /** Whether this role grants a capability, allowing for the catch-all. */
    public function grants(string $capability): bool
    {
        $set = $this->capabilitySet();

        return isset($set[Capabilities::ALL]) || isset($set[$capability]);
    }

    public function forgetCachedCapabilities(): void
    {
        Cache::forget(self::cacheKey($this->getKey()));
    }

    public static function cacheKey(int|string $roleId): string
    {
        return "role:{$roleId}:capabilities";
    }

    /**
     * Which screens this role works.
     *
     * Inherited from the family when the role does not say for itself, so
     * "DSR under Sales" needs nothing configured to land in the right place.
     */
    public function workspaceKey(): ?string
    {
        return $this->workspace ?: $this->parent?->workspace;
    }

    /** The family name, for showing a role in context: "Sales › DSR". */
    public function familyName(): string
    {
        return $this->parent ? $this->parent->name.' › '.$this->name : $this->name;
    }

    /** The path this role should open on — the first thing its holder can reach. */
    public function landingPath(): string
    {
        if ($this->landing) {
            return $this->landing;
        }

        foreach (Capabilities::LANDINGS as $capability => $path) {
            if ($this->grants($capability)) {
                return $path;
            }
        }

        // Somebody with a login and no capabilities yet. Their own profile is
        // the one page that is always theirs.
        return '/profile';
    }
}
