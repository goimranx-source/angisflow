<?php

declare(strict_types=1);

namespace App\Http\Api\V1\Auth;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Support\AuthEventRecorder;
use App\Http\Api\Endpoint;
use App\Rules\Turnstile;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

/**
 * Forgetting, resetting, confirming and changing a password.
 */
class PasswordEndpoint extends Endpoint
{
    /**
     * Send a reset link — and say the same thing either way.
     *
     * The response is identical whether the address exists or not. Reporting
     * "we have no account for that" turns this endpoint into an oracle for
     * testing a list of leaked addresses against the user base, one request at
     * a time, with no password needed.
     */
    public function forgot(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'string', 'email:filter', 'max:255'],
            'cf-turnstile-response' => [new Turnstile],
        ]);

        $email = mb_strtolower(trim((string) $request->input('email')));

        // Throttled by address and by source. Unthrottled, this endpoint sends
        // mail to anybody as fast as it is asked to — which is a way to use our
        // own mail reputation to flood somebody else's inbox.
        $key = 'password-reset:'.Str::transliterate($email).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 3)) {
            throw ValidationException::withMessages([
                'email' => __('passwords.throttled'),
            ]);
        }

        RateLimiter::hit($key, 900);

        Password::sendResetLink(['email' => $email]);

        return response()->json([
            'message' => 'If that address has an account here, a reset link is on its way.',
        ]);
    }

    public function reset(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email:filter'],
            'password' => ['required', 'confirmed', PasswordRule::min(10)->uncompromised()],
        ]);

        $status = Password::reset(
            [
                'email' => mb_strtolower(trim((string) $request->input('email'))),
                'password' => $request->string('password')->value(),
                'password_confirmation' => $request->string('password_confirmation')->value(),
                'token' => $request->string('token')->value(),
            ],
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => $password,
                    // A reset is what somebody does when they think their
                    // account is compromised. Rotating the remember token is
                    // what actually ends an intruder's access: without it a
                    // stolen "remember me" cookie keeps working long after the
                    // password it was issued against has changed.
                    'remember_token' => Str::random(60),
                ])->save();

                // And every other session. A new password is worth nothing if
                // the old session is still signed in beside it.
                $this->dropOtherSessions($user);

                event(new PasswordReset($user));

                AuthEventRecorder::record(AuthEventRecorder::PASSWORD_RESET, $user, method: 'password');
            }
        );

        if ($status !== Password::PasswordReset) {
            throw ValidationException::withMessages(['email' => __($status)]);
        }

        return response()->json([
            'message' => 'Your password has been changed. Sign in with it.',
            'redirect' => '/login',
        ]);
    }

    /**
     * "Confirm your password" — the check in front of the handful of things
     * that would be catastrophic if somebody sat down at an unlocked laptop.
     *
     * A live session proves somebody signed in this morning; it does not prove
     * the person at the keyboard now is the same one.
     */
    public function confirm(Request $request): JsonResponse
    {
        $request->validate(['password' => ['required', 'string']]);

        // Throttled like a sign-in, because that is what it is: an unlimited
        // password check behind an authenticated session is a way to brute
        // force a password without ever touching the login endpoint or its
        // limits.
        $key = 'password-confirm:'.$request->user()->getKey();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'password' => __('auth.throttle', [
                    'seconds' => RateLimiter::availableIn($key),
                    'minutes' => (int) ceil(RateLimiter::availableIn($key) / 60),
                ]),
            ]);
        }

        $passes = Auth::guard('web')->validate([
            'email' => $request->user()->email,
            'password' => $request->string('password')->value(),
        ]);

        if (! $passes) {
            RateLimiter::hit($key, 300);

            throw ValidationException::withMessages(['password' => __('auth.password')]);
        }

        RateLimiter::clear($key);

        $request->session()->put('auth.password_confirmed_at', time());

        return response()->json(['message' => 'Confirmed.']);
    }

    /** Change it from the profile screen. */
    public function update(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'current_password' => [$user->hasPassword() ? 'required' : 'nullable', 'string'],
            'password' => ['required', 'confirmed', PasswordRule::min(10)->uncompromised()],
        ]);

        // Checked by hand rather than with the current_password rule, so an
        // account that has never had one — passkey-only — can set its first.
        if ($user->hasPassword() && ! Hash::check($validated['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'That is not your current password.',
            ]);
        }

        $user->forceFill(['password' => $validated['password']])->save();

        // Keeps this session alive while invalidating the rest, so changing a
        // password does not sign you out of the tab you changed it in.
        Auth::logoutOtherDevices($validated['password']);

        AuthEventRecorder::record(AuthEventRecorder::PASSWORD_CHANGED, $user);

        return response()->json([
            'message' => 'Password changed. Any other sessions have been signed out.',
        ]);
    }

    /**
     * Drop every stored session belonging to this user.
     *
     * Only possible on the database session driver; on Redis the equivalent is
     * a key sweep and on the cookie driver there is nothing stored to drop.
     * Written defensively because a reset must not fail on an install
     * configured differently.
     */
    private function dropOtherSessions(User $user): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        DB::table(config('session.table', 'sessions'))
            ->where('user_id', $user->getKey())
            ->delete();
    }
}
