<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\TenantContext;
use App\Http\Middleware\ResolveTenant;
use App\Support\BootPayload;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The only page the server renders.
 *
 * Every address that is not /api/v1/* lands here and gets the same document.
 * React reads the URL and decides what to draw; from that moment on, navigating
 * inside the application never touches this controller again — no request, no
 * round trip, no waiting. That is what makes moving between screens feel like
 * nothing happened, because on the network nothing did.
 *
 * ── Why the boot payload is inlined ──────────────────────────────────────────
 *
 * The usual single-page application does this on a cold load:
 *
 *   1. fetch the HTML          (empty frame)
 *   2. fetch the bundle        (still empty)
 *   3. run it, then ask the server who is signed in
 *   4. wait
 *   5. finally draw the sidebar
 *
 * Steps 3 and 4 are a round trip that happens *after* the JavaScript has
 * already started, so it cannot be parallelised away — and it is why plenty of
 * SPAs show a spinner where their navigation should be. Sending the answer with
 * the document removes the step entirely: the first render already knows the
 * user, the business and the menu.
 *
 * It is revalidated from /api/v1/bootstrap in the background, so a stale
 * document — one served from a browser's back-forward cache, say — corrects
 * itself without ever showing an empty shell.
 *
 * ── Why the tenant is resolved here by hand ──────────────────────────────────
 *
 * This route is not in the API middleware group, so ResolveTenant — which is —
 * never runs for it. The `web` group still gives it a session and a signed-in
 * user, which is why this looked fine: the payload had an `auth` block and
 * nobody noticed `tenant` was null beside it.
 *
 * The consequence was small until money moved to the business. BootPayload
 * asks CurrencyService what the figures are counted in; with no tenant it
 * answered with the account's fallback, so the first paint drew every figure
 * with the wrong currency's symbol and corrected itself a moment later when
 * /bootstrap replied. A dashboard that flickers from taka to afghani on every
 * cold load is not a rendering detail, it is the screen telling the reader it
 * does not know what its own numbers mean.
 *
 * ResolveTenant exposes resolveFor() for exactly this — sign-in and the
 * two-factor challenge already use it, for the same reason: they need a
 * resolved tenant outside the pipeline that normally provides one.
 */
class SpaController extends Controller
{
    public function __invoke(Request $request, TenantContext $tenant, ResolveTenant $resolver): View
    {
        /** @var User|null $user */
        $user = $request->user();

        // Only when nothing has resolved it already, so this stays a fallback
        // for the one route that misses the middleware rather than a second
        // place that decides tenancy.
        if ($user !== null && ! $tenant->hasAccount()) {
            $resolver->resolveFor($user);
        }

        return view('app', [
            'boot' => BootPayload::build(),
        ]);
    }
}
