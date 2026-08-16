<?php

declare(strict_types=1);

namespace App\Http\Api\V1\Auth;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Support\AuthEventRecorder;
use App\Http\Api\Endpoint;
use App\Http\Middleware\RequireTwoFactor;
use App\Http\Middleware\ResolveTenant;
use App\Http\Requests\Auth\LoginRequest;
use App\Support\BootPayload;
use App\Support\Landing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Signing in and out.
 *
 * ── Cookies, not tokens ──────────────────────────────────────────────────────
 *
 * The API is same-origin, so the session cookie authenticates it and stays
 * HttpOnly where no script can read it. The alternative — minting a bearer
 * token and keeping it in localStorage — hands every cross-site scripting bug
 * in the application a permanent credential, and buys nothing a browser talking
 * to its own origin needs.
 *
 * Third parties are a different audience with different needs, and they get
 * Sanctum tokens on their own route group. That is not a reason to make our own
 * screens less safe.
 */
class LoginEndpoint extends Endpoint
{
    public function store(LoginRequest $request): JsonResponse
    {
        $request->authenticate();

        /** @var User $user */
        $user = $request->user();

        // Session fixation defence, and not optional. Without it the session id
        // the visitor arrived with — which anybody who handed them a link could
        // have chosen — is the id they are now authenticated on.
        $request->session()->regenerate();

        // A fresh session has not passed the second factor, whatever the
        // previous one did. Cleared explicitly rather than relied upon: "the
        // old value happens not to survive" is not a security property.
        RequireTwoFactor::forget($request, $user);

        $user->forceFill(['last_seen_at' => now()])->saveQuietly();

        AuthEventRecorder::record(AuthEventRecorder::LOGIN, $user, method: 'password');

        if ($user->hasTwoFactorEnabled()) {
            return response()->json([
                'two_factor_required' => true,
                'redirect' => '/two-factor',
            ]);
        }

        // The whole boot payload comes back with the sign-in, so the client can
        // draw the application immediately instead of signing in and then
        // asking who it just signed in as.
        return response()->json([
            'two_factor_required' => false,
            'redirect' => Landing::forUser($user),
            'boot' => $this->bootAfterLogin($user),
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user !== null) {
            AuthEventRecorder::record(AuthEventRecorder::LOGOUT, $user);
        }

        Auth::guard('web')->logout();

        // Both, in this order. Invalidating drops the session data; regenerating
        // the token means the sign-in form they land on carries a CSRF token
        // belonging to the new anonymous session rather than the dead one —
        // which is otherwise what makes the next sign-in fail with a 419 on a
        // shared computer.
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['redirect' => '/login']);
    }

    /**
     * The boot payload as it stands *after* authenticating.
     *
     * The tenant middleware ran before this request was authenticated, so
     * nothing has resolved an account yet. Resolving it here means the response
     * carries a complete shell rather than one with a null business that has to
     * be corrected a moment later.
     */
    private function bootAfterLogin(User $user): array
    {
        app(ResolveTenant::class)->resolveFor($user);

        return BootPayload::build();
    }
}
