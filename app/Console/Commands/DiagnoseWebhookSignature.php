<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Integrations\Models\Integration;
use App\Domain\Integrations\PlatformRegistry;
use App\Domain\Integrations\WebhookProvisioner;
use Illuminate\Console\Command;

/**
 * Diagnose webhook signature and configuration issues.
 *
 * ── The problem this solves ─────────────────────────────────────────────────
 *
 * Webhooks can fail silently due to signature mismatches, wrong URLs, disabled
 * webhooks, or missing secrets. This command provides a comprehensive diagnostic
 * of webhook configuration and recent delivery status.
 */
class DiagnoseWebhookSignature extends Command
{
    protected $signature = 'webhook:diagnose
                          {integration? : Integration ID or public ID to diagnose}
                          {--all : Check all integrations}
                          {--fix : Attempt to fix issues found}';

    protected $description = 'Diagnose webhook signature and configuration issues';

    public function __construct(
        private readonly WebhookProvisioner $provisioner,
        private readonly PlatformRegistry $registry,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $integrationId = $this->argument('integration');
        $checkAll = $this->option('all');
        $fix = $this->option('fix');

        if (!$integrationId && !$checkAll) {
            $this->error('Please provide an integration ID or use --all to check all integrations');
            return self::FAILURE;
        }

        $integrations = $checkAll
            ? Integration::withoutGlobalScopes()->get()
            : Integration::withoutGlobalScopes()
                ->where('id', $integrationId)
                ->orWhere('public_id', $integrationId)
                ->get();

        if ($integrations->isEmpty()) {
            $this->error('No integrations found.');
            return self::FAILURE;
        }

        foreach ($integrations as $integration) {
            $this->diagnoseIntegration($integration, $fix);
        }

        return self::SUCCESS;
    }

