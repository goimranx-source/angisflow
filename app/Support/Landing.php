<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\Identity\Models\User;

/**
 * Where signing in should land somebody.
 *
 * A path the client navigates to, not a redirect the server performs — there
 * are no page routes left to redirect to. The dashboard for an owner; for a
 * member of staff, the first screen their role can actually open.
 *
 * That distinction is not cosmetic. Landing a new sales rep on a dashboard
 * their role cannot read greets them with a refusal on the one screen they did
 * not choose to visit, and it reads as the tool being broken.
 */
final class Landing
{
    public static function forUser(?User $user): string
    {
        if ($user === null) {
            return '/login';
        }

        if ($user->ownsAccount()) {
            return '/home';
        }

        return $user->role()->getResults()?->landingPath() ?? '/profile';
    }
}
