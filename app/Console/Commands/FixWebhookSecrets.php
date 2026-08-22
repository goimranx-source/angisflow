<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Integrations\Models\Integration;
use App\Domain\Integrations\WebhookProvisioner;
use Illuminate\Console\Command;

/**
 * Force-fix webhook secrets for all integrations.
 *
 * This command re-provisions webhooks with fresh secrets to fix signature
 * mismatch issues.
 */
class FixWebhookSecrets extends Command
{
    protected $signature = 'webhooks:fix-secrets {--integration= : Specific integration ID}';
    protected $description = 'Fix webhook signature mismatches by re-provisioning with fresh secrets';

    public function __construct(private readonly WebhookProvisioner $provisioner)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $integrationId = $this->option('integration');

        $integrations = $integrationId
            ? Integration::withoutGlobalScopes()->where('id', $integrationId)->get()
            : Integration::withoutGlobalScopes()->whereNotNull('webhook_token')->get();

        if ($integrations->isEmpty()) {
            $this->error('No integrations found.');
            return self::FAILURE;
        }

        $this->info('Fixing webhook secrets for ' . $integrations->count() . ' integration(s)...');
        $this->newLine();

        $fixed = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($integrations as $integration) {
            $this->line("Processing: {$integration->name} ({$integration->provider})");

            if (!$this->provisioner->supported($integration)) {
                $this->line('  <fg=gray>Skipped - webhooks not supported</>');
                $skipped++;
                continue;
            }

            try {
                // Force reconcile to ensure secret is properly set
                $result = $this->provisioner->reconcile($integration, false);

                if ($result['ok']) {
                    $this->line('  <fg=green>✓ Fixed</>');
                    if (!empty($result['created'])) {
                        $this->line('    Created: ' . implode(', ', $result['created']));
                    }
                    if (!empty($result['repaired'])) {
                        $this->line('    Repaired: ' . implode(', ', $result['repaired']));
                    }
                    $fixed++;
                } else {
                    $this->line('  <fg=red>✗ Failed</>');
                    $this->line('    ' . $result['message']);
                    $failed++;
                }
            } catch (\Throwable $e) {
                $this->line('  <fg=red>✗ Error: ' . $e->getMessage() . '</>');
                $failed++;
            }

            $this->newLine();
        }

        $this->info('Summary:');
        $this->line("  Fixed: {$fixed}");
        $this->line("  Skipped: {$skipped}");
        $this->line("  Failed: {$failed}");

        return self::SUCCESS;
    }
}
