<?php

declare(strict_types=1);

namespace App\Http\Api\V1;

use App\Domain\Billing\Allowance;
use App\Domain\Catalogue\Models\BusinessCategory;
use App\Domain\Catalogue\ModuleProvisioner;
use App\Domain\Identity\Models\User;
use App\Domain\Ledger\ChartOfAccounts;
use App\Domain\Localization\Countries;
use App\Domain\Tenancy\Models\Business;
use App\Domain\Tenancy\Models\Workspace;
use App\Domain\Tenancy\TenantContext;
use App\Http\Api\Endpoint;
use App\Support\BootPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Workspaces — what the subscription is sold in.
 *
 * Creating one is the single place a plan ceiling is enforced, and it is
 * enforced here rather than in the screen that draws the button: the button is
 * a courtesy, the check is the rule. A client that has cached a stale count, or
 * simply posts the request directly, meets the same answer.
 */
class WorkspaceEndpoint extends Endpoint
{
    public function __construct(
        private readonly ModuleProvisioner $provisioner,
        private readonly ChartOfAccounts $chart,
    ) {}

    /**
     * The categories the create form offers.
     *
     * Public to any signed-in user rather than owner-only: the form that reads
     * it is behind the owner check already, and the list itself is product
     * information, not anybody's data.
     *
     * Each row carries how many modules its preset switches on, because the
     * last step of the create flow tells the subscriber what they are about to
     * get. Counted once for the whole list rather than per row — the naive
     * version is fifty-three queries to draw nine cards.
     */
    public function categories(): JsonResponse
    {
        $categories = BusinessCategory::query()
            ->topLevel()
            ->active()
            ->ordered()
            ->with(['children' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order')])
            ->get();

        // Core counts even where the preset says false. ModuleProvisioner
        // forces it on, and a summary that promises 51 while the workspace
        // arrives with 52 is a small lie that costs more trust than the number
        // was ever worth.
        $counts = DB::table('category_module_presets as preset')
            ->join('modules as module', 'module.id', '=', 'preset.module_id')
            ->where(fn ($q) => $q->where('preset.enabled_by_default', true)->orWhere('module.is_core', true))
            ->selectRaw('preset.business_category_id as category_id, COUNT(*) as total')
            ->groupBy('preset.business_category_id')
            ->pluck('total', 'category_id');

        foreach ($categories as $category) {
            $category->preset_module_count = (int) ($counts[$category->id] ?? 0);

            foreach ($category->children as $child) {
                // A subcategory with no presets of its own inherits its
                // parent's — the same fallback presetSource() applies when the
                // workspace is actually provisioned, so the number shown and
                // the number delivered are the same number.
                $child->preset_module_count = (int) ($counts[$child->id] ?? $category->preset_module_count);
            }
        }

        return response()->json([
            'data' => $categories->map->toPayload()->all(),
        ]);
    }

    /**
     * Every country a set of books can be opened in, with its money and clock.
     *
     * Two hundred and forty-three rows of reference data that change on deploy
     * and are the same for everybody, so this is cached hard at the edge rather
     * than rebuilt per request.
     */
    public function countries(): JsonResponse
    {
        return response()
            ->json(['data' => Countries::toPayload(), 'currencies' => Countries::currencies()])
            ->header('Cache-Control', 'public, max-age=86400');
    }

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $workspaces = Workspace::query()
            ->active()
            ->withCount(['businesses' => fn ($q) => $q->where('is_active', true)])
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $workspaces->map(fn (Workspace $workspace) => [
                ...$workspace->toPayload(),
                'business_count' => $workspace->businesses_count,
            ])->all(),
            'allowance' => Allowance::for($user->account)->toPayload(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'icon' => ['nullable', 'string', 'max:50'],
            // Optional, and stays optional. Somebody who does not recognise
            // themselves in any of the categories gets the core modules and
            // picks the rest themselves — which is a worse first run than
            // choosing well, but a better one than being made to lie.
            'category' => ['nullable', 'string', 'max:60', 'exists:business_categories,key'],
        ]);

        /** @var User $user */
        $user = $request->user();

        if (! $user->is_owner) {
            return response()->json([
                'message' => 'Only the account owner can add a workspace.',
            ], 403);
        }

        $allowance = Allowance::for($user->account);

        if (! $allowance->canAddWorkspace()) {
            return response()->json([
                'message' => 'Your plan does not include another workspace.',
                'allowance' => $allowance->toPayload(),
            ], 402);
        }

        $category = isset($validated['category'])
            ? BusinessCategory::where('key', $validated['category'])->first()
            : null;

        $workspace = DB::transaction(function () use ($user, $validated, $category) {
            $workspace = Workspace::create([
                'account_id' => $user->account_id,
                'business_category_id' => $category?->id,
                'name' => $validated['name'],
                'slug' => Workspace::uniqueSlug($user->account_id, $validated['name']),
                'icon' => $validated['icon'] ?? null,
                'is_active' => true,
            ]);

            // Inside the transaction: a workspace that exists with no module
            // rows is one whose sidebar is empty, and the subscriber has no
            // way to tell that from a broken account.
            $this->provisioner->provision($workspace, $category);

            return $workspace;
        });

        return response()->json([
            'message' => "{$workspace->name} is ready.",
            'data' => $workspace->toPayload(),
        ], 201);
    }

