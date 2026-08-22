<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Integrations\Models\Integration;
use App\Domain\Integrations\PlatformRegistry;
use Illuminate\Console\Command;

class InspectWebhooksOnPlatform extends Command
{
    protected $signature = 'webhooks:inspect {integration : Integration ID}';
    protected $description = 'Inspect webhook configuration on the platform itself';

    public function handle(PlatformRegistry $registry): int
    {
        $id = $this->argument('integration');
        $integration = Integration::withoutGlobalScopes()->find($id);

        if (!$integration) {
            $this->error("Integration {$id} not found.");
            return self::FAILURE;
        }

        $this->info("Integration: {$integration->name} (ID: {$integration->id})");
        $this->line(str_repeat('=', 80));

        $driver = $registry->for($integration);

        if (!method_exists($driver, 'listWebhooks')) {
            $this->error('This platform does not support webhook inspection.');
            return self::FAILURE;
        }

        // Use the public listWebhooks method
        $webhooks = $driver->listWebhooks($integration);
        $token = (string) $integration->webhook_token;

        $ours = array_filter($webhooks, fn($wh) => $wh->belongsTo($token));

        if (empty($ours)) {
            $this->warn('No webhooks found for this integration.');
            return self::SUCCESS;
        }

        $this->info("\nFound " . count($ours) . " webhook(s):\n");

        // Now fetch full details using reflection to access protected method
        $reflection = new \ReflectionClass($driver);
        $fetchMethod = $reflection->getMethod('fetch');
        $fetchMethod->setAccessible(true);

        $result = $fetchMethod->invoke($driver, $integration, 'webhooks', ['per_page' => 100, 'status' => 'all']);

        if (!$result['ok'] || !is_array($result['body'])) {
            $this->error('Failed to fetch webhook details from platform.');
            return self::FAILURE;
        }

        foreach ($result['body'] as $webhook) {
            if (!is_array($webhook)) continue;

            $url = (string) ($webhook['delivery_url'] ?? '');
            if (strpos($url, $token) === false) continue;

            $this->line("Webhook ID: {$webhook['id']}");
            $this->line("  Topic: {$webhook['topic']}");
            $this->line("  Status: {$webhook['status']}");
            $this->line("  Delivery URL: {$webhook['delivery_url']}");
            
            // Check if secret field exists and has a value
            $hasSecret = isset($webhook['secret']) && trim((string) $webhook['secret']) !== '';
            $secretDisplay = $hasSecret ? '[SET]' : '[EMPTY]';
            
            $this->line("  Secret: {$secretDisplay}");
            $this->newLine();
        }

        return self::SUCCESS;
    }
}
