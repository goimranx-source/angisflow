<?php

declare(strict_types=1);

namespace App\Http\Api\V1\Auth;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Support\AuthEventRecorder;
use App\Domain\Identity\Support\TwoFactor;
use App\Http\Api\Endpoint;
use App\Http\Middleware\RequireTwoFactor;
use App\Http\Middleware\ResolveTenant;
use App\Support\BootPayload;
use App\Support\Landing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * The second factor: answering the challenge, and turning it on and off.
 *
 * ── The two-step enable, and why it is two steps ─────────────────────────────
 *
 * Generating a secret does not switch anything on. The user has to prove they
 * can produce a code from it first, and only then is two_factor_confirmed_at
 * written.
 *
 * Skipping that — enabling on the strength of "we showed you a QR code" — locks
 * out everybody who closed the tab before scanning, everybody whose phone clock
 * is wrong, and everybody who scanned it into an app they then deleted. None of
 * them can sign in to fix it, because fixing it requires signing in.
 */
class TwoFactorEndpoint extends Endpoint
{
    public function __construct(private readonly TwoFactor $twoFactor) {}

    /** What the challenge screen needs to draw itself. */
    public function status(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'data' => [
                'enabled' => $user->hasTwoFactorEnabled(),
                'passed' => RequireTwoFactor::hasPassed($request, $user),
                'recovery_codes_left' => count($user->recoveryCodes()),
            ],
        ])->header('Cache-Control', 'no-store, private');
    }

    /** Answer the challenge with a code, or spend a recovery code. */
    public function challenge(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $request->validate([
            'code' => ['nullable', 'string'],
            'recovery_code' => ['nullable', 'string'],
        ]);

        $key = 'two-factor:'.$user->getKey();

        // Six digits is a million possibilities in a thirty-second window. That
        // is only strong while guessing is slow — unthrottled, a script walks
        // the whole space in minutes.
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'code' => __('auth.throttle', [
                    'seconds' => RateLimiter::availableIn($key),
                    'minutes' => (int) ceil(RateLimiter::availableIn($key) / 60),
                ]),
            ]);
        }

        $recovery = $request->string('recovery_code')->value();
        $code = $request->string('code')->value();

        if ($recovery !== '') {
            if (! $this->twoFactor->consumeRecoveryCode($user, $recovery)) {
                RateLimiter::hit($key, 300);
                AuthEventRecorder::record(AuthEventRecorder::TWO_FACTOR_FAILED, $user, method: 'recovery');

                throw ValidationException::withMessages([
                    'recovery_code' => 'That recovery code is not valid, or has already been used.',
                ]);
            }

            AuthEventRecorder::record(AuthEventRecorder::RECOVERY_CODE_USED, $user, method: 'recovery', context: [
                'remaining' => count($user->fresh()?->recoveryCodes() ?? []),
            ]);
        } else {
            if ($code === '' || ! $this->twoFactor->verify((string) $user->two_factor_secret, $code)) {
                RateLimiter::hit($key, 300);
                AuthEventRecorder::record(AuthEventRecorder::TWO_FACTOR_FAILED, $user, method: 'totp');

                throw ValidationException::withMessages([
                    'code' => 'That code is not right. Check your authenticator app and try again.',
                ]);
            }

            AuthEventRecorder::record(AuthEventRecorder::TWO_FACTOR_PASSED, $user, method: 'totp');
        }

        RateLimiter::clear($key);

        // A new session id at the moment privilege rises, for the same reason
        // as at sign-in: whatever id existed while the account was only half
        // authenticated should not carry a fully authenticated session.
        $request->session()->regenerate();
        RequireTwoFactor::markPassed($request, $user);

        app(ResolveTenant::class)->resolveFor($user);

        return response()->json([
            'redirect' => Landing::forUser($user),
            'boot' => BootPayload::build(),
        ]);
    }

    /**
     * Start enabling: mint a secret, return the QR code.
     *
     * Stored immediately but unconfirmed, so it survives a page refresh —
     * otherwise scanning the code and then reloading leaves the phone holding a
     * secret the server has forgotten.
     */
    public function begin(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->hasTwoFactorEnabled()) {
            return response()->json(['message' => 'Two-factor is already on.'], 409);
        }

        $secret = $this->twoFactor->generateSecret();

        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => null,
        ])->save();

        $uri = $this->twoFactor->provisioningUri($user, $secret);

        return response()->json([
            'secret' => $secret,
            // Rendered here so the secret never has to reach a JavaScript QR
            // library. A secret is only as private as the least careful thing
            // that has touched it.
            'qr' => $this->twoFactor->qrCodeSvg($uri),
            'uri' => $uri,
        ]);
    }

    /** Prove a code works, and switch it on. */
    public function confirm(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $request->validate(['code' => ['required', 'string']]);

        if ($user->two_factor_secret === null) {
            return response()->json(['message' => 'Start again — there is no pending secret.'], 409);
        }

        if (! $this->twoFactor->verify($user->two_factor_secret, $request->string('code')->value())) {
            throw ValidationException::withMessages([
                'code' => 'That code is not right. Check the app and try again.',
            ]);
        }

        $plainCodes = $this->twoFactor->generateRecoveryCodes();

        $user->forceFill([
            'two_factor_confirmed_at' => now(),
            // Hashed. These are eight passwords that each bypass the second
            // factor completely, and keeping them readable means one database
            // read hands over 2FA for every user who has ever enabled it.
            'two_factor_recovery_codes' => $this->twoFactor->hashCodes($plainCodes),
        ])->save();

        RequireTwoFactor::markPassed($request, $user);

        AuthEventRecorder::record(AuthEventRecorder::TWO_FACTOR_ENABLED, $user);

        return response()->json([
            // Shown once, now. No route returns these again, because nothing
            // stored could produce them.
            'recovery_codes' => $plainCodes,
        ]);
    }

    /** Turn it off. Behind a fresh password check — see the route. */
    public function destroy(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        RequireTwoFactor::forget($request, $user);

        AuthEventRecorder::record(AuthEventRecorder::TWO_FACTOR_DISABLED, $user);

        return response()->json(['message' => 'Two-factor authentication is off.']);
    }

    /** Replace the recovery codes, after losing track of the old ones. */
    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->hasTwoFactorEnabled()) {
            return response()->json(['message' => 'Two-factor is not on.'], 409);
        }

        $plainCodes = $this->twoFactor->generateRecoveryCodes();

        $user->forceFill([
            'two_factor_recovery_codes' => $this->twoFactor->hashCodes($plainCodes),
        ])->save();

        return response()->json(['recovery_codes' => $plainCodes]);
    }
}
