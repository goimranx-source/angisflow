<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stops a suspended or cancelled subscriber from working, and lets everyone
 * else through.
 *
 * ── What counts as "cannot work" ─────────────────────────────────────────────
 *
 * Suspended and cancelled. Not past due.
 *
 * Locking a business out of its own ledger over a failed card is how a
 * subscriber becomes an ex-subscriber with a public complaint about being held
 * hostage. Somebody whose payment bounced still has staff taking orders and
 * still has a month end to close; the place to apply pressure is a banner they
 * see on every screen, not the front door.
 *
 * Cancelled is different — they left — and suspended is different again:
 * somebody made a deliberate decision about that account.
 */
class EnsureAccountIsUsable
{
    public function handle(Request $request, Closure $next): Response
    {
        $account = app(TenantContext::class)->account();

        if ($account === null || $account->isUsable()) {
            return $next($request);
        }

        // Read-only from here: the billing screen has to stay reachable, or
        // there is no way to fix the thing that caused the lock-out.
        if ($request->routeIs('billing.*', 'logout', 'profile.*')) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'This account is not active.',
                'status' => $account->status,
            ], 402);
        }

        return redirect()->route('billing.locked');
    }
}
