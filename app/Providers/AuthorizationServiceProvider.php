<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Identity\Models\User;
use App\Support\Capabilities;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the capability catalogue into Laravel's own authorisation.
 *
 * Registering each capability as a gate means `can('orders.create')` in React,
 * `$this->authorize(...)` in a controller and `->middleware('can:...')` on a
 * route all read from one list. One catalogue, three ways of asking, and no way
 * for them to disagree.
 *
 * The gates themselves are free: each closure calls hasCapability(), which is
 * an array lookup against a set resolved once per request.
 */
class AuthorizationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        foreach (Capabilities::slugs() as $capability) {
            Gate::define(
                $capability,
                static fn (User $user) => $user->hasCapability($capability),
            );
        }

        // An owner is never blocked by a capability that does not exist yet — a
        // typo in a route name should not lock somebody out of their own books
        // while it is being found.
        Gate::before(static fn (User $user) => $user->ownsAccount() ? true : null);
    }
}
