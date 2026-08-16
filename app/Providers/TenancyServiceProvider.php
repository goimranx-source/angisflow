<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\ServiceProvider;

/**
 * Binds the tenant context for the life of one request or one job.
 *
 * `scoped` rather than `singleton`, and the difference is the whole point under
 * Octane or a long-lived queue worker: a singleton is created once per process
 * and would carry one subscriber's context into the next job that runs on the
 * same worker. Scoped bindings are flushed between requests, so the worst case
 * is a job that has no tenant — which fails loudly — rather than a job that has
 * the wrong one, which does not fail at all.
 */
class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(TenantContext::class, fn () => new TenantContext);
    }
}
