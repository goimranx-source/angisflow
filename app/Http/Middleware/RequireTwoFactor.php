<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Identity\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Holds somebody at the door until they have proved the second factor.
 *
 * ── The session key, and why it carries a timestamp ──────────────────────────
 *
 * A boolean would mean "passed 2FA at some point in this session", and a
 * session lives for days. What is wanted is "passed recently", so the key holds
 * when it happened and this checks the age. That turns a stolen session cookie
 * from permanent access into access with an expiry.
 *
 * The key is namespaced by user id so that signing in as somebody else on the
 * same browser cannot inherit the previous person's cleared challenge.
 */
class RequireTwoFactor
{
    public const SESSION_KEY = 'auth.two_factor_passed_at';

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user === null || ! $user->hasTwoFactorEnabled()) {
            return $next($request);
        }

        if (self::hasPassed($request, $user)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Two-factor authentication is required.'], 423);
        }

        // Where they were going, so finishing the challenge lands them there
        // rather than on a dashboard they did not ask for.
        $request->session()->put('url.intended', $request->fullUrl());

        return redirect()->route('two-factor.challenge');
    }

    public static function hasPassed(Request $request, User $user): bool
    {
        $passedAt = $request->session()->get(self::keyFor($user));

        if (! is_int($passedAt)) {
            return false;
        }

        $lifetime = (int) config('prism.auth.two_factor_lifetime_minutes') * 60;

        return (time() - $passedAt) < $lifetime;
    }

    public static function markPassed(Request $request, User $user): void
    {
        $request->session()->put(self::keyFor($user), time());
    }

    public static function forget(Request $request, User $user): void
    {
        $request->session()->forget(self::keyFor($user));
    }

    private static function keyFor(User $user): string
    {
        return self::SESSION_KEY.':'.$user->getKey();
    }
}
