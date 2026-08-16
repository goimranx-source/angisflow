<?php

declare(strict_types=1);

namespace App\Http\Api\V1\Auth;

use App\Domain\Billing\Models\Plan;
use App\Domain\Identity\Actions\RegisterAccount;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Support\AuthEventRecorder;
use App\Http\Api\Endpoint;
use App\Http\Middleware\ResolveTenant;
use App\Rules\Turnstile;
use App\Support\BootPayload;
use App\Support\Landing;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Signing up creates a subscriber, not a login.
 *
 * See RegisterAccount, which builds the account, the trial, the owner, the
 * first set of books and the starting roles in one transaction.
 */
class RegisterEndpoint extends Endpoint
{
    public function store(Request $request, RegisterAccount $register): JsonResponse
    {
        abort_unless(config('prism.registration.enabled'), 404);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'business' => ['nullable', 'string', 'max:120'],
            'email' => ['required', 'string', 'email:filter', 'max:255'],
            'password' => [
                'required',
                'confirmed',
                'string',
                // Length first, then the leak check. Composition rules — a
                // symbol, a digit, a capital — mostly produce Password1! and
                // are not asked for; length and "has this one already been
                // stolen" are what actually correlate with a password holding.
                Password::min(10)->uncompromised(),
            ],
            'timezone' => ['nullable', 'string', 'max:64'],
            'cf-turnstile-response' => [new Turnstile],
        ]);

        // Not a database unique rule, deliberately.
        //
        // `unique:users,email` would be wrong anyway — the same address can
        // legitimately hold a login at two subscribers — but the reason to
        // check by hand is that a validation error on a sign-up form is a free
        // way to ask "does this person have an account", one address at a time.
        // Owners are the exception worth guarding: an address owns one account.
        $ownsAlready = User::query()
            ->withoutGlobalScopes()
            ->where('email', mb_strtolower(trim($validated['email'])))
            ->where('is_owner', true)
            ->exists();

        if ($ownsAlready) {
            throw ValidationException::withMessages([
                'email' => 'That address already runs a business here. Sign in instead.',
            ]);
        }

        $owner = $register->handle($validated);

        // Sends the verification mail. The tool stays usable while unverified —
        // locking a paying subscriber out over an undelivered email is a
        // support ticket, not a security control — but the prompt is there.
        event(new Registered($owner));

        Auth::login($owner);
        $request->session()->regenerate();

        AuthEventRecorder::record(AuthEventRecorder::REGISTERED, $owner, method: 'password');

        // Resolve the tenant now, so the response carries a complete shell
        // rather than one with a null business to be corrected a moment later.
        app(ResolveTenant::class)->resolveFor($owner);

        // Get public plans for the onboarding flow
        $plans = Plan::query()
            ->where('is_public', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Plan $plan) => [
                'id' => $plan->id,
                'code' => $plan->code,
                'name' => $plan->name,
                'description' => $plan->description,
                'price_minor' => $plan->price_minor,
                'currency' => $plan->currency,
                'interval' => $plan->interval,
                'trial_days' => $plan->trial_days,
                'limits' => $plan->limits,
                'features' => $plan->features,
            ]);

        return response()->json([
            'redirect' => Landing::forUser($owner),
            'boot' => BootPayload::build(),
            'plans' => $plans,
        ], 201);
    }
}
