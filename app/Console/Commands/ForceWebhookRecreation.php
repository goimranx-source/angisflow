<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Integrations\Models\Integration;
use App\Domain\Integrations\PlatformRegistry;
use App\Domain\Integrations\WebhookProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Force complete webhook recreation with a fresh secret.
 *
 * Unlike the regular reconcile(), this:
 * 1. Generates a completely new secret
 * 2. Deletes all existing webhooks from the platform
 * 3. Creates fresh webhooks with the new secret
 *
 * Use when signature verification keeps failing despite reconcile().
 */
class ForceWebhookRecreation extends Command
{
    protected $signature = 'webhooks:recreate {integration? : Integration ID to recreate webhooks for}';

    protected $description = 'Force complete webhook deletion and recreation with fresh secrets';

    public function handle(PlatformRegistry $registry, WebhookProvisioner $provisioner): int
    {
        $id = $this->argument('integration');

        if ($id === null) {
            $integrations = Integration::whereNotNull('webhook_token')->get();

            if ($integrations->isEmpty()) {
                $this->error('No integrations with webhooks found.');
                return self::FAILURE;
            }

            $this->info("Found {$integrations->count()} integration(s) with webhooks.");
            
            foreach ($integrations as $integration) {
                $this->processIntegration($integration, $registry, $provisioner);
            }

            return self::SUCCESS;
        }

        $integration = Integration::withoutGlobalScopes()->find($id);

        if ($integration === null) {
            $this->error("Integration {$id} not found.");
            return self::FAILURE;
        }

        return $this->processIntegration($integration, $registry, $provisioner);
    }

    private function processIntegration(
        Integration $integration,
        PlatformRegistry $registry,
        WebhookProvisioner $provisioner
    ): int {
        $this->newLine();
        $this->line("Processing: {$integration->name} (ID: {$integration->id})");
        $this->line(str_repeat('=', 80));

        if (! $provisioner->supported($integration)) {
            $this->warn('  ⊘ This platform does not support webhook management.');
            return self::FAILURE;
        }

        $driver = $registry->for($integration);
        
        if (!method_exists($driver, 'listWebhooks') || !method_exists($driver, 'createWebhook')) {
            $this->error('  ✗ Driver does not support webhook management.');
            return self::FAILURE;
        }

        // Step 1: Generate a completely new secret
        $this->info('  → Generating new secret...');
        $newSecret = Str::random(48);
        
        // Step 2: Save the new secret FIRST
        $integration->configuration = [
            ...($integration->configuration ?? []),
            'webhook_secret' => $newSecret,
            'webhook_secret_previous' => null, // Clear rotation
        ];
        $integration->save();
        $integration->refresh();
        
        $this->info('  ✓ New secret generated and saved');
        $this->line('    Secret: ' . substr($newSecret, 0, 10) . '...');

        // Step 3: Get current webhooks from platform
        $this->info('  → Fetching existing webhooks from platform...');
        $existingWebhooks = $driver->listWebhooks($integration);
        $token = (string) $integration->webhook_token;
        
        $ourWebhooks = array_filter($existingWebhooks, fn($wh) => $wh->belongsTo($token));
        
        $this->info('  ✓ Found ' . count($ourWebhooks) . ' webhook(s) belonging to us');

        // Step 4: DELETE all existing webhooks (WooCommerce doesn't update secrets properly)
        $this->warn('  → Deleting existing webhooks (required for secret update)...');
        
        $reflection = new \ReflectionClass($driver);
        $sendMethod = $reflection->getMethod('send');
        $sendMethod->setAccessible(true);
        
        foreach ($ourWebhooks as $webhook) {
            $this->line("    Deleting {$webhook->topic} (ID: {$webhook->id})...");
            $result = $sendMethod->invoke($driver, $integration, 'DELETE', 'webhooks/' . $webhook->id);
            
            if ($result['ok']) {
                $this->info("      ✓ Deleted");
            } else {
                $this->warn("      ! Failed to delete: " . ($result['message'] ?? 'unknown error'));
            }
        }

        // Step 5: Create fresh webhooks with the new secret
        $url = $this->deliveryUrl($integration);
        $allTopics = $driver->webhookTopics();
        $created = 0;
        $failed = 0;

        $this->info('  → Creating fresh webhooks with new secret...');

        foreach ($allTopics as $topic) {
            $this->line("    Creating {$topic}...");
            
            $webhook = $driver->createWebhook($integration, $topic, $url, $newSecret);
            
            if ($webhook !== null) {
                $created++;
                $this->info("      ✓ {$topic} created (ID: {$webhook->id})");
            } else {
                $failed++;
                $this->error("      ✗ {$topic} creation failed");
            }
        }

        // Summary
        $this->newLine();
        if ($failed === 0) {
            $this->info("  ✓ Successfully recreated {$created} webhook(s) with fresh secrets");
            $this->info('  → All webhooks now have proper secrets configured');
            $this->info('  → Test with a new order on the platform');
            return self::SUCCESS;
        } else {
            $this->error("  ✗ {$failed} webhook(s) failed, {$created} succeeded");
            $this->warn('  → Check that the API key has write permission');
            return self::FAILURE;
        }
    }

    private function deliveryUrl(Integration $integration): string
    {
        $path = route('api.v1.webhooks.integrations', ['token' => $integration->webhook_token], absolute: false);
        return rtrim((string) config('app.url'), '/').'/'.ltrim($path, '/');
    }
}
