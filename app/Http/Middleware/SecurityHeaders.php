<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The headers a browser needs to be told, on every response.
 *
 * ── On the content security policy ───────────────────────────────────────────
 *
 * There is no CDN in the allow-list, and that is the point. The first version
 * of this tool pulled Chart.js, an icon font and a webfont from three third
 * parties, which meant three DNS lookups and three TLS handshakes to hosts we
 * do not control before the first screen could finish painting — and a policy
 * that had to permit script from those origins, which is most of what a script
 * policy is for.
 *
 * Everything is bundled now, so the policy can be 'self' and actually mean it.
 *
 * 'unsafe-inline' survives in style-src only, because React writes inline
 * styles for anything animated and there is no way around that short of a
 * nonce on every style attribute. It is absent from script-src, which is the
 * one that matters: an injected <script> has nowhere to run.
 */
class SecurityHeaders
{
    /** @var array<string, string> */
    private array $headers = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'SAMEORIGIN',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), interest-cohort=()',
        'Cross-Origin-Opener-Policy' => 'same-origin',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        foreach ($this->headers as $key => $value) {
            $response->headers->set($key, $value);
        }

        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        $response->headers->set('Content-Security-Policy', $this->policy());

        return $response;
    }

    private function policy(): string
    {
        $directives = [
            "default-src 'self'",
            "script-src 'self'",
            "style-src 'self' 'unsafe-inline'",
            "font-src 'self' data:",
            /*
             * ── Pictures may come from the shops this business sells through ─
             *
             * A product's photograph is hosted by the shop that sells it —
             * vorosabajar.com, someone else's Shopify, a CDN in front of
             * either. This application stores the address rather than a second
             * copy of the file (see the migration that added products.
             * image_url), so an order line's thumbnail is a request to a host
             * that is not this one, and 'self' refused every one of them.
             *
             * Widened to https rather than to a list, because the list is not
             * knowable here: every business connects different shops, and each
             * of those can move its images to a different CDN without telling
             * anybody. A policy that has to be edited whenever a customer
             * changes hosting is a policy that gets switched off.
             *
             * What this gives up is narrow. An image cannot execute; the worst
             * a hostile one does is fail to load, or report to its own host
             * that somebody looked at it — which the shop's own site already
             * does, to the same host, for the same person. Scripts, styles,
             * frames and connections are all still 'self'.
             *
             * http is deliberately not allowed: a plain-text image on an https
             * page is a mixed-content warning and a downgrade, and any shop
             * worth syncing with serves its media over TLS.
             */
            "img-src 'self' data: blob: https:",
            "connect-src 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "object-src 'none'",
        ];

        // Vite's dev server serves the bundle over its own origin and opens a
        // websocket for hot reload. Relaxed in local only — shipping this to
        // production would quietly undo the whole policy.
        if (app()->environment('local')) {
            $directives[1] = "script-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173 http://localhost:5174 http://127.0.0.1:5173 http://127.0.0.1:5174";
            $directives[2] = "style-src 'self' 'unsafe-inline' http://localhost:5173 http://localhost:5174 http://127.0.0.1:5173 http://127.0.0.1:5174";
            $directives[5] = "connect-src 'self' ws://localhost:5173 ws://localhost:5174 http://localhost:5173 http://localhost:5174 ws://127.0.0.1:5173 ws://127.0.0.1:5174 http://127.0.0.1:5173 http://127.0.0.1:5174";
        }

        // The Turnstile widget is an iframe from Cloudflare, so it needs its
        // origin — but only when it is actually switched on.
        if (config('prism.turnstile.site_key')) {
            $directives[1] .= ' https://challenges.cloudflare.com';
            $directives[] = 'frame-src https://challenges.cloudflare.com';
        }

        return implode('; ', $directives);
    }
}
