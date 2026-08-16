<?php

declare(strict_types=1);

namespace App\Domain\Catalogue;

use App\Domain\Catalogue\Contracts\ModuleEntitlement;
use App\Domain\Tenancy\Models\Workspace;

/**
 * Entitles everything, until billing can say otherwise.
 *
 * The honest placeholder for the plan/add-on/grant resolver that task 37
 * builds. It returns null rather than a list of every key, so the resolver
 * takes its "no restriction" path and does no filtering work at all — the
 * cost of the seam before it is filled is one method call returning null.
 */
final class UnrestrictedEntitlement implements ModuleEntitlement
{
    public function permittedKeys(Workspace $workspace): ?array
    {
        return null;
    }
}
