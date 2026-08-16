<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Models;

use App\Domain\Billing\Models\Subscription;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One subscriber. The thing that pays, and the boundary every other row lives
 * inside.
 *
 * ── Account, Business, Store, Location ───────────────────────────────────────
 *
 * Four words that sound alike and are not, and conflating any two of them is
 * what makes these systems collapse two years in:
 *
 *   Account   who pays. One subscription, one bill, one owner. The tenant.
 *   Business  whose money is it. A set of books — its own profit and loss, its
 *             own capital, its own currency. An account can hold several: a
 *             manufacturer and its retail arm, an operation abroad.
 *   Store     how did the order reach us. A website, a Facebook page, a
 *             marketplace listing.
 *   Location  where are the things and the people. A hub, an outlet, a godown.
 *
 * Only the first of those is the tenant. Making the Business the tenant would
 * mean a group could never see its arms together; making the Store the tenant
 * would mean a firm with two websites had two of everything and no answer to
 * "what did we earn".
 */
class Account extends Model
{
    use HasPublicId, SoftDeletes;

    protected $fillable = [
        'public_id',
        'name',
        'slug',
        'owner_user_id',
        'base_currency',
        'country',
        'timezone',
        'locale',
        'status',
        'trial_ends_at',
        'suspended_at',
        'suspended_reason',
        'settings',
        'onboarding_steps',
        'onboarding_completed',
        'onboarding_completed_at',
        'deleted_by_user_id',
        'deletion_reason',
        'deletion_notes',
        'permanent_deletion_at',
    ];

    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
            'suspended_at' => 'datetime',
            'settings' => 'array',
            'onboarding_steps' => 'array',
            'onboarding_completed' => 'boolean',
            'onboarding_completed_at' => 'datetime',
            'permanent_deletion_at' => 'datetime',
        ];
    }

    /** What an account can be, from the platform's point of view. */
    public const STATUS_TRIALING = 'trialing';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAST_DUE = 'past_due';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_CANCELLED = 'cancelled';

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    public function workspaces(): HasMany
    {
        return $this->hasMany(Workspace::class);
    }

    /**
     * Every set of books the subscriber owns, across all their workspaces.
     *
     * Kept alongside the workspace relation rather than replaced by it: some
     * questions are about one workspace's businesses and some — "how many am I
     * paying for", the global inbox — are about all of them, and going through
     * workspaces for the second kind is a join that buys nothing.
     */
    public function businesses(): HasMany
    {
        return $this->hasMany(Business::class);
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class)->latestOfMany();
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * Whether the tool should let anyone in at all.
     *
     * Past due is deliberately still usable. Locking a business out of its own
     * ledger over a failed card is how a subscriber leaves and takes their data
     * complaint public; the place to apply pressure is a banner, not the door.
     */
    public function isUsable(): bool
    {
        return in_array($this->status, [
            self::STATUS_TRIALING,
            self::STATUS_ACTIVE,
            self::STATUS_PAST_DUE,
        ], true) && $this->suspended_at === null;
    }

    public function isOnTrial(): bool
    {
        return $this->status === self::STATUS_TRIALING
            && $this->trial_ends_at !== null
            && $this->trial_ends_at->isFuture();
    }

    /**
     * Check if the account needs to complete onboarding.
     *
     * Returns true if onboarding is not yet marked as completed.
     */
    public function needsOnboarding(): bool
    {
        return !$this->onboarding_completed;
    }

    /**
     * Mark the onboarding process as complete.
     *
     * Sets the completion flag and timestamp.
     */
    public function markOnboardingComplete(): void
    {
        $this->update([
            'onboarding_completed' => true,
            'onboarding_completed_at' => now(),
        ]);
    }

    /**
     * Track completion of an onboarding step.
     *
     * Updates the onboarding_steps JSON with the step status.
     *
     * @param  string  $step  The step name (e.g., 'workspace_created', 'business_created')
     */
    public function trackOnboardingStep(string $step): void
    {
        $steps = $this->onboarding_steps ?? [];
        $steps[$step] = true;
        $this->update(['onboarding_steps' => $steps]);
    }

    /**
     * Check if a specific onboarding step has been completed.
     *
     * @param  string  $step  The step name to check
     */
    public function hasCompletedStep(string $step): bool
    {
        return ($this->onboarding_steps[$step] ?? false) === true;
    }

    /**
     * Get the list of incomplete onboarding steps.
     *
     * @return array<string>
     */
    public function incompleteOnboardingSteps(): array
    {
        $allSteps = [
            'account_created',
            'workspace_created',
            'business_created',
            'plan_selected',
            'payment_confirmed',
        ];

        $steps = $this->onboarding_steps ?? [];

        return array_filter($allSteps, fn (string $step) => !($steps[$step] ?? false));
    }
}
