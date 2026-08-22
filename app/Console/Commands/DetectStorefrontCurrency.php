<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Integrations\Models\Integration;
use App\Domain\Integrations\StorefrontCurrencyDetector;
use Illuminate\Console\Command;

/**
 * Auto-detect and set storefront currencies from connected platforms.
 *
 * This command fetches the currency directly from WooCommerce/Shopify store
 * settings via API and sets it on the storefront. Only runs for storefronts
 * that don't have a currency configured yet.
 */
class DetectStorefrontCurrency extends Command
{
    protected $signature = 'storefront:detect-currency
                          {--integration= : Only check a specific integration ID}
                          {--force : Overwrite existing currency settings}';

    protected $description = 'Auto-detect and set storefront currencies from connected platforms';

    public function __construct(private readonly StorefrontCurrencyDetector $detector)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $integrationFilter = $this->option('integration');
        $force = $this->option('force');

        $this->info('Auto-detecting storefront currencies from platforms...');
        $this->newLine();

        $integrations = Integration::query()
            ->withoutGlobalScopes()
            ->with(['storefront', 'business'])
            ->whereNotNull('storefront_id')
            ->when($integrationFilter, fn ($q) => $q->where('id', $integrationFilter))
            ->when(!$force, function ($q) {
                // Only get integrations where storefront has no currency
                $q->whereHas('storefront', function ($q) {
                    $q->whereNull('currency')
                      ->orWhere('currency', '');
                });
            })
            ->get();

        if ($integrations->isEmpty()) {
            $this->warn('No integrations found needing currency detection.');
            return self::SUCCESS;
        }

        $detected = 0;
        $set = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($integrations as $integration) {
            $storefront = $integration->storefront;
            
            if (!$storefront) {
                continue;
            }

            $this->line("<comment>Integration:</comment> {$integration->name} ({$integration->provider})");
            $this->line("<comment>Storefront:</comment> {$storefront->name}");
            
            if (!$this->detector->supported($integration)) {
                $this->line("  <fg=gray>⊘ Currency detection not supported for {$integration->provider}</>");
                $skipped++;
                $this->newLine();
                continue;
            }

            if ($force && $storefront->currency) {
                $this->line("  <comment>Current:</comment> {$storefront->currency}");
            }

            $result = $this->detector->detectAndSet($integration);

            if ($result['set']) {
                $this->line("  <fg=green>✓ Detected and set:</> {$result['currency']}");
                $detected++;
                $set++;
            } elseif ($result['detected'] && !$result['set']) {
                $this->line("  <fg=yellow>⚠ Detected but failed to set:</> {$result['currency']}");
                $this->line("    {$result['message']}");
                $detected++;
                $failed++;
            } elseif (!$result['detected'] && str_contains($result['message'], 'already has currency')) {
                $this->line("  <fg=blue>→ Already configured:</> {$result['currency']}");
                $skipped++;
            } else {
                $this->line("  <fg=red>✗ Could not detect currency</>");
                $this->line("    {$result['message']}");
                $failed++;
            }

            $this->newLine();
        }

        $this->info('Summary:');
        $this->line("  Total integrations checked: {$integrations->count()}");
        $this->line("  Currencies detected: {$detected}");
        $this->line("  <fg=green>Successfully set: {$set}</>");
        $this->line("  <fg=blue>Already configured: {$skipped}</>");
        
        if ($failed > 0) {
            $this->line("  <fg=red>Failed: {$failed}</>");
        }

        return self::SUCCESS;
    }
}
