<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Billing\PlanEntitlement;
use App\Domain\Catalogue\Contracts\ModuleEntitlement;
use App\Domain\Identity\Models\User;
use App\Domain\Integrations\PlatformRegistry;
use App\Domain\Integrations\QueueHeartbeat;
use App\Models\Employee;
use App\Models\FleetVehicle;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /*
         * What a plan permits. Bound to a permissive default until billing
         * can answer it properly — see the ModuleEntitlement contract. When
         * plans, add-ons and operator grants land, this one line changes and
         * every screen that asks ModuleAccess starts obeying it.
         */
        // Task 37: real entitlement resolver. Resolves from the account's
        // active subscription → plan → features → module keys. Returns null
        // (unrestricted) during trial with no plan, preserving the trial UX.
        $this->app->bind(ModuleEntitlement::class, PlanEntitlement::class);

        /*
         * The platform driver lookup. A singleton because it caches the driver
         * it resolves for each key, and drivers are stateless — a settings page
         * listing eight connections would otherwise build eight identical
         * objects, and a fresh registry per call would cache nothing at all.
         */
        $this->app->singleton(PlatformRegistry::class);
    }

    public function boot(): void
    {
        $this->configureUrls();
        $this->configureModels();
        $this->configureMailLinks();
        $this->configureMorphMap();
        $this->watchTheQueue();
    }

    /**
     * Let the application know a worker is alive.
     *
     * `Looping` fires on every poll a worker makes, whether or not there is
     * anything to do — which is what makes it a liveness signal rather than a
     * throughput one. A worker sitting idle is still a worker, and work handed
     * to it will run.
     *
     * Anything that must happen whether or not the queue is being consumed can
     * then ask, and do the work itself when the answer is no. See
     * PushDispatcher.
     */
    private function watchTheQueue(): void
    {
        Event::listen(Looping::class, function (): void {
            app(QueueHeartbeat::class)->beat();
        });
    }

    /**
     * Every generated URL is https outside local.
     *
     * Behind a load balancer the application usually speaks plain HTTP while the
     * world speaks HTTPS. Laravel reads the scheme from the request, so without
     * this every signed link it mails — password resets, email verification —
     * comes out as http://, and either breaks on a strict-transport site or
     * gets rewritten by the proxy and fails its signature.
     */
    private function configureUrls(): void
    {
        if (! $this->app->environment('local')) {
            URL::forceScheme('https');
        }
    }

    private function configureModels(): void
    {
        /*
         * Accessing a relationship that was not loaded throws instead of
         * quietly running a query.
         *
         * This is the single most valuable line in the file at the scale this
         * is built for. An N+1 is invisible in development — fifty rows, fifty
         * fast queries, nobody notices — and catastrophic in production, where
         * the same code runs fifty thousand. Making it an exception means it is
         * found by the developer who wrote it, on the day they wrote it.
         *
         * Off in production, where an unexpected throw would be worse than a
         * slow page for the person unlucky enough to hit it.
         */
        Model::preventLazyLoading(! $this->app->isProduction());

        // Assigning something that is not a real column is almost always a
        // typo, and silently discarding it is how a save appears to work and
        // does nothing.
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());
    }

    /**
     * Links in mail point at the client, not at a server route.
     *
     * There are no page routes any more — the server renders one shell and
     * React does the routing — so Laravel's built-in notification, which calls
     * route('password.reset'), has nothing to resolve. Left alone it throws
     * while sending, which also leaks whether an address exists: the unknown
     * one returns a cheerful 200 and the real one returns a 500.
     *
     * The address travels in the query string because the reset form has to
     * submit it back with the token, and the token alone does not identify who
     * it belongs to.
     */
    private function configureMailLinks(): void
    {
        ResetPassword::createUrlUsing(
            fn (User $user, string $token) => url('/reset-password/'.$token.'?email='.urlencode($user->email)),
        );
    }

    /**
     * BookingResource::resource() is a MorphTo keyed on resource_type, but
     * that column is populated with plain vocabulary strings ('employee',
     * 'room', 'equipment', 'vehicle') rather than fully-qualified class
     * names — and without a morph map, Eloquent's default MorphTo resolver
     * treats the stored string *as* the class name, so `$resource->resource`
     * silently fails to resolve for every row.
     *
     * Only two of the four vocabulary values have a real backing model today:
     * an employee resource is a person on payroll (App\Models\Employee), and
     * a vehicle resource is a fleet vehicle (App\Models\FleetVehicle). 'room'
     * and 'equipment' are left unmapped on purpose — there is no Room or
     * Equipment model anywhere in the app, so mapping them to something would
     * be inventing a target that does not exist. A resource_type of 'room' or
     * 'equipment' is valid vocabulary for naming and scheduling purposes, it
     * simply has no polymorphic record of its own yet; the day one is built,
     * its entry belongs here.
     *
     * Not enforced: enforceMorphMap() throws for any stored type absent from
     * the map, which would turn every 'room'/'equipment' resource's ->resource
     * access into a hard error instead of the null it resolves to today.
     * That behaviour change belongs with whoever adds those models, not here.
     */
    private function configureMorphMap(): void
    {
        Relation::morphMap([
            'employee' => Employee::class,
            'vehicle' => FleetVehicle::class,
        ]);
    }
}
