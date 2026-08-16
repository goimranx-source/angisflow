<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signed-out only.
 *
 * Laravel's own `guest` middleware answers with a redirect, which is right for
 * a browser following a link and wrong for a fetch: the fetch follows the
 * redirect, lands on the HTML shell, and the client's JSON parser fails on
 * "<!DOCTYPE" — an error that has nothing to do with what actually happened.
 *
 * A status the caller can act on, instead. 409 rather than 403: signing in
 * while already signed in is not forbidden, it is a request that conflicts with
 * the state the session is already in, and the client's response is to go to
 * the application rather than to show a refusal.
 */
class EnsureGuest
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::guard('web')->check()) {
            return $next($request);
        }

        return response()->json([
            'message' => 'You are already signed in.',
            'redirect' => '/dashboard',
        ], 409);
    }
}
