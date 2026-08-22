<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Adapters;

use App\Domain\Delivery\Models\CourierConnection;
use App\Domain\Delivery\Models\Shipment;
use Illuminate\Support\Str;

/**
 * Deterministic sandbox courier for exercising dispatch and status updates.
 */
final class TestCourierAdapter implements CourierAdapter
{
    public static function handles(): array
    {
        return ['test'];
    }

    public function label(): string
    {
        return 'Test Courier (Sandbox)';
    }

    public function capabilities(): array
    {
        return [
            'book' => true,
            'cancel' => true,
            'track' => true,
            'webhook' => false,
            'pickup' => false,
        ];
    }

    public function book(CourierConnection $connection, Shipment $shipment): array
    {
        $tracking = 'TEST-'.strtoupper(Str::random(10));

        return [
            'tracking_number' => $tracking,
            'external_id' => $tracking,
            'raw' => ['sandbox' => true, 'tracking_number' => $tracking],
        ];
    }

    public function cancel(CourierConnection $connection, Shipment $shipment): bool
    {
        return true;
    }

    public function track(CourierConnection $connection, Shipment $shipment): array
    {
        return [['tracking_number' => $shipment->tracking_number, 'raw_status' => $shipment->status]];
    }

    public function verifyWebhook(CourierConnection $connection, string $body, array $headers): bool
    {
        return false;
    }

    public function parseWebhook(CourierConnection $connection, array $payload): array
    {
        return [];
    }
}
