<?php

declare(strict_types=1);

namespace App\Domain\Delivery;

use App\Domain\Delivery\Adapters\CourierAdapter;
use App\Domain\Delivery\Adapters\GenericAdapter;
use App\Domain\Delivery\Adapters\TestCourierAdapter;
use App\Domain\Delivery\Models\CourierConnection;
use App\Domain\Delivery\Models\Shipment;
use App\Domain\Delivery\Models\WebhookDelivery;
use App\Domain\Tenancy\Models\Account;
use App\Domain\Tenancy\Models\Business;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Taking in what couriers send us.
 *
 * ── The order of operations is the design ────────────────────────────────────
 *
 *   1. Write the body down. Always, before anything else, whatever it is.
 *   2. Answer 200.
 *   3. Try to understand it.
 *
 * Step 3 is allowed to fail, and the system stays whole when it does, because
 * step 1 already happened. Parse first and a courier's unannounced field rename
 * turns into a 500, their retries exhaust, and a week of deliveries silently
 * never occurred.
 *
 * ── Why almost nothing returns an error ──────────────────────────────────────
 *
 * A courier's webhook queue treats a non-2xx as "try again later", and after a
 * few tries, as "give up". Returning 500 because we could not read a payload
 * asks them to send it again, unchanged, until they stop sending it at all.
 * Accepting it and marking the row failed keeps the data and puts the problem
 * on a screen where somebody can act on it.
 *
 * The exception is a bad signature, which is a 401 — that one should be retried
 * by a courier who has just rotated a secret, and should be refused loudly
 * enough that a scanner moves on.
 */
final class WebhookReceiver
{
    /** @var list<class-string<CourierAdapter>> */
    private const ADAPTERS = [
        TestCourierAdapter::class,
        GenericAdapter::class,
    ];

