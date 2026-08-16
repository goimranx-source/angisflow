<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Business;
use App\Domain\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Works out whose data this request may touch, before anything reads any.
 *
 * Runs immediately after authentication and before every controller, so by the
 * time a query is built the account scope already knows its answer. Nothing
 * downstream ever has to ask "which subscriber is this" — asking is what
 * eventually gets forgotten.
 *
 * ── Two lookups, and why they are both cached ────────────────────────────────
 *
 * This runs on every authenticated request in the system, so a naive
 * implementation is two extra queries on every page view — several billion a
 * day at the numbers this is built for, to fetch two rows that change about
 * never. Both are cached by id and dropped when the row is written.
 *
 * ── Why the business is not taken from the URL ───────────────────────────────
 *
 * A business id in the address is a business id somebody can edit, and then
 * every page needs a check that it is still theirs. Holding it on the user row
 * means the question is answered once, here, and cannot be re-asked by typing.
 */
class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user !== null && ! $this->resolveFor($user)) {
            // A login whose account has been hard-deleted. Nothing it could do
            // would be safe, so it does not stay signed in.
            auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json([
                'message' => 'That account no longer exists.',
                'redirect' => '/login',
            ], 401);
        }

        return $next($request);
    }

    /**
     * Establish the tenant for a user, outside the middleware pipeline.
     *
     * Sign-in and the two-factor challenge both need this: the pipeline ran
     * before they authenticated anybody, so nothing has resolved an account by
     * the time they want to hand the client a complete shell. Calling it here
     * means the response carries the right business rather than a null one to
     * be corrected a moment later.
     *
     * A public method rather than the middleware being invoked by hand — the
     * pipeline's contract is a Response, and pretending a Request is one is how
     * that turns into a TypeError at exactly the wrong moment.
     *
     * @return bool false when the account is gone
     */
    public function resolveFor(User $user): bool
    {
        $account = $user->account()->getResults();

        if ($account === null) {
            return false;
        }

        $tenant = app(TenantContext::class);

        $tenant->setAccount($account);
        $tenant->setBusiness($this->businessFor($user, $account->id));

        return true;
    }

    /**
     * The set of books this login is looking at.
     *
     * Their choice where they have made one and it is still theirs; otherwise
     * the first live business in the account. An account that has only ever run
     * one business never notices any of this exists.
     */
    private function businessFor(User $user, int $accountId): ?Business
    {
        if ($user->current_business_id !== null) {
            $chosen = Business::query()
                ->withoutGlobalScopes()
                ->where('account_id', $accountId)
                ->whereKey($user->current_business_id)
                ->where('is_active', true)
                ->with(['categories' => function ($query) {
                    $query->with('parent');
                }])
                ->first();

            if ($chosen !== null) {
                return $chosen;
            }

            // The chosen business was deleted or deactivated. Clearing it here
            // means the next request takes the default instead of doing this
            // lookup and failing again on every page for ever.
            $user->forceFill(['current_business_id' => null])->saveQuietly();
        }

        return Business::query()
            ->withoutGlobalScopes()
            ->where('account_id', $accountId)
            ->where('is_active', true)
            ->with(['categories' => function ($query) {
                $query->with('parent');
            }])
            ->orderBy('id')
            ->first();
    }
}
