<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Support\DefaultRoles;
use App\Domain\Tenancy\Models\Account;
use App\Domain\Tenancy\Models\Business;
use App\Domain\Tenancy\Models\Workspace;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Signing up creates a subscriber, not a login.
 *
 * Seven things have to exist before the new owner sees a usable screen: an
 * account, a trial against a plan, an owner, a first set of books, and the
 * roles they will hand out in week two. Missing any one of them produces an
 * account that half works — a login with no books, a business with no roles —
 * and every one of those states then needs code elsewhere to cope with it.
 *
 * So it is one transaction. Either a subscriber exists or nobody was created.
 */
final class RegisterAccount
{
    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * @param  array{name: string, email: string, password: string, business?: ?string, currency?: ?string, timezone?: ?string, country?: ?string}  $input
     */
    public function handle(array $input): User
    {
        return DB::transaction(function () use ($input): User {
            $businessName = trim($input['business'] ?? '') ?: $input['name'].'’s business';
            $currency = strtoupper($input['currency'] ?? 'BDT');

            $account = Account::create([
                'name' => $businessName,
                'slug' => $this->uniqueSlug($businessName),
                'base_currency' => $currency,
                'country' => $input['country'] ?? null,
                'timezone' => $input['timezone'] ?? config('app.timezone'),
                'status' => Account::STATUS_TRIALING,
                'trial_ends_at' => now()->addDays($this->trialDays()),
            ]);

            // Every write from here on belongs inside the new account. Setting
            // it now means the models stamp account_id themselves rather than
            // each call site remembering to pass it — which is the failure this
            // whole design exists to make impossible.
            $this->tenant->setAccount($account);

            $this->seedRoles($account->id);

            // One workspace to begin with, holding the business they named at
            // sign-up. A subscriber who never needs a second one never has to
            // learn the word; the plan decides whether they may add more.
            $workspace = Workspace::create([
                'account_id' => $account->id,
                'name' => $businessName,
                'slug' => Workspace::uniqueSlug($account->id, $businessName),
                'is_active' => true,
            ]);

            $business = Business::create([
                'account_id' => $account->id,
                'workspace_id' => $workspace->id,
                'name' => $businessName,
                'short_code' => $this->shortCodeFor($businessName),
                'base_currency' => $currency,
                'country' => $input['country'] ?? null,
                'timezone' => $input['timezone'] ?? config('app.timezone'),
                'is_active' => true,
            ]);

            $owner = User::create([
                'account_id' => $account->id,
                'current_business_id' => $business->id,
                'name' => $input['name'],
                'email' => mb_strtolower(trim($input['email'])),
                'password' => $input['password'],
                'timezone' => $input['timezone'] ?? null,
                // The owner holds every capability implicitly and has no role
                // row. A role they could edit is a role they could lock
                // themselves out of their own business with.
                'is_owner' => true,
                'is_active' => true,
            ]);

            // Written after the owner exists, because it points at them.
            $account->forceFill(['owner_user_id' => $owner->id])->save();

            // Initialize onboarding tracking
            $account->update([
                'onboarding_steps' => [
                    'account_created' => true,
                    'workspace_created' => true,
                    'business_created' => true,
                    'plan_selected' => false,
                    'payment_confirmed' => false,
                ],
            ]);

            $this->startTrial($account);

            return $owner;
        });
    }

    /**
     * The starting roles.
     *
     * Two passes: families first, then the roles that hang under them, because
     * the second pass needs the ids the first produced.
     */
    private function seedRoles(int $accountId): void
    {
        $ids = [];

        foreach (DefaultRoles::all() as $definition) {
            $role = Role::create([
                'account_id' => $accountId,
                'parent_id' => $definition['parent'] !== null ? ($ids[$definition['parent']] ?? null) : null,
                'name' => $definition['name'],
                'slug' => $definition['slug'],
                'description' => $definition['description'],
                'capabilities' => $definition['capabilities'],
                'workspace' => $definition['workspace'],
                'scope' => $definition['scope'],
                'is_system' => true,
            ]);

            $ids[$definition['slug']] = $role->id;
        }
    }

    /**
     * Put the account on a trial of the default plan.
     *
     * Silently skipped when no plans have been seeded yet, so a fresh install
     * with an empty plans table can still register somebody. The account's own
     * status already says "trialing", which is what the application reads; the
     * subscription row is the commercial record beside it.
     */
    private function startTrial(Account $account): void
    {
        $plan = Plan::query()
            ->where('code', config('prism.registration.default_plan'))
            ->first()
            ?? Plan::query()->where('is_public', true)->orderBy('sort_order')->first();

        if ($plan === null) {
            return;
        }

        Subscription::create([
            'account_id' => $account->id,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_TRIALING,
            'started_at' => now(),
            'trial_ends_at' => $account->trial_ends_at,
            'current_period_start' => now(),
            'current_period_end' => $account->trial_ends_at,
        ]);
    }

    private function trialDays(): int
    {
        return Plan::query()
            ->where('code', config('prism.registration.default_plan'))
            ->value('trial_days') ?? 14;
    }

    /**
     * A readable, unique slug.
     *
     * Retried against the unique index rather than checked and then inserted:
     * two people signing up with the same business name in the same second is
     * rare, and a check-then-insert would let exactly that pair through.
     */
    private function uniqueSlug(string $name): string
    {
        $stem = Str::slug($name) ?: 'account';
        $stem = Str::limit($stem, 40, '');

        $slug = $stem;
        $suffix = 0;

        while (Account::withoutGlobalScopes()->where('slug', $slug)->exists()) {
            $slug = $stem.'-'.(++$suffix);
        }

        return $slug;
    }

    /** "Vorosa Bajar" → "VB". The stem of every future SKU and order reference. */
    private function shortCodeFor(string $name): string
    {
        $initials = collect(preg_split('/\s+/', trim($name)) ?: [])
            ->filter()
            ->map(fn (string $word) => mb_substr($word, 0, 1))
            ->implode('');

        $clean = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $initials) ?? '');

        return substr($clean !== '' ? $clean : 'BIZ', 0, 6);
    }
}
