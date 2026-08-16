<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes a repeated write harmless.
 *
 * ── The problem this exists for ──────────────────────────────────────────────
 *
 * A request can be sent more than once for reasons that have nothing to do with
 * the user: a load balancer retries a request it thinks timed out, a phone
 * switches from wifi to mobile mid-post, a user on a slow connection presses
 * the button again because nothing appeared to happen. Every one of those turns
 * one order into two, or one payment into two — and the second is invisible
 * until somebody reconciles the books.
 *
 * Disabling the button is not a fix. It stops the user pressing twice; it does
 * nothing about the network doing it for them.
 *
 * ── How it works ─────────────────────────────────────────────────────────────
 *
 * The client generates a key for each *intent* — one key per "create this
 * order", reused across every retry of it. The first request to arrive with a
 * given key does the work and its response is remembered; every later request
 * with the same key gets that same response back without the work running
 * again.
 *
 * A request that is still in flight gets a 409 rather than being allowed to run
 * beside its own twin, which is the case a naive "have we seen this key"
 * check misses entirely: both arrive, neither has finished, both proceed.
 *
 * Keys are scoped per user, so one subscriber cannot read another's response by
 * guessing a key, and they expire after a day — long enough to cover any
 * retry, short enough not to be a permanent store.
 */
class Idempotency
{
    private const HEADER = 'Idempotency-Key';

    private const TTL = 86400;

    /** How long a request may be in flight before its lock is considered stale. */
    private const IN_FLIGHT_TTL = 60;

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header(self::HEADER);

        // Optional by design. Reads never need it, and a write that has not
        // asked for the guarantee should not be refused for lack of it — that
        // would make every client change at once.
        if (! is_string($key) || $key === '' || ! $request->isMethod('POST')) {
            return $next($request);
        }

        if (strlen($key) > 128) {
            return response()->json(['message' => 'That idempotency key is too long.'], 400);
        }

        $cacheKey = $this->cacheKeyFor($request, $key);

        $stored = Cache::get($cacheKey);

        if (is_array($stored)) {
            if (($stored['state'] ?? null) === 'in_flight') {
                // The twin is still running. Told to wait rather than allowed
                // to duplicate the work.
                return response()->json([
                    'message' => 'That request is already being processed.',
                ], 409)->header('Retry-After', '2');
            }

            return response()
                ->json($stored['body'], $stored['status'])
                ->header('Idempotent-Replay', 'true');
        }

        // Claimed atomically. `add` only succeeds if nothing is there, so two
        // requests racing here cannot both believe they are the first.
        if (! Cache::add($cacheKey, ['state' => 'in_flight'], self::IN_FLIGHT_TTL)) {
            return response()->json([
                'message' => 'That request is already being processed.',
            ], 409)->header('Retry-After', '2');
        }

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            // The work did not complete, so the key must not be held — a retry
            // is exactly the right thing for the client to do next.
            Cache::forget($cacheKey);

            throw $e;
        }

        $status = $response->getStatusCode();

        // Only successful writes are remembered. Replaying a validation error
        // would mean a corrected resubmission got the original complaint back,
        // and replaying a server error would make a transient fault permanent.
        if ($status >= 200 && $status < 300) {
            Cache::put($cacheKey, [
                'state' => 'done',
                'status' => $status,
                'body' => json_decode($response->getContent() ?: 'null', true),
            ], self::TTL);
        } else {
            Cache::forget($cacheKey);
        }

        return $response;
    }

    private function cacheKeyFor(Request $request, string $key): string
    {
        $owner = $request->user()?->getKey() ?? 'guest:'.$request->ip();

        // The path is part of the key so one client key reused across two
        // different endpoints cannot return the wrong endpoint's response.
        return 'idem:'.$owner.':'.sha1($request->path().'|'.$key);
    }
}
