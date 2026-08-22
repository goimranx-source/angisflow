<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Changes that never reached their shop, swept up every few minutes.
 *
 * ── Why this runs on a timer rather than on demand ───────────────────────────
 *
 * Because the failure it repairs is the absence of an attempt, and an absence
 * raises nothing. A queue with no worker, a job lost to a restart, a process
 * killed between the save and the dispatch — none of them leave anything behind
 * that would prompt a retry. Without something looking, the change simply stays
 * owed until a person notices the shop disagreeing, which is how this
 * application lost pushes for days at a time.
 *
 * withoutOverlapping because a slow shop must not have two sweeps queueing the
 * same orders on top of each other.
 */
Schedule::command('integrations:retry-pushes')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();
