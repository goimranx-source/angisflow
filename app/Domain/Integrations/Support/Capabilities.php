<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Support;

/**
 * What one platform can actually be asked to do.
 *
 * ── Why this is asked rather than assumed ────────────────────────────────────
 *
 * Platforms differ in ways that matter to a settings screen. A hosted shop will
 * hand over its orders and take a product back; a bespoke site may only be able
 * to post to us and never be polled; some will sign their webhooks and some
 * have no webhooks at all.
 *
 * Offering all of it regardless and letting the failure happen at sync time is
 * how a product teaches people that its switches are decorative. So the screen
 * asks first, and only draws the switches that mean something for the shop in
 * front of it.
 */
final readonly class Capabilities
{
    /**
     * @param  list<string>  $entities  what can be exchanged: 'order', 'product', 'customer'
     */
    public function __construct(
        public array $entities = [],
        /** Can we read from it — poll, backfill, resync? */
        public bool $canPull = false,
        /** Can we write to it — push a price change back? */
        public bool $canPush = false,
        /** Does it call us when something changes? */
        public bool $supportsWebhooks = false,
        /** Can an inbound call be proven genuine, or only hoped for? */
        public bool $verifiesWebhooks = false,
        /**
         * Can it answer "what changed since X"?
         *
         * Without it every sync is a full read of the whole catalogue, which is
         * the difference between a minute and an afternoon on a shop of any
         * size — and worth knowing before somebody schedules it hourly.
         */
        public bool $supportsIncremental = false,
    ) {}

    public function handles(string $entity): bool
    {
        return in_array($entity, $this->entities, true);
    }

    /**
     * Two-way sync is only honest when both halves are possible. Asked as one
     * question because the bidirectional flag on a connection is one switch,
     * and it should not be offerable when half of it would silently do nothing.
     */
    public function canSyncBothWays(): bool
    {
        return $this->canPull && $this->canPush;
    }

    /** @return array<string, mixed> for the settings screen */
    public function toArray(): array
    {
        return [
            'entities' => $this->entities,
            'can_pull' => $this->canPull,
            'can_push' => $this->canPush,
            'supports_webhooks' => $this->supportsWebhooks,
            'verifies_webhooks' => $this->verifiesWebhooks,
            'supports_incremental' => $this->supportsIncremental,
            'can_sync_both_ways' => $this->canSyncBothWays(),
        ];
    }
}
