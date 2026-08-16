<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\Assistant\Assistant;
use App\Domain\Billing\Allowance;
use App\Domain\Identity\Models\User;
use App\Domain\Media\Models\MediaItem;
use App\Domain\Settings\PlatformSettings;
use App\Domain\Settings\Settings;
use App\Domain\Tenancy\TenantContext;
use App\Http\Api\V1\OrganiserEndpoint;

/**
 * Everything the shell needs to draw itself: who is signed in, whose books they
 * are looking at, and what the menu contains.
 *
 * ── Built once, served two ways ──────────────────────────────────────────────
 *
 * The same payload is inlined into the HTML document on a cold load and served
 * from /api/v1/bootstrap on every revalidation. Two callers, one builder, so
 * they cannot drift — and the client's very first render has the sidebar, the
 * business name and the user's capabilities without waiting on a request.
 *
 * That inlining is the difference between a single-page application that feels
 * instant and one that shows an empty frame for 300ms on every cold load. The
 * usual SPA sequence is: HTML, then bundle, then a call to find out who you
 * are, and only then can the shell be drawn. Here the answer arrived with the
 * document.
 *
 * ── Cost ─────────────────────────────────────────────────────────────────────
 *
 * One user row (already loaded for authentication), one cached menu, one cached
 * business list. At a million subscribers this is two cache reads.
 */
final class BootPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function build(): array
    {
        /** @var User|null $user */
        $user = auth()->user();

        return [
            'app' => self::brand($user),
            // The tool's own mark, ours rather than a subscriber's. It sits in
            // the header on every account, so it is deliberately not merged
            // into `app` — that one is whatever the subscriber renamed us to.
            'platform' => PlatformSettings::brand(),
            'config' => [
                'registration_enabled' => (bool) config('prism.registration.enabled'),
                'turnstile_site_key' => config('prism.turnstile.site_key'),
                // Forms part of the /modules URL so that response can be
                // cached hard and still never be stale. See Modules::version().
                'catalogue_version' => Modules::version(),
                // So the palette can leave the ask-anything affordance out
                // entirely rather than offering a button that returns 503.
                'assistant_enabled' => Assistant::isConfigured(),
            ],
            'auth' => self::auth($user),
            'tenant' => self::tenant($user),
            // The workspace decides which modules the rail may offer, so the
            // menu cannot be built without knowing which one is open. With
            // none open the nav falls back to core modules — see
            // Navigation::forUser.
            // The business's categories determine which modules are shown.
            'nav' => $user === null
                ? []
                : Navigation::forUser($user, app(TenantContext::class)->workspace(), app(TenantContext::class)->business()),
            'todo_marks' => $user === null ? [] : OrganiserEndpoint::marksFor($user),
        ];
    }

    /**
     * What the tool calls itself here.
     *
     * A subscriber's own name and marks where they have set them, the
     * platform's where they have not. This is read on every single page load —
     * it is what the sidebar and the browser tab say — so it comes from the
     * cached settings set rather than from a query.
     *
     * The logo is resolved to a URL here. The client should never have to know
     * how a media id becomes an address, and doing it once on the server means
     * a change of storage disk is a config edit rather than a client release.
     *
     * OVERRIDE: Hardcoded to use Angisflow branding for now, until admin panel is built.
     *
     * @return array<string, mixed>
     */
    private static function brand(?User $user): array
    {
        // Hardcoded Angisflow branding - override the dynamic settings system
        return [
            'name' => 'Angisflow',
            'tagline' => 'Run the whole business from one place.',
            'logo' => '/img/angisflow-logo.png',
            'logo_mark' => '/img/angisflow-favicon.png', 
            'favicon' => '/img/angisflow-favicon.png',
            'show_logo' => true, // Always show logo, never show text title
        ];
    }

    /**
     * Resolve several media ids to URLs in one query.
     *
     * Three ids means three lookups done naively, on every request. One
     * whereIn does the same work once — the kind of saving that is invisible at
     * a hundred users and is the whole budget at a million.
     *
     * @param  array<int, string>  $ids
     * @return array<string, string>
     */
    private static function mediaUrls(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return MediaItem::query()
            ->whereIn('public_id', array_unique($ids))
            ->get(['public_id', 'path'])
            ->mapWithKeys(fn (MediaItem $item) => [$item->public_id => $item->url()])
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function auth(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        // Hand-built rather than handing over the model. A model serialises
        // whatever is on it, so the day somebody adds a column the browser
        // receives it — including the day somebody adds a sensitive one.
        // $hidden is a denylist, and a denylist guarding the boundary between a
        // database and a browser is the wrong shape.
        return [
            'user' => [
                'id' => $user->public_id,
                'name' => $user->name,
                'email' => $user->email,
                'avatar' => $user->avatar_url,
                'is_owner' => $user->is_owner,
                'timezone' => $user->timezoneOrDefault(),
                'two_factor_enabled' => $user->hasTwoFactorEnabled(),
                'email_verified' => $user->hasVerifiedEmail(),
                'has_password' => $user->hasPassword(),
            ],

            // Not a security boundary — every one of these is checked again on
            // the server, on every request. It is how the interface avoids
            // offering somebody a button that will refuse them, which reads as
            // the tool being broken rather than as a permission they lack.
            'capabilities' => $user->capabilityList(),
        ];
    }

    /**
     * Calculate allowance for each workspace.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function workspaceAllowances(
        \App\Domain\Tenancy\Models\Account $account,
        TenantContext $tenant
    ): array {
        $allowances = [];
        $workspaces = Navigation::workspacesFor($tenant);

        foreach ($workspaces as $workspace) {
            // Get the internal workspace ID from the model
            $workspaceModel = \App\Domain\Tenancy\Models\Workspace::withoutGlobalScopes()
                ->where('public_id', $workspace['id'])
                ->first();

            if ($workspaceModel !== null) {
                $businessAllowance = Allowance::for($account)->toPayload($workspaceModel->id);
                $allowances[$workspace['id']] = $businessAllowance['businesses'];
            }
        }

        return $allowances;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function tenant(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        $tenant = app(TenantContext::class);
        $account = $tenant->account();
        $business = $tenant->business();
        $workspace = $tenant->workspace();

        if ($account === null) {
            return null;
        }

        return [
            'account' => [
                'id' => $account->public_id,
                'name' => $account->name,
                'status' => $account->status,
                'currency' => $account->base_currency,
                'trial_ends_at' => $account->trial_ends_at?->toIso8601String(),
                'usable' => $account->isUsable(),
                'onboarding_completed' => $account->onboarding_completed ?? false,
                'onboarding_steps' => $account->onboarding_steps ?? [],
            ],
            'workspace' => $workspace === null ? null : [
                'id' => $workspace->public_id,
                'name' => $workspace->name,
                'slug' => $workspace->slug,
                'icon' => $workspace->icon,
            ],
            'business' => $business === null ? null : [
                'id' => $business->public_id,
                'workspace' => $workspace?->public_id,
                'name' => $business->name,
                'short_code' => $business->short_code,
                'currency' => $business->base_currency,
                'logo_url' => $business->logoMedia?->url(),
            ],
            'workspaces' => Navigation::workspacesFor($tenant),
            'businesses' => Navigation::businessesFor($tenant),
            // What the plan still allows, so the create buttons can be drawn
            // honestly rather than offered and then refused.
            'allowance' => Allowance::for($account)->toPayload($workspace?->id),
            // Per-workspace allowances for the businesses page
            'workspace_allowances' => self::workspaceAllowances($account, $tenant),
        ];
    }
}
