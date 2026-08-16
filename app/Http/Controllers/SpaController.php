<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Tenancy\TenantContext;
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
 */
class SpaController extends Controller
{
    public function __invoke(Request $request, TenantContext $tenant): View
    {
        return view('app', [
            'boot' => BootPayload::build(),
        ]);
    }
}
