<?php

declare(strict_types=1);

namespace App\Http\Api\V1;

use App\Http\Api\Endpoint;
use App\Support\BootPayload;
use App\Support\Modules;
use Illuminate\Http\JsonResponse;

/**
 * Who is signed in, whose books, and the menu.
 *
 * The same payload the HTML document already carried — see SpaController — so
 * the client never has to call this to draw its first frame. It calls it to
 * *revalidate*: after signing in, after switching business, or when a tab that
 * has been open since yesterday comes back to life.
 *
 * Deliberately reachable while signed out. A guest gets `auth: null` and a 200,
 * not a 401, because "am I still signed in" is a question whose answer is
 * sometimes no and that is not an error.
 */
class BootstrapEndpoint extends Endpoint
{
    public function show(): JsonResponse
    {
        return response()->json(BootPayload::build())
            // Never cached. This is the answer to "who am I", and a stale one
            // served from a browser cache after signing out is the worst
            // possible thing to be wrong about.
            ->header('Cache-Control', 'no-store, private');
    }

    /**
     * The module catalogue — what the tool covers and what it intends to.
     *
     * Static for the life of a deploy, identical for everybody, and read by the
     * roadmap and the "not built yet" screens.
     *
     * Cached for a day, which is only safe because the client puts the
     * catalogue's version in the URL: change the catalogue and the URL changes,
     * so a stale copy can never be served. A bare max-age here meant a release
     * that shipped a module kept pointing at its coming-soon page in every
     * browser that had already asked.
     */
    public function modules(): JsonResponse
    {
        return response()->json([
            'version' => Modules::version(),
            'data' => array_values(Modules::planned()),
        ])->header('Cache-Control', 'private, max-age=86400, immutable');
    }
}
