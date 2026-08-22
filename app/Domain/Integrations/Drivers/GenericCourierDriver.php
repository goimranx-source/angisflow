<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Drivers;

use App\Domain\Integrations\Contracts\PlatformDriver;
use App\Domain\Integrations\Models\Integration;
use App\Domain\Integrations\Support\Capabilities;
use App\Domain\Integrations\Support\ConfigField;
use App\Domain\Integrations\Support\ConnectionResult;
use Illuminate\Http\Request;

/**
 * Generic courier integration driver.
 *
 * This provides a minimal driver for courier integrations that need API
 * configuration but don't have specific platform requirements. Each courier
 * (Pathao, Steadfast, etc.) uses this driver with their own provider key.
 */
class GenericCourierDriver implements PlatformDriver
{
    public function __construct(
        private readonly string $label,
        private readonly string $provider,
    ) {}

    public function label(): string
    {
        return $this->label;
    }

    public function provider(): string
    {
        return $this->provider;
    }

    public function key(): string
    {
        return $this->provider;
    }

    public function configSchema(): array
    {
        return [
            new ConfigField(
                key: 'api_key',
                label: 'API Key',
                secret: true,
                help: 'Your '.$this->label.' API key',
            ),
            new ConfigField(
                key: 'api_secret',
                label: 'API Secret',
                required: false,
                secret: true,
                help: 'Your '.$this->label.' API secret (if required)',
            ),
            new ConfigField(
                key: 'base_url',
                label: 'API Base URL',
                required: false,
                help: 'Base URL for API requests (optional, uses default if not provided)',
            ),
        ];
    }

    public function testConnection(Integration $integration): ConnectionResult
    {
        $config = $integration->configuration ?? [];

        // Basic validation - check if API key exists
        if (empty($config['api_key'])) {
            return ConnectionResult::failed('API key is required');
        }

        // For now, we'll assume it's valid if the key is provided
        // Real courier-specific drivers would make actual API test calls
        return ConnectionResult::success('Configuration saved. Full connection test requires courier-specific implementation.');
    }

    public function verifyWebhook(Integration $integration, Request $request): bool
    {
        return false;
    }

    public function webhookEvent(Request $request, array $payload): ?string
    {
        return null;
    }

    public function capabilities(): Capabilities
    {
        // Couriers don't sync data like e-commerce platforms
        return new Capabilities([]);
    }
}