    private function diagnoseIntegration(Integration $integration, bool $fix): void
    {
        $this->newLine();
        $this->line(str_repeat('=', 80));
        $this->info("Integration: {$integration->name} (ID: {$integration->id})");
        $this->line("Provider: {$integration->provider}");
        $this->line("Status: " . $this->formatStatus($integration->status));
        $this->line(str_repeat('=', 80));
        $this->newLine();

        // Check if webhooks are supported
        if (!$this->provisioner->supported($integration)) {
            $this->warn('✗ Webhooks are not supported for this platform.');
            return;
        }

        // Check webhook configuration
        $this->line('<comment>Webhook Configuration:</comment>');
        
        $secrets = $integration->signingSecrets();
        $this->line("  Webhook Token: " . ($integration->webhook_token ? '<fg=green>✓ Set</>' : '<fg=red>✗ Missing</>'));
        $this->line("  Current Secret: " . ($integration->config('webhook_secret') ? '<fg=green>✓ Set</>' : '<fg=red>✗ Missing</>'));
        $this->line("  Previous Secret: " . ($integration->config('webhook_secret_previous') ? '<fg=yellow>✓ Set (rotation)</>' : '<fg=gray>Not set</>'));
        $this->line("  Total Secrets Available: " . count($secrets));
        
        $this->newLine();

        // Get webhook status from the shop
        $this->line('<comment>Checking shop webhooks...</comment>');
        
        try {
            $status = $this->provisioner->status($integration);
            
            if (!$status['supported']) {
                $this->warn('✗ Webhook management not supported.');
                return;
            }

            $this->line("  Delivery URL: " . ($status['url'] ?? '<fg=red>Not set</>'));
            $this->line("  Has Secret: " . ($status['has_secret'] ? '<fg=green>Yes</>' : '<fg=red>No</>'));
            $this->newLine();

            // Check each topic
            $this->line('<comment>Webhook Topics:</comment>');
            $issues = [];
            
            foreach ($status['topics'] as $topic) {
                $icon = match($topic['state']) {
                    'ok' => '<fg=green>✓</>',
                    'missing' => '<fg=red>✗</>',
                    'disabled' => '<fg=yellow>⚠</>',
                    'wrong-address' => '<fg=red>✗</>',
                    default => '?',
                };

                $stateLabel = match($topic['state']) {
                    'ok' => '<fg=green>OK</>',
                    'missing' => '<fg=red>MISSING</>',
                    'disabled' => '<fg=yellow>DISABLED</>',
                    'wrong-address' => '<fg=red>WRONG URL</>',
                    default => $topic['state'],
                };

                $this->line("  {$icon} {$topic['topic']}: {$stateLabel}");
                
                if ($topic['state'] !== 'ok') {
                    $issues[] = $topic;
                }
                
                if (isset($topic['url']) && $topic['url'] !== $status['url']) {
                    $this->line("    Current: {$topic['url']}");
                    $this->line("    Expected: {$status['url']}");
                }
            }
            
            $this->newLine();

            // Check last delivery
            if ($lastDelivery = $status['last_delivery'] ?? null) {
                $this->line('<comment>Last Webhook Delivery:</comment>');
                $this->line("  Time: {$lastDelivery['at']}");
                
                if ($lastDelivery['accepted']) {
                    $this->line("  Status: <fg=green>✓ Accepted</>");
                } else {
                    $this->line("  Status: <fg=red>✗ Refused</>");
                    if (isset($lastDelivery['reason'])) {
                        $this->line("  Reason: {$lastDelivery['reason']}");
                        
                        // Special note for signature issues with WooCommerce
                        if ($integration->provider === 'woocommerce' && str_contains($lastDelivery['reason'], 'signature')) {
                            $this->newLine();
                            $this->line("  <fg=yellow>Note: WooCommerce webhooks now work without signatures.</>");
                            $this->line("  <fg=yellow>The webhook token in the URL provides authentication.</>");
                            $this->line("  <fg=yellow>This old failure can be ignored - new webhooks will succeed.</>");
                        }
                    }
                }
            } else {
                $this->line('<comment>Last Webhook Delivery:</comment>');
                $this->line("  <fg=gray>No deliveries recorded yet</>");
            }
            
            $this->newLine();
                } else {
                    $this->line("  Status: <fg=red>✗ Refused</>");
                    $this->line("  Reason: " . ($lastDelivery['reason'] ?? 'Unknown'));
                    $issues[] = ['type' => 'delivery_failed', 'reason' => $lastDelivery['reason']];
                }
                $this->newLine();
            }

            // Overall health
            if ($status['healthy']) {
                $this->info('✓ Overall Status: HEALTHY');
            } else {
                $this->error('✗ Overall Status: UNHEALTHY');
                $this->line("  Issues found: " . count($issues));
            }

            // Offer to fix
            if (!empty($issues) && $fix) {
                $this->newLine();
                $this->line('<comment>Attempting to fix issues...</comment>');
                
                $result = $this->provisioner->reconcile($integration, false);
                
                if ($result['ok']) {
                    $this->info('✓ ' . $result['message']);
                    
                    if (!empty($result['created'])) {
                        $this->line("  Created: " . implode(', ', $result['created']));
                    }
                    
                    if (!empty($result['repaired'])) {
                        $this->line("  Repaired: " . implode(', ', $result['repaired']));
                    }
                } else {
                    $this->error('✗ ' . $result['message']);
                    
                    if (!empty($result['failed'])) {
                        $this->line("  Failed: " . implode(', ', $result['failed']));
                    }
                }
            } elseif (!empty($issues) && !$fix) {
                $this->newLine();
                $this->line('<fg=blue>→ Run with --fix to attempt automatic repair</>');
            }

        } catch (\Throwable $e) {
            $this->error('Failed to check webhooks: ' . $e->getMessage());
            $this->line('  ' . $e->getFile() . ':' . $e->getLine());
        }
    }

    private function formatStatus(string $status): string
    {
        return match($status) {
            'active' => '<fg=green>Active</>',
            'error' => '<fg=red>Error</>',
            'paused' => '<fg=yellow>Paused</>',
            default => $status,
        };
    }
}
