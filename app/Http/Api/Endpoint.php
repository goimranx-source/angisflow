<?php

declare(strict_types=1);

namespace App\Http\Api;

use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;

/**
 * The base every API endpoint extends.
 *
 * ── Why "endpoint" and not "controller" ──────────────────────────────────────
 *
 * Because there are no controllers in the MVC sense left in this application.
 * The server renders exactly one HTML document — the shell — and everything
 * else is a JSON endpoint. Calling these controllers would suggest there is a
 * view somewhere they belong to, and there is not.
 *
 * The whole of app/Http/Api is the surface the client talks to. One directory,
 * versioned, so "what can the front end call" is answered by listing a folder
 * rather than by reading a router.
 */
abstract class Endpoint
{
    use DispatchesJobs, ValidatesRequests;
}
