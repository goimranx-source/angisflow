<?php

declare(strict_types=1);

namespace App\Domain\Integrations;

use App\Domain\Integrations\Models\Integration;
use App\Models\Storefront;
use Illuminate\Support\Facades\Log;

/**
 * Auto-detect and set storefront currency from the connected platform.
 *
 * ── Why this exists ──────────────────────────────────────────────────────────
 *
 * When a user connects a WooCommerce or Shopify store, we can fetch the store's
 * configured currency directly from its API rather than asking the user to
 * manually configure it. This:
 * 1. Reduces manual setup
 * 2. Ensures accuracy (no typos or wrong currency selection)
 * 3. Respects the actual store settings
 *
 * ── When it runs ─────────────────────────────────────────────────────────────
 *
 * - When an integration is first connected
 * - When a storefront is assigned to an integration that has no currency
 * - Can be manually triggered via command for existing storefronts
 *
 * ── What it does NOT do ──────────────────────────────────────────────────────
 *
 * - Does NOT override manually set currencies
 * - Does NOT change currency if already configured
 * - Does NOT run on every sync (only when needed)
 */
class StorefrontCurrencyDetector
{
    public function __construct(private readonly PlatformRegistry $registry) {}

    /**
     * Detect and set currency for a storefront from its integration.
     *
     * This is called when:
     * 1. An integration is first connected
     * 2. A storefront is assigned to an integration
     *
     * It will ONLY set the currency if the storefront has NO currency configured.
     * Manual currency configurations are NEVER overridden.
     *
     * @return array{detected: bool, currency: string|null, set: bool, message: string}
     */
    public function detectAndSet(Integration $integration): array
    {
        // No storefront attached
        if ($integration->storefront_id === null) {
            return [
                'detected' => false,
                'currency' => null,
                'set' => false,
                'message' => 'No storefront attached to this integration.',
            ];
        }

        $storefront = Storefront::withoutGlobalScopes()
            ->whereKey($integration->storefront_id)
            ->first();

        if (! $storefront) {
            return [
                'detected' => false,
                'currency' => null,
                'set' => false,
                'message' => 'Storefront not found.',
            ];
        }

        // Storefront already has a currency configured - respect it
        if ($storefront->currency !== null && trim($storefront->currency) !== '') {
            return [
                'detected' => false,
                'currency' => mb_strtoupper($storefront->currency),
                'set' => false,
                'message' => 'Storefront already has currency configured. Manual configuration is preserved.',
            ];
        }

        // Try to fetch currency from the platform
        $currency = $this->fetchPlatformCurrency($integration);

        if ($currency === null) {
            return [
                'detected' => false,
                'currency' => null,
                'set' => false,
                'message' => 'Could not auto-detect currency from platform API. Set manually in storefront settings.',
            ];
        }

        // Set the currency
        try {
            $storefront->currency = $currency;
            $storefront->save();

            Log::info('Auto-detected and set storefront currency from platform', [
                'integration_id' => $integration->id,
                'integration_name' => $integration->name,
                'storefront_id' => $storefront->id,
                'storefront_name' => $storefront->name,
                'currency' => $currency,
                'provider' => $integration->provider,
            ]);

            return [
                'detected' => true,
                'currency' => $currency,
                'set' => true,
                'message' => "Auto-detected currency {$currency} from {$integration->provider}. All orders will display in this currency.",
            ];
        } catch (\Throwable $e) {
            Log::error('Failed to set auto-detected storefront currency', [
                'integration_id' => $integration->id,
                'storefront_id' => $storefront->id,
                'currency' => $currency,
                'error' => $e->getMessage(),
            ]);

            return [
                'detected' => true,
                'currency' => $currency,
                'set' => false,
                'message' => 'Detected currency but failed to save: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Fetch the currency from the platform's API.
     */
    private function fetchPlatformCurrency(Integration $integration): ?string
    {
        try {
            $driver = $this->registry->for($integration);

            // Check if driver has a fetchStoreCurrency method
            if (! method_exists($driver, 'fetchStoreCurrency')) {
                return null;
            }

            return $driver->fetchStoreCurrency($integration);
        } catch (\Throwable $e) {
            Log::warning('Failed to fetch store currency from platform', [
                'integration_id' => $integration->id,
                'provider' => $integration->provider,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Check if currency detection is supported for this integration.
     */
    public function supported(Integration $integration): bool
    {
        $driver = $this->registry->for($integration);

        return method_exists($driver, 'fetchStoreCurrency');
    }
}
