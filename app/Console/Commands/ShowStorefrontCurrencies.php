<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Integrations\Models\Integration;
use App\Models\Storefront;
use Illuminate\Console\Command;

/**
 * Display currency configuration for all storefronts.
 *
 * Helps users understand how currency is configured across their storefronts
 * and which stores have auto-detected vs manually set currencies.
 */
class ShowStorefrontCurrencies extends Command
{
    protected $signature = 'storefronts:currencies {--set-missing : Auto-detect and set currencies for storefronts that have none}';
    protected $description = 'Show currency configuration for all storefronts';

    public function handle(): int
    {
        $storefronts = Storefront::withoutGlobalScopes()
            ->with(['account', 'business'])
            ->get();

        if ($storefronts->isEmpty()) {
            $this->warn('No storefronts found.');
            return self::SUCCESS;
        }

        $this->info("Storefront Currency Configuration");
        $this->line(str_repeat('=', 80));
        $this->newLine();

        foreach ($storefronts as $storefront) {
            $this->line("Storefront: {$storefront->name} (ID: {$storefront->id})");
            
            // Find connected integration
            $integration = Integration::withoutGlobalScopes()
                ->where('storefront_id', $storefront->id)
                ->first();

            if ($integration) {
                $this->line("  Connected to: {$integration->name} ({$integration->provider})");
            } else {
                $this->line("  Connected to: <fg=gray>None</>");
            }

            // Currency status
            if ($storefront->currency) {
                $this->line("  Currency: <fg=green>{$storefront->currency}</>");
                
                if ($integration && method_exists(app(\App\Domain\Integrations\PlatformRegistry::class)->for($integration), 'fetchStoreCurrency')) {
                    $this->line("  Source: <fg=yellow>Configured (auto-detected or manual)</>");
                } else {
                    $this->line("  Source: <fg=yellow>Manual configuration</>");
                }
            } else {
                $this->line("  Currency: <fg=red>NOT SET</>");
                
                if ($integration) {
                    $this->warn("  → This storefront needs a currency configured!");
                    $this->line("  → Orders will use business base currency as fallback");
                    
                    if ($this->option('set-missing')) {
                        $this->attemptAutoDetection($integration, $storefront);
                    }
                } else {
                    $this->line("  → No integration connected, set currency manually");
                }
            }

            $this->newLine();
        }

        if (!$this->option('set-missing')) {
            $this->info("💡 Tip: Run with --set-missing to auto-detect currencies for storefronts that need them");
        }

        $this->newLine();
        $this->line("<fg=green>Currency Architecture:</>");
        $this->line("  • Storefront currency = THE source of truth for all orders");
        $this->line("  • Auto-detected from platform API when integration is connected");
        $this->line("  • Can be manually changed in storefront settings");
        $this->line("  • Once set, never overridden by order payload data");

        return self::SUCCESS;
    }

    private function attemptAutoDetection(Integration $integration, Storefront $storefront): void
    {
        $this->line("  → Attempting auto-detection...");

        $detector = app(\App\Domain\Integrations\StorefrontCurrencyDetector::class);
        
        if (!$detector->supported($integration)) {
            $this->warn("  ✗ Platform does not support currency auto-detection");
            return;
        }

        $result = $detector->detectAndSet($integration);

        if ($result['set']) {
            $this->info("  ✓ Auto-detected and set: {$result['currency']}");
        } elseif ($result['detected']) {
            $this->warn("  ⚠ Detected {$result['currency']} but failed to save");
        } else {
            $this->error("  ✗ Could not detect currency from platform");
        }
    }
}
