<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Contracts;

use App\Domain\Integrations\Models\Integration;

/**
 * A platform that can be asked for one record by its own id.
 *
 * ── Why a push needs to read before it writes ────────────────────────────────
 *
 * Because an edit is a difference, and a difference needs both sides. Changing
 * the items on an order is not one operation but three — some lines updated,
 * some added, some taken off — and only the first two can be worked out from
 * what we hold. A line the shop still has and we no longer do leaves no trace
 * here to compare against: the row was deleted, and its id went with it.
 *
 * Writing without looking would therefore mean removals silently never happen.
 * An order edited here to drop an item would keep it in the shop, the totals
 * would disagree for ever, and nothing on either side would say why.
 *
 * Separate from PullsRecords because reading a page of a listing and reading
 * one known record are different questions, and a platform can be able to
 * answer the second without being able to answer the first.
 */
interface ReadsRecord
{
    /**
     * One record, exactly as the platform holds it now.
     *
     * Null for "could not be read" — the shop is unreachable, the id is not
     * known to it, the credentials do not allow it. Never an empty array, which
     * a caller would be entitled to read as "the record exists and is empty"
     * and act on.
     *
     * @param  string  $entity  'order' | 'product' | 'customer'
     * @return array<string, mixed>|null
     */
    public function fetchOne(Integration $integration, string $entity, string $externalId): ?array;
}
