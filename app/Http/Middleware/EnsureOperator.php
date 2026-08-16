<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Allows only platform operators through.
 *
 * An operator is a member of the Angisflow team, not a subscriber. The flag
 * lives on the users table so operators sign in through the same flow as
 * everyone else — no separate auth surface to maintain and no separate session
 * to steal.
 *
 * Returns 403 rather than 404. Hiding the existence of the operator panel
 * behind a 404 is security through obscurity; the routes are in the source
 * code. A 403 is honest: the resource exists, you are not allowed.
 */
class EnsureOperator
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->is_operator) {
            abort(403, 'Operator access required.');
        }

        return $next($request);
    }
}
