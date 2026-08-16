<?php

declare(strict_types=1);

namespace App\Http\Api\V1\Auth;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Support\AuthEventRecorder;
use App\Http\Api\Endpoint;
use App\Http\Middleware\RequireTwoFactor;
use App\Http\Middleware\ResolveTenant;
use App\Support\BootPayload;
use App\Support\Landing;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laragear\WebAuthn\Http\Requests\AssertedRequest;
use Laragear\WebAuthn\Http\Requests\AssertionRequest;
use Laragear\WebAuthn\Http\Requests\AttestationRequest;
use Laragear\WebAuthn\Http\Requests\AttestedRequest;
use Laragear\WebAuthn\Models\WebAuthnCredential;

/**
 * Passkeys: signing in with one, and managing the ones on an account.
 *
 * ── Why they are worth having ────────────────────────────────────────────────
 *
 * A passkey is the only common credential that cannot be phished. The private
 * key never leaves the device, the signature is bound to the origin, and a
 * convincing copy of the sign-in page at a lookalike domain gets nothing —
 * because the browser will not sign for a domain the key was not created for.
 * Passwords and TOTP codes both fail that test: both can be typed into the
 * wrong site by somebody having a bad morning.
 *
 * It is also faster than either, which is the part users notice.
 */
class PasskeyEndpoint extends Endpoint
{
    // ── Signing in ───────────────────────────────────────────────────────────

    /**
     * The challenge for signing in.
     *
     * No email is asked for. The browser offers whichever passkeys it holds for
     * this origin and the user picks one — which is both the pleasant path and
     * the private one, since an endpoint that takes an address before issuing a
     * challenge is an endpoint that can be asked which addresses have accounts.
     */
    public function loginOptions(AssertionRequest $request): Responsable
    {
        return $request->toVerify($request->validate([
            'email' => ['sometimes', 'string', 'email:filter'],
        ]));
    }

    public function login(AssertedRequest $request): JsonResponse
    {
        if (! $request->login()) {
            AuthEventRecorder::record(AuthEventRecorder::LOGIN_FAILED, method: 'passkey');

            return response()->json(['message' => 'That passkey was not accepted.'], 422);
        }

        /** @var User $user */
        $user = $request->user();

        // A deactivated login must not walk in through a door the password
        // check would have closed. The credential is still valid; the person is
        // no longer permitted.
        if (! $user->is_active) {
            auth()->logout();

            return response()->json(['message' => 'That account is not active.'], 403);
        }

        $request->session()->regenerate();
        RequireTwoFactor::forget($request, $user);

        $user->forceFill(['last_seen_at' => now()])->saveQuietly();

        AuthEventRecorder::record(AuthEventRecorder::LOGIN, $user, method: 'passkey');

        // A passkey is already two factors — something you have, plus whatever
        // the device demanded to unlock it — and the standard treats a
        // user-verified assertion as multi-factor in its own right. Asking for
        // a TOTP code on top is theatre unless the account explicitly runs both.
        if ($user->hasTwoFactorEnabled()) {
            return response()->json(['two_factor_required' => true, 'redirect' => '/two-factor']);
        }

        app(ResolveTenant::class)->resolveFor($user);

        return response()->json([
            'two_factor_required' => false,
            'redirect' => Landing::forUser($user),
            'boot' => BootPayload::build(),
        ]);
    }

    // ── Managing them ────────────────────────────────────────────────────────

    /**
     * The challenge for registering a new one.
     *
     * fastRegistration() lets a device reuse a key it already holds for this
     * account rather than forcing a fresh one, which is what makes adding a
     * second device pleasant instead of a chore.
     */
    public function options(AttestationRequest $request): Responsable
    {
        return $request->fastRegistration()->toCreate();
    }

    public function register(AttestedRequest $request): JsonResponse
    {
        $request->save();

        /** @var User $user */
        $user = $request->user();

        // Named after the browser that made it, so a list of four passkeys is
        // four things somebody can tell apart rather than four identical rows
        // with different dates.
        $latest = $user->webAuthnCredentials()->latest('created_at')->first();

        if ($latest !== null && ($request->filled('name') || $latest->alias === null)) {
            $latest->forceFill([
                'alias' => $request->string('name')->value() ?: $this->describeDevice($request),
            ])->save();
        }

        AuthEventRecorder::record(AuthEventRecorder::PASSKEY_REGISTERED, $user, method: 'passkey');

        return response()->json(['message' => 'Passkey added.'], 201);
    }

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'data' => $user->webAuthnCredentials()
                ->orderByDesc('created_at')
                ->get(['id', 'alias', 'created_at', 'updated_at', 'disabled_at'])
                ->map(fn (WebAuthnCredential $credential) => [
                    // Already a random opaque string, so there is nothing to
                    // leak by exposing it.
                    'id' => $credential->id,
                    'name' => $credential->alias ?: 'Unnamed passkey',
                    'created_at' => $credential->created_at?->toIso8601String(),
                    'last_used_at' => $credential->updated_at?->toIso8601String(),
                    'disabled' => $credential->disabled_at !== null,
                ])
                ->all(),
        ]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $request->validate(['name' => ['required', 'string', 'max:60']]);

        /** @var User $user */
        $user = $request->user();

        $credential = $user->webAuthnCredentials()->whereKey($id)->firstOrFail();
        $credential->forceFill(['alias' => $request->string('name')->value()])->save();

        return response()->json(['message' => 'Renamed.']);
    }

    /**
     * Remove one.
     *
     * The refusal below is not paternalism: deleting the last passkey on a
     * passkey-only account is the difference between a support ticket and a
     * permanently lost account.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $credential = $user->webAuthnCredentials()->whereKey($id)->firstOrFail();

        if (! $user->hasPassword() && $user->webAuthnCredentials()->count() <= 1) {
            return response()->json([
                'message' => 'That is the only way into this account. Set a password first.',
            ], 422);
        }

        $credential->delete();

        AuthEventRecorder::record(AuthEventRecorder::PASSKEY_REMOVED, $user, method: 'passkey');

        return response()->json(['message' => 'Passkey removed.']);
    }

    /** A readable name from the user agent — "Chrome on Windows". */
    private function describeDevice(Request $request): string
    {
        $agent = (string) $request->userAgent();

        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'OPR/') => 'Opera',
            str_contains($agent, 'Firefox') => 'Firefox',
            str_contains($agent, 'Chrome') => 'Chrome',
            str_contains($agent, 'Safari') => 'Safari',
            default => 'Browser',
        };

        $platform = match (true) {
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'iPhone'), str_contains($agent, 'iPad') => 'iOS',
            str_contains($agent, 'Mac OS') => 'macOS',
            str_contains($agent, 'Linux') => 'Linux',
            default => null,
        };

        return $platform ? "{$browser} on {$platform}" : $browser;
    }
}
