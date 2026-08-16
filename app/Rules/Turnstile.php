<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Cloudflare's bot check, applied to the forms an attacker can hammer.
 *
 * Inert unless both keys are configured, which is what lets it sit permanently
 * on the auth rules without affecting a development machine or an install that
 * has never turned it on.
 *
 * ── On failing open ──────────────────────────────────────────────────────────
 *
 * If Cloudflare cannot be reached, this passes. That is a deliberate trade and
 * worth stating plainly: a bot check that fails closed means an outage at a
 * third party locks every one of your subscribers out of their own business,
 * and that is a far larger incident than the handful of automated sign-up
 * attempts that get through in the meantime. The rate limiters are still in
 * front of every one of these endpoints and they do not depend on anybody else
 * being up.
 */
class Turnstile implements ValidationRule
{
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $secret = config('prism.turnstile.secret_key');

        if (! $secret || ! config('prism.turnstile.site_key')) {
            return;
        }

        if (! is_string($value) || $value === '') {
            $fail('Please complete the “I am human” check.');

            return;
        }

        try {
            $response = Http::asForm()
                ->timeout(5)
                ->retry(1, 200)
                ->post(self::VERIFY_URL, [
                    'secret' => $secret,
                    'response' => $value,
                    'remoteip' => request()->ip(),
                ]);
        } catch (Throwable $e) {
            report($e);

            return;
        }

        if (! $response->successful()) {
            return;
        }

        if ($response->json('success') !== true) {
            $fail('That check did not pass. Please try again.');
        }
    }
}