    public function __construct(
        private readonly ShipmentTracker $tracker,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * @param  array<string, mixed>  $headers
     * @return array{delivery: WebhookDelivery, status: int, message: string}
     */
    public function receive(
        string $connectionPublicId,
        string $body,
        array $headers = [],
        ?string $sourceIp = null,
    ): array {
        // Written down before the connection is even resolved. A request for a
        // connection that no longer exists is still evidence.
        $delivery = WebhookDelivery::create([
            'endpoint' => "couriers/{$connectionPublicId}",
            'source_ip' => $sourceIp,
            'headers' => $this->safeHeaders($headers),
            'body' => $body,
            'received_at' => now(),
        ]);

        $connection = CourierConnection::withoutGlobalScopes()
            ->with('courier')
            ->where('public_id', $connectionPublicId)
            ->first();

        if ($connection === null) {
            return $this->finish($delivery, WebhookDelivery::IGNORED, 404,
                'No connection with that id', 'Unknown endpoint');
        }

        $delivery->forceFill([
            'account_id' => $connection->account_id,
            'business_id' => $connection->business_id,
            'courier_connection_id' => $connection->id,
        ])->save();

        $adapter = $this->adapterFor($connection);

        if (! $adapter->verifyWebhook($connection, $body, $this->stringHeaders($headers))) {
            // Not logged as an error: these URLs are found by scanners within
            // days, and a page of red alerts nobody can act on is how real
            // alerts stop being read.
            return $this->finish($delivery, WebhookDelivery::REJECTED, 401,
                'Signature did not match', 'Rejected');
        }

        $delivery->forceFill(['status' => WebhookDelivery::VERIFIED])->save();

        $payload = json_decode($body, true);

        if (! is_array($payload)) {
            return $this->finish($delivery, WebhookDelivery::FAILED, 202,
                'Body was not JSON we could read', 'Stored for review');
        }

        try {
            return $this->process($delivery, $connection, $adapter, $payload);
        } catch (Throwable $e) {
            // The row keeps the body, so this is recoverable rather than lost.
            Log::warning('Courier webhook could not be processed', [
                'delivery' => $delivery->public_id,
                'connection' => $connection->public_id,
                'error' => $e->getMessage(),
            ]);

            $connection->forceFill(['last_error' => $e->getMessage()])->save();

            return $this->finish($delivery, WebhookDelivery::FAILED, 202,
                $e->getMessage(), 'Stored for review');
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{delivery: WebhookDelivery, status: int, message: string}
     */
    private function process(
        WebhookDelivery $delivery,
        CourierConnection $connection,
        CourierAdapter $adapter,
        array $payload,
    ): array {
        $events = $adapter->parseWebhook($connection, $payload);

        if ($events === []) {
            return $this->finish($delivery, WebhookDelivery::PARSED, 200,
                'Nothing in the payload looked like a status', 'Accepted, nothing to apply');
        }

        // Tenancy has to be set explicitly: a webhook arrives with no session,
        // and every model below is scoped. Without this the shipment lookup
        // finds nothing and the events land nowhere.
        $this->tenant->setAccount(Account::withoutGlobalScopes()->find($connection->account_id));
        $this->tenant->setBusiness(Business::withoutGlobalScopes()->find($connection->business_id));

        $applied = 0;

        foreach ($events as $event) {
            $shipment = $this->findShipment($connection, $event);

            if ($shipment === null) {
                // Common and not an error: couriers send updates for parcels
                // booked elsewhere, and a merchant using one account for two
                // shops will see the other shop's traffic here.
                continue;
            }

            $record = $this->tracker->record(
                $shipment,
                $event['raw_status'],
                $payload,
                [
                    'occurred_at' => $event['occurred_at'] ?? null,
                    'description' => $event['description'] ?? null,
                    'location' => $event['location'] ?? null,
                    'source' => 'webhook',
                ],
            );

            if ($record->applied) {
                $applied++;
            }
        }

        $delivery->forceFill([
            'event_count' => count($events),
            'applied_count' => $applied,
        ])->save();

        return $this->finish($delivery, WebhookDelivery::PARSED, 200, null,
            sprintf('%d event(s), %d applied', count($events), $applied));
    }

    /**
     * Which parcel an event is about.
     *
     * By the courier's own tracking number first, because that is what they
     * know. Falling back to our own number covers the courier who echoes back
     * the reference we gave them, which is common enough to be worth trying and
     * cheap enough to be worth trying second.
     *
     * @param  array<string, mixed>  $event
     */
    private function findShipment(CourierConnection $connection, array $event): ?Shipment
    {
        $reference = $event['tracking_number'] ?? null;

        if ($reference === null || $reference === '') {
            return null;
        }

        return Shipment::withoutGlobalScopes()
            ->where('business_id', $connection->business_id)
            ->where(fn ($q) => $q
                ->where('tracking_number', $reference)
                ->orWhere('external_id', $reference)
                ->orWhere('number', $reference))
            ->first();
    }

    private function adapterFor(CourierConnection $connection): CourierAdapter
    {
        $slug = $connection->courier?->adapter ?? 'generic';

        foreach (self::ADAPTERS as $class) {
            if (in_array($slug, $class::handles(), true)) {
                return new $class;
            }
        }

        // A courier whose adapter has not been written yet still receives
        // webhooks, through the generic one. Refusing would leave the
        // subscriber with a connection that cannot do the one thing it needs.
        return new GenericAdapter;
    }

    /**
     * @return array{delivery: WebhookDelivery, status: int, message: string}
     */
    private function finish(
        WebhookDelivery $delivery,
        string $status,
        int $code,
        ?string $reason,
        string $message,
    ): array {
        $delivery->forceFill([
            'status' => $status,
            'failure_reason' => $reason,
            'processed_at' => now(),
        ])->save();

        return ['delivery' => $delivery->refresh(), 'status' => $code, 'message' => $message];
    }

    /**
     * Headers worth keeping, without the ones that are credentials.
     *
     * Storing an Authorization header would put a working key in a table that
     * exists to be read by support staff debugging an integration.
     *
     * @param  array<string, mixed>  $headers
     * @return array<string, string>
     */
    private function safeHeaders(array $headers): array
    {
        $redact = ['authorization', 'x-api-key', 'x-signature', 'x-webhook-signature', 'cookie', 'x-hub-signature-256'];
        $out = [];

        foreach ($this->stringHeaders($headers) as $key => $value) {
            $out[$key] = in_array($key, $redact, true) ? '[redacted]' : substr($value, 0, 500);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $headers
     * @return array<string, string>
     */
    private function stringHeaders(array $headers): array
    {
        $out = [];

        foreach ($headers as $key => $value) {
            $out[strtolower((string) $key)] = is_array($value) ? (string) ($value[0] ?? '') : (string) $value;
        }

        return $out;
    }
}
