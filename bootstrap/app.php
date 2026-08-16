<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureAccountIsUsable;
use App\Http\Middleware\EnsureGuest;
use App\Http\Middleware\EnsureOperator;
use App\Http\Middleware\Idempotency;
use App\Http\Middleware\RequireTwoFactor;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            /*
             * The API.
             *
             * ── Why it is on the web middleware group ────────────────────────
             *
             * Laravel's 'api' group is stateless: no session, so every request
             * would have to carry a bearer token. That means minting a token in
             * the browser and storing it somewhere JavaScript can read — which
             * is somewhere an XSS can read — and refreshing it before it
             * expires. All of that to authenticate a request the browser is
             * already sending a session cookie with.
             *
             * Same-origin front ends need none of it. On the web group the
             * session cookie authenticates, the CSRF token protects, and the
             * cookie stays HttpOnly where no script can touch it.
             *
             * A stateless token group for third parties — a storefront, a
             * courier, somebody's own script — will sit beside this. That is a
             * genuinely different audience, and it should not dictate how our
             * own screens talk to our own server.
             */
            Route::middleware(['web', Idempotency::class])
                ->prefix('api/v1')
                ->as('api.v1.')
                ->group(base_path('routes/api.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: [
            // Read by an inline script in <head> before the bundle loads, so
            // the sidebar is drawn at its stored width on the very first paint.
            // An encrypted cookie cannot be read there, and the result is a
            // rail that flashes open and snaps shut on every cold load.
            'prism_rail',
            // Same reasoning, for light/dark: read before the first paint so
            // the page never flashes light before jumping to dark.
            'prism_theme',
        ]);

        $middleware->validateCsrfTokens(except: [
            // Signed by the sender and verified in the endpoint. A storefront
            // has no session here and therefore no token to present.
            'api/v1/webhooks/*',
        ]);

        // A load balancer, a CDN, ngrok. Without this Laravel reads the proxy's
        // address as the client's — which breaks rate limiting by IP, the audit
        // trail, and the scheme of every generated URL.
        $middleware->trustProxies(at: '*');

        $middleware->web(append: [
            SecurityHeaders::class,
        ]);

        $middleware->alias([
            'tenant' => ResolveTenant::class,
            // Resolves a tenant when there is one and shrugs when there is not
            // — for /bootstrap, which answers for guests as well.
            'tenant.optional' => ResolveTenant::class,
            'account.usable' => EnsureAccountIsUsable::class,
            'two-factor' => RequireTwoFactor::class,
            // Replaces Laravel's, which redirects. See EnsureGuest.
            'guest' => EnsureGuest::class,
            'operator' => EnsureOperator::class,
            // Public API middleware for external integrations
            'api.auth' => \App\Http\Middleware\PublicApiAuth::class,
            'api.rate-limit' => \App\Http\Middleware\PublicApiRateLimit::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
         * Everything the client calls is JSON, so everything that goes wrong
         * has to come back as JSON too.
         *
         * Without this a 500 returns Laravel's HTML error page, the client's
         * JSON parser chokes on "<!DOCTYPE", and the error the user is shown
         * has nothing to do with the error that happened.
         */
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson()
        );

        // A session that expired while a tab sat open. 401 rather than a
        // redirect to a login page the fetch would follow and then fail to
        // parse — the client sees the status and sends the user to sign in.
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'Your session has ended. Sign in again.',
                ], 401);
            }

            return null;
        });

        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*') || app()->environment('local')) {
                return null;
            }

            $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;

            // Anything below 500 has already been shaped by Laravel and says
            // something useful. A 500 has not, and its message is an internal
            // detail — a stack trace or a query — that no client should see.
            if ($status < 500) {
                return null;
            }

            report($e);

            return response()->json([
                'message' => 'Something went wrong at our end. It has been logged.',
            ], 500);
        });
    })->create();
