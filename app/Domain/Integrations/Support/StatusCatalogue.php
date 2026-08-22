<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Support;

/**
 * The statuses each platform is documented to send.
 *
 * Half of what fills the left-hand select on the mapping screen; the other half
 * is whatever a sync has actually observed. Both are needed, and for opposite
 * reasons: a brand-new WooCommerce connection has observed nothing yet and would
 * otherwise offer an empty list, while a bespoke site has no documented list at
 * all and lives entirely on what it has been seen to send.
 *
 * Custom statuses are the norm rather than the exception even on the named
 * platforms — WooCommerce plugins register their own freely, and a shop running
 * a fulfilment plugin will be sending three words that are not on any list here.
 * Those arrive through observation.
 */
final class StatusCatalogue
{
    /**
     * @return list<string>
     */
    public static function known(string $provider, string $entity = 'order'): array
    {
        if ($entity !== 'order') {
            return [];
        }

        return match (strtolower($provider)) {
            'woocommerce' => [
                'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed',
            ],

            // The order-level word only. Shopify's payment and dispatch live in
            // their own fields and are read directly, not mapped — they are a
            // fixed vocabulary rather than something a shop owner can rename.
            'shopify' => ['open', 'closed', 'cancelled'],

            'webflow' => ['unfulfilled', 'fulfilled', 'disputed', 'dispute-lost', 'refunded'],

            // Nothing is documented about somebody's own site, so nothing is
            // claimed. Its list is built from what it actually sends.
            default => [],
        };
    }
}