    /**
     * Update a workspace.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9-]+$/'],
            'icon' => ['nullable', 'string', 'max:50'],
        ]);

        /** @var User $user */
        $user = $request->user();

        if (! $user->is_owner) {
            return response()->json([
                'message' => 'Only the account owner can edit a workspace.',
            ], 403);
        }

        $workspace = Workspace::query()->wherePublicId($id)->active()->first();

        if ($workspace === null) {
            return response()->json(['message' => 'That workspace is not available.'], 404);
        }

        // Check if slug is unique (excluding this workspace)
        $slugExists = Workspace::query()
            ->where('account_id', $user->account_id)
            ->where('slug', $validated['slug'])
            ->where('id', '!=', $workspace->id)
            ->exists();

        if ($slugExists) {
            return response()->json([
                'message' => 'That slug is already in use.',
                'errors' => ['slug' => ['That slug is already in use.']],
            ], 422);
        }

        $workspace->update([
            'name' => $validated['name'],
            'slug' => $validated['slug'],
            'icon' => $validated['icon'] ?? null,
        ]);

        return response()->json([
            'message' => "{$workspace->name} updated.",
        ]);
    }

    /**
     * Add a set of books to a workspace.
     *
     * The ceiling here is per workspace, not per account: a plan sells so many
     * workspaces of so many businesses each, so filling one says nothing about
     * whether another has room.
     */
    public function storeBusiness(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'short_code' => ['nullable', 'string', 'max:12'],
            'base_currency' => ['nullable', 'string', 'size:3'],
            'business_category_id' => ['nullable', 'integer', 'exists:business_categories,id'],
            // Was being collected by the form and silently dropped here. A
            // country that does not round-trip is worse than no field at all:
            // the subscriber answered and we lost it.
            'country' => ['nullable', 'string', 'size:2'],
            'timezone' => ['nullable', 'string', 'timezone'],
            'logo' => ['nullable', 'image', 'max:2048'], // 2MB max
        ]);

        /** @var User $user */
        $user = $request->user();

        // A short code composes every SKU and order reference, so the database
        // holds it unique per account. It was not being checked here, which
        // meant a duplicate reached the insert and came back as a 500 — the
        // subscriber sees "something went wrong" for what is really "that code
        // is taken", and the code they typed is lost with the form.
        $clash = $this->shortCodeClash($user->account_id, $validated['short_code'] ?? null);

        if ($clash !== null) {
            return response()->json([
                'message' => "The short code {$clash} is already used by another business.",
                'errors' => ['short_code' => ["{$clash} is already in use. Try another."]],
            ], 422);
        }

        $workspace = Workspace::query()->wherePublicId($id)->active()->first();

        if ($workspace === null) {
            return response()->json(['message' => 'That workspace is not available.'], 404);
        }

        $allowance = Allowance::for($user->account);

        if (! $allowance->canAddBusiness($workspace->id)) {
            return response()->json([
                'message' => 'This workspace has reached the number of businesses your plan allows.',
                'allowance' => $allowance->toPayload($workspace->id),
            ], 402);
        }

        $logoMediaId = null;
        
        // Handle logo upload
        if ($request->hasFile('logo')) {
            $storeMedia = new \App\Domain\Media\Actions\StoreMedia(app(\App\Domain\Tenancy\TenantContext::class));
            
            try {
                [$mediaItem, $existed] = $storeMedia->handle($request->file('logo'), $user->id);
                $logoMediaId = $mediaItem->id;
            } catch (\RuntimeException $e) {
                return response()->json([
                    'message' => 'Logo upload failed.',
                    'errors' => ['logo' => [$e->getMessage()]],
                ], 422);
            }
        }

        $country = isset($validated['country']) && Countries::has($validated['country'])
            ? strtoupper($validated['country'])
            : null;

        // Each falls back a step at a time rather than all the way to the
        // account: a business opened in Bangladesh should keep Dhaka time even
        // when the account was set up from London, and the country is a better
        // guess about both than the account holder's own settings.
        $currency = strtoupper(
            $validated['base_currency']
            ?? ($country !== null ? Countries::currency($country) : null)
            ?? $user->account->base_currency
        );

        $timezone = $validated['timezone']
            ?? ($country !== null ? Countries::timezone($country) : null)
            ?? $user->account->timezone;

        $business = DB::transaction(function () use ($user, $workspace, $validated, $logoMediaId, $currency, $country, $timezone) {
            $business = Business::create([
                'account_id' => $user->account_id,
                'workspace_id' => $workspace->id,
                'name' => $validated['name'],
                'short_code' => $validated['short_code'] ?? null,
                'logo_media_id' => $logoMediaId,
                'base_currency' => $currency,
                'country' => $country,
                'timezone' => $timezone,
                'is_active' => true,
            ]);
            
            // Attach category if provided
            if (isset($validated['business_category_id'])) {
                $business->categories()->attach($validated['business_category_id']);
            }

            // A set of books with no chart of accounts is a set of books that
            // cannot record anything, and every screen that posts would fail on
            // its first use. Inside the transaction, because a business that
            // exists without one is not a state worth having.
            $this->chart->install($business);

            return $business;
        });

        return response()->json([
            'message' => "{$business->name} is ready.",
            'data' => [
                'id' => $business->public_id,
                'name' => $business->name,
                'short_code' => $business->short_code,
                'currency' => $business->base_currency,
                'country' => $business->country,
                'timezone' => $business->timezone,
            ],
        ], 201);
    }

    /**
     * Update a business.
     */
    public function updateBusiness(Request $request, string $id): JsonResponse
    {
        \Log::info('Update business request', [
            'id' => $id,
            'data' => $request->except(['logo', '_method']),
            'has_logo' => $request->hasFile('logo'),
        ]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'short_code' => ['nullable', 'string', 'max:12'],
            'base_currency' => ['nullable', 'string', 'size:3'],
            'category_ids' => ['nullable', 'array'],
            'category_ids.*' => ['integer', 'exists:business_categories,id'],
            'country' => ['nullable', 'string', 'size:2'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:120'],
            'logo' => ['nullable', 'image', 'max:2048'], // 2MB max
            'remove_logo' => ['nullable', 'string'],
        ]);

        \Log::info('Validated data', ['validated' => $validated]);

        /** @var User $user */
        $user = $request->user();

        if (! $user->is_owner) {
            return response()->json([
                'message' => 'Only the account owner can edit a business.',
            ], 403);
        }

        $business = Business::query()->wherePublicId($id)->first();

        if ($business === null) {
            return response()->json(['message' => 'That business is not available.'], 404);
        }

        $clash = $this->shortCodeClash($user->account_id, $validated['short_code'] ?? null, $business->id);

        if ($clash !== null) {
            return response()->json([
                'message' => "The short code {$clash} is already used by another business.",
                'errors' => ['short_code' => ["{$clash} is already in use. Try another."]],
            ], 422);
        }

        $business->update([
            'name' => $validated['name'],
            'short_code' => $validated['short_code'] ?? null,
            'base_currency' => strtoupper($validated['base_currency'] ?? $user->account->base_currency),
            'country' => $validated['country'] ?? null,
            'timezone' => $validated['timezone'] ?? null,
            'address' => $validated['address'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'email' => $validated['email'] ?? null,
        ]);
        
        // Sync categories (many-to-many relationship)
        // If category_ids is not present or empty, sync with empty array to remove all
        $business->categories()->sync($validated['category_ids'] ?? []);

        // Handle logo upload
        if ($request->hasFile('logo')) {
            $storeMedia = new \App\Domain\Media\Actions\StoreMedia(app(\App\Domain\Tenancy\TenantContext::class));
            
            try {
                [$mediaItem, $existed] = $storeMedia->handle($request->file('logo'), $user->id);
                
                // Remove old logo if exists
                if ($business->logo_media_id && $business->logoMedia) {
                    $storeMedia->delete($business->logoMedia);
                }
                
                $business->update(['logo_media_id' => $mediaItem->id]);
            } catch (\RuntimeException $e) {
                return response()->json([
                    'message' => 'Logo upload failed.',
                    'errors' => ['logo' => [$e->getMessage()]],
                ], 422);
            }
        }

        // Handle logo removal
        if ($request->filled('remove_logo')) {
            if ($business->logo_media_id && $business->logoMedia) {
                $storeMedia = new \App\Domain\Media\Actions\StoreMedia(app(\App\Domain\Tenancy\TenantContext::class));
                $storeMedia->delete($business->logoMedia);
                $business->update(['logo_media_id' => null]);
            }
        }

        return response()->json([
            'message' => "{$business->name} updated.",
        ]);
    }

    /**
     * Open a workspace and a set of books inside it, in one call.
     *
     * The two selects in the sidebar change together — picking a workspace with
     * no business chosen would leave every figure on screen belonging to
     * nothing — so the server resolves both and returns one shell.
     */
    public function open(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'workspace' => ['required', 'string', 'size:26'],
            'business' => ['nullable', 'string', 'size:26'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $workspace = Workspace::query()->wherePublicId($validated['workspace'])->active()->first();

        if ($workspace === null) {
            return response()->json(['message' => 'That workspace is not available.'], 404);
        }

        $business = Business::query()
            ->where('workspace_id', $workspace->id)
            ->where('is_active', true)
            ->with(['categories' => function ($query) {
                $query->with('parent');
            }])
            ->when(
                filled($validated['business'] ?? null),
                fn ($q) => $q->wherePublicId($validated['business']),
                // No business named: the first in the workspace, so choosing a
                // workspace from the picker always lands somewhere real.
                fn ($q) => $q->orderBy('id'),
            )
            ->first();

        if ($business === null) {
            return response()->json([
                'message' => 'That workspace has no active business yet.',
            ], 409);
        }

        DB::transaction(function () use ($user, $business) {
            $user->forceFill(['current_business_id' => $business->id])->save();
        });

        app(TenantContext::class)->setBusiness($business);

        return response()->json([
            'message' => "Now showing {$business->name}.",
            'boot' => BootPayload::build(),
        ]);
    }

    /**
     * The normalised short code, if some other business already holds it.
     *
     * Normalised first, because the model's setter uppercases and strips
     * punctuation on the way in — checking the raw string would let "v-b" pass
     * a check against the stored "VB" and then collide at the insert anyway.
     *
     * Soft-deleted rows are excluded deliberately. They keep their codes only
     * until the deleting hook mangles them, and a removed business should not
     * go on reserving a code somebody wants back.
     */
    private function shortCodeClash(int $accountId, ?string $code, ?int $ignoreId = null): ?string
    {
        $clean = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $code) ?? '');

        if ($clean === '') {
            return null;
        }

        $taken = Business::query()
            ->withoutGlobalScopes()
            ->where('account_id', $accountId)
            ->where('short_code', $clean)
            ->whereNull('deleted_at')
            ->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();

        return $taken ? $clean : null;
    }

    /**
     * Remove a workspace, and the books kept inside it.
     *
     * ── Why this cascades rather than refusing ───────────────────────────────
     *
     * It used to refuse while the workspace held any business, on the reasoning
     * that deleting it would orphan books somebody was still keeping. That
     * reasoning produced a trap: removing the business was refused too, because
     * the account may not lose its last one. A subscriber with two workspaces —
     * one holding the only business — could delete neither, and the two
     * messages each pointed at the other. There was no sequence of actions that
     * got them out.
     *
     * So the workspace takes its businesses with it. That is what deleting a
     * container means everywhere else in software, it is what the confirmation
     * dialog now says out loud before anybody presses anything, and everything
     * involved is soft-deleted and recoverable.
     *
     * One guard is left, and it is a real one: the last workspace stays. An
     * account with none has nowhere to put anything back, which is not a state
     * a subscriber can undo from the screen.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->is_owner) {
            return response()->json(['message' => 'Only the account owner can remove a workspace.'], 403);
        }

        $workspace = Workspace::query()->wherePublicId($id)->first();

        if ($workspace === null) {
            return response()->json(['message' => 'That workspace is not available.'], 404);
        }

        if (Workspace::query()->active()->count() <= 1) {
            return response()->json([
                'message' => 'This is your only workspace, so it cannot be removed.',
            ], 409);
        }

        $held = Business::query()->where('workspace_id', $workspace->id)->get();
        $wasCurrent = $held->contains(fn (Business $b) => $b->id === $user->current_business_id);

        DB::transaction(function () use ($workspace, $held): void {
            // One at a time rather than a mass delete: each Business fires
            // model events that drop the cached switcher list and the plan
            // allowance, and a bulk query would skip every one of them.
            foreach ($held as $business) {
                $business->delete();
            }

            $workspace->delete();
        });

        // Somebody looking at books that just went needs to be somewhere real
        // when the response lands. If nothing survived, they land on no
        // business at all — which the shell draws as the empty state that
        // invites them to add one, not as a broken screen.
        if ($wasCurrent) {
            $next = Business::query()
                ->where('is_active', true)
                ->with(['categories' => function ($query) {
                    $query->with('parent');
                }])
                ->orderBy('id')
                ->first();

            $user->forceFill(['current_business_id' => $next?->id])->save();

            if ($next !== null) {
                app(TenantContext::class)->setBusiness($next);
            }
        }

        $count = $held->count();

        return response()->json([
            'message' => $count === 0
                ? "{$workspace->name} removed."
                : "{$workspace->name} removed, along with its {$count} ".($count === 1 ? 'business' : 'businesses').'.',
            'boot' => BootPayload::build(),
        ]);
    }

    /**
     * Remove a business.
     *
     * ── Why the last one may go too ──────────────────────────────────────────
     *
     * This used to refuse when it was the only business on the account, on the
     * reasoning that every screen reads against one and an account with none
     * can open nothing. That reasoning was wrong twice over.
     *
     * It is wrong about the software: an account with no business is exactly
     * what a brand new one looks like, and the shell already draws it — the
     * business list shows its empty state, the rail falls back to core modules,
     * and nothing throws. That path is not an error state, it is the first
     * five minutes of every account's life.
     *
     * And it is wrong about the person. Somebody clearing out a business they
     * set up wrongly, intending to add a better one, was being told to delete
     * the workspace around it — which is a bigger, more destructive action than
     * the one they asked for, and loses the workspace's name, icon, category
     * and module choices for no reason at all.
     */
    public function destroyBusiness(Request $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->is_owner) {
            return response()->json(['message' => 'Only the account owner can remove a business.'], 403);
        }

        $business = Business::query()->wherePublicId($id)->first();

        if ($business === null) {
            return response()->json(['message' => 'That business is not available.'], 404);
        }

        $wasCurrent = $user->current_business_id === $business->id;
        $workspaceId = $business->workspace_id;

        $business->delete();

        // Somebody looking at the business that just went needs to be somewhere
        // real when the response lands. A sibling in the same workspace first:
        // deleting one of three sets of books should not also move the header
        // to a different arm of the group. Then anything at all. Then nothing,
        // which is a state the shell draws rather than an error.
        if ($wasCurrent) {
            $next = Business::query()
                ->where('is_active', true)
                ->with(['categories' => function ($query) {
                    $query->with('parent');
                }])
                ->orderByRaw('CASE WHEN workspace_id = ? THEN 0 ELSE 1 END', [$workspaceId])
                ->orderBy('id')
                ->first();

            $user->forceFill(['current_business_id' => $next?->id])->save();
            app(TenantContext::class)->setBusiness($next);
        }

        $left = Business::query()->where('is_active', true)->count();

        return response()->json([
            'message' => $left === 0
                ? "{$business->name} removed. You have no businesses left — add one when you are ready."
                : "{$business->name} removed.",
            'boot' => BootPayload::build(),
        ]);
    }
}
