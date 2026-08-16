<?php

declare(strict_types=1);

namespace App\Http\Api\V1;

use App\Http\Api\Endpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Whether this particular server should be sent traffic.
 *
 * ── Liveness and readiness are different questions ───────────────────────────
 *
 * `/up` — Laravel's own — answers "is PHP running". A load balancer that only
 * checks that will happily keep sending requests to a node whose database
 * connection has died, and every one of those requests fails. From the outside
 * the site is down; from the monitoring it is perfectly healthy.
 *
 * This answers the question that actually decides routing: can this node serve
 * a real request right now. If the database or the cache is unreachable it
 * returns 503, the balancer takes the node out of rotation, and the remaining
 * healthy nodes carry on — which is exactly the "if a server is busy, use
 * another one" behaviour, done properly. The node is not killed; it is drained
 * until it recovers, then put back.
 *
 * ── Why the checks are cheap and time-boxed ──────────────────────────────────
 *
 * A health check runs every couple of seconds against every node for ever. A
 * check that queries anything real would put a permanent load on the database
 * proportional to how many servers you own — so it does a `SELECT 1` and a
 * cache round trip, nothing more. It also has to be *fast to fail*: a check
 * that hangs for thirty seconds waiting on a dead database keeps a broken node
 * in rotation for thirty seconds.
 */
class HealthEndpoint extends Endpoint
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->check(fn () => DB::select('select 1')),
            'cache' => $this->check(function () {
                $key = 'health:'.bin2hex(random_bytes(4));
                Cache::put($key, 1, 5);
                Cache::forget($key);
            }),
        ];

        $healthy = ! in_array(false, $checks, true);

        return response()->json([
            'status' => $healthy ? 'ready' : 'degraded',
            'checks' => $checks,
            // Which node answered. Without it, "one server in the pool is
            // misbehaving" is a report nobody can act on.
            'node' => gethostname() ?: 'unknown',
            'at' => now()->toIso8601String(),
        ], $healthy ? 200 : 503)->header('Cache-Control', 'no-store');
    }

    private function check(callable $probe): bool
    {
        try {
            $probe();

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }
}
