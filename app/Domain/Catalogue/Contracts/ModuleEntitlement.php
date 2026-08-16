<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Contracts;

use App\Domain\Tenancy\Models\Workspace;

/**
 * What the plan allows — the billing half of the answer.
 *
 * ── Why this is an interface with a permissive default ───────────────────────
 *
 * Entitlement genuinely belongs to billing: it resolves from the plan, plus
 * any add-ons bought individually, plus any grant an operator has made for a
 * negotiated deal. None of that exists yet.
 *
 * The temptation is to leave entitlement out until it does, and add the checks
 * later. That is how it ends up half-applied — a dozen call sites that each
 * decided for themselves, and no single place that knows the rule. So the seam
 * is cut now and filled with a default that entitles everything: every caller
 * is already asking the right question, and the day billing can answer it
 * properly, one binding changes and every screen obeys.
 */
interface ModuleEntitlement
{
    /**
     * Module keys this workspace's plan permits.
     *
     * Core modules are not the concern of this method — they are never
     * metered, never withheld, and the resolver grants them regardless of
     * what is returned here.
     *
     * @return list<string>|null  null means "no restriction"
     */
    public function permittedKeys(Workspace $workspace): ?array;
}
