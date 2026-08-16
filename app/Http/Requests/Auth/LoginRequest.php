<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Domain\Identity\Support\AuthEventRecorder;
use App\Rules\Turnstile;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The sign-in attempt, and the throttling around it.
 *
 * ── Why two rate limiters and not one ────────────────────────────────────────
 *
 * Limiting by IP alone is the version of this control that does nothing: a
 * botnet has as many addresses as it wants, and each one gets a fresh budget to
 * guess your password with.
 *
 * Limiting by email alone is worse in a different way — anyone can lock any
 * user out of their own account by failing to sign in as them five times, which
 * turns a security control into a denial-of-service tool aimed at your
 * customers.
 *
 * So there are two, counted separately:
 *
 *   email + IP   strict. This is one person guessing one password from one
 *                place, which is the attack that actually works.
 *   IP           loose, and much larger. This catches one source spraying
 *                thousands of addresses, and it cannot be used to lock out a
 *                real user because it is not keyed on them.
 */
class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:filter', 'max:255'],
            'password' => ['required', 'string'],
            'remember' => ['boolean'],
            'cf-turnstile-response' => [new Turnstile],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            // Normalised once, here, so the credential lookup, the rate-limiter
            // key and the audit row all agree about what was typed.
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    /**
     * Attempt to sign in, or fail with a message that gives nothing away.
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        if (! Auth::attempt($this->credentials(), $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey(), $this->decaySeconds());
            RateLimiter::hit($this->ipThrottleKey(), 900);

            AuthEventRecorder::record(
                AuthEventRecorder::LOGIN_FAILED,
                email: $this->string('email')->value(),
                method: 'password',
            );

            // One message for "no such address" and for "wrong password", on
            // purpose. Telling them apart hands an attacker a free way to
            // enumerate which of a million leaked addresses have accounts here.
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * @return array<string, mixed>
     */
    protected function credentials(): array
    {
        return [
            'email' => $this->string('email')->value(),
            'password' => $this->string('password')->value(),
            // A deactivated login stops working immediately rather than at the
            // end of its session. Checked in the credential lookup rather than
            // after it, so a suspended member of staff never gets as far as
            // having a session to invalidate.
            'is_active' => true,
        ];
    }

    protected function ensureIsNotRateLimited(): void
    {
        $tooManyForPair = RateLimiter::tooManyAttempts($this->throttleKey(), $this->maxAttempts());
        $tooManyForIp = RateLimiter::tooManyAttempts($this->ipThrottleKey(), $this->maxAttempts() * 20);

        if (! $tooManyForPair && ! $tooManyForIp) {
            return;
        }

        Event::dispatch(new Lockout($this));

        AuthEventRecorder::record(
            AuthEventRecorder::LOCKED_OUT,
            email: $this->string('email')->value(),
            context: ['reason' => $tooManyForIp ? 'ip' : 'email'],
        );

        $seconds = RateLimiter::availableIn($tooManyForIp ? $this->ipThrottleKey() : $this->throttleKey());

        throw ValidationException::withMessages([
            'email' => __('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => (int) ceil($seconds / 60),
            ]),
        ]);
    }

    public function throttleKey(): string
    {
        return 'login:'.Str::transliterate($this->string('email')->value()).'|'.$this->ip();
    }

    public function ipThrottleKey(): string
    {
        return 'login-ip:'.$this->ip();
    }

    protected function maxAttempts(): int
    {
        return (int) config('prism.auth.login_attempts', 5);
    }

    protected function decaySeconds(): int
    {
        return (int) config('prism.auth.login_decay_minutes', 1) * 60;
    }
}
