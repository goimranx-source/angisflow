<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Who is allowed to ask for how much.
 *
 * ── The noisy neighbour, and why per-IP limits do not solve it ───────────────
 *
 * On a shared platform the thing that takes everybody down is rarely an
 * attacker. It is one subscriber who wrote a script, or opened a report over
 * three years of data on a loop, or connected an integration that polls every
 * second. Their traffic is legitimate, authenticated and from an ordinary
 * number of addresses — so an IP-based limit sees nothing wrong right up until
 * every other subscriber's screens are slow.
 *
 * The limit that matters is therefore per *account*: one subscriber gets a
 * generous share and cannot consume anybody else's. It is the difference
 * between one customer having a bad afternoon and every customer having one.
 *
 * ── The numbers ──────────────────────────────────────────────────────────────
 *
 * Deliberately high. A working screen in this application makes a handful of
 * calls, and a busy user perhaps a hundred a minute; the ceiling is set an
 * order of magnitude above that, so it never touches real use and only bites
 * something that has genuinely run away. A limit tight enough to annoy people
 * is a limit that gets raised in a panic and then forgotten.
 */
class RateLimitServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // The general API budget. Per account where there is one, per address
        // where there is not — a signed-out request has no account to charge.
        RateLimiter::for('api', function (Request $request) {
            $accountId = app(TenantContext::class)->accountId();

            if ($accountId !== null) {
                return [
                    Limit::perMinute(1200)->by("account:{$accountId}"),
                    // A second, tighter limit per user inside that budget, so
                    // one runaway browser tab cannot spend the whole account's
                    // allowance and lock out their colleagues.
                    Limit::perMinute(600)->by('user:'.$request->user()?->getKey()),
                ];
            }

            return Limit::perMinute(60)->by($request->ip());
        });

        // Anything that can be submitted in bulk by somebody who is not signed
        // in. Much stricter, and keyed on the address because that is all we
        // know about them.
        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));

        // Endpoints that send mail. The limit here is not about our load, it is
        // about not letting somebody use our mail reputation to flood an inbox
        // that is not theirs.
        RateLimiter::for('mail', fn (Request $request) => [
            Limit::perMinute(3)->by($request->ip()),
            Limit::perHour(20)->by($request->ip()),
        ]);

        // Uploads. Capped per account rather than per address: the cost is
        // storage and bandwidth, and those are charged to the subscriber, not
        // to whoever happened to be sitting at the browser.
        RateLimiter::for('uploads', function (Request $request) {
            $accountId = app(TenantContext::class)->accountId();

            return Limit::perMinute(30)->by('uploads:'.($accountId ?? $request->ip()));
        });

        // Reports, exports, anything that reads a lot to answer one question.
        // Low on purpose: these are the queries that hurt, and nobody needs to
        // run six a minute.
        RateLimiter::for('heavy', function (Request $request) {
            $accountId = app(TenantContext::class)->accountId();

            return Limit::perMinute(20)->by('heavy:'.($accountId ?? $request->ip()));
        });
    }
}
