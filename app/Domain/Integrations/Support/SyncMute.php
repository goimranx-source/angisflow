<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Support;

/**
 * A switch that stops a sync answering itself.
 *
 * ── The circle this prevents ─────────────────────────────────────────────────
 *
 * Bringing records in writes them to our tables. Writing to our tables is what
 * tells the observers to send changes out. So without this, importing a product
 * from a shop immediately pushes that same product back to the shop it came
 * from — which the shop then reports as a change, which we import again.
 *
 * The fingerprint on a link catches most of that after the fact, one lap later.
 * This stops the lap being run at all, which is cheaper and clearer.
 *
 * ── Why it is static ─────────────────────────────────────────────────────────
 *
 * Because the observers fire deep inside Eloquent, with no reference to
 * whatever asked for the save. Something process-wide is the only thing both
 * ends can see. Held as a counter rather than a flag so that nested imports
 * release it once, at the end, rather than the inner one unmuting the outer.
 */
final class SyncMute
{
    private static int $depth = 0;

    /** Run something without any of its saves being pushed back out. */
    public static function while(callable $work): mixed
    {
        self::$depth++;

        try {
            return $work();
        } finally {
            self::$depth--;
        }
    }

    /** Is a sync currently writing? */
    public static function active(): bool
    {
        return self::$depth > 0;
    }
}
