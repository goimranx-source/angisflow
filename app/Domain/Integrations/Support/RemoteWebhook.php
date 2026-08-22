<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Support;

/**
 * One webhook as it exists on the shop, in words that are not the shop's.
 *
 * WooCommerce says `status: 'active' | 'paused' | 'disabled'`; Shopify has no
 * status at all and simply deletes what it gives up on. Normalising to a
 * boolean here means the reconciler is written once against a concept both
 * platforms have, rather than twice against the vocabularies they don't share.
 */
final readonly class RemoteWebhook
{
    public function __construct(
        public string $id,
        public string $topic,
        public string $url,
        public bool $active,
        /** What the shop called it, for a screen that lists them. */
        public string $name = '',
        /** How many deliveries in a row have failed, where the shop counts. */
        public int $failures = 0,
    ) {}

    /**
     * Is this one of ours?
     *
     * Matched on the token rather than the whole URL, and that distinction is
     * the entire point. The token is permanent and unique to this connection;
     * the host in front of it is not — it changes when the application moves,
     * and during development it changes whenever a tunnel restarts. Comparing
     * whole URLs would look at a webhook of ours pointing at yesterday's
     * address and conclude it belonged to somebody else, then create a second
     * one beside it, every time.
     */
    public function belongsTo(string $token): bool
    {
        return $token !== '' && str_contains($this->url, $token);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'topic' => $this->topic,
            'url' => $this->url,
            'active' => $this->active,
            'name' => $this->name,
            'failures' => $this->failures,
        ];
    }
}
