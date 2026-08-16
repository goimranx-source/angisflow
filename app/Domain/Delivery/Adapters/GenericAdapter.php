<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Adapters;

use App\Domain\Delivery\Models\CourierConnection;
use App\Domain\Delivery\Models\Shipment;
use RuntimeException;

/**
 * The adapter for a courier nobody has written an adapter for.
 *
 * ── Why this one matters more than the named ones ────────────────────────────
 *
 * Most couriers in most countries have no public API, no documentation, and no
 * intention of writing either. The subscriber who needs this tool most is the
 * one whose delivery partner is three riders and a phone number.
 *
 * So this adapter books nothing and asks nothing. What it does is accept
 * whatever arrives — a webhook somebody wired up themselves, a form on the
 * dashboard, a row from a spreadsheet — and find the parcel it refers to. That
 * is the whole job, and it is enough to make such a courier a first-class
 * citizen of the same dashboard as one with a REST API.
 *
 * ── How it finds fields in a payload it has never seen ───────────────────────
 *
 * By looking for the handful of names the world actually uses. A tracking
 * number is called tracking_number, consignment_id, awb, waybill, or half a
 * dozen other things, and searching a flattened payload for any of them finds
 * it far more often than not. Where the subscriber knows better, the
 * connection's settings override the search — a guess that can be corrected is
 * the right shape for this.
 */
final class GenericAdapter implements CourierAdapter
{
    /** What a tracking number tends to be called. */
    private const TRACKING_KEYS = [
        'tracking_number', 'trackingnumber', 'tracking_id', 'tracking_code', 'tracking',
        'consignment_id', 'consignment_no', 'awb', 'awb_number', 'waybill', 'waybill_no',
        'parcel_id', 'shipment_id', 'reference', 'ref', 'order_id', 'invoice',
    ];

    private const STATUS_KEYS = [
        'status', 'current_status', 'order_status', 'delivery_status', 'state', 'event', 'event_type',
    ];

    private const TIME_KEYS = [
        'occurred_at', 'timestamp', 'time', 'updated_at', 'event_time', 'date', 'datetime',
    ];

    private const NOTE_KEYS = ['description', 'message', 'note', 'remarks', 'comment', 'reason'];

    private const PLACE_KEYS = ['location', 'city', 'hub', 'branch', 'zone', 'area'];

    public static function handles(): array
    {
        return ['generic', 'manual', 'webhook'];
    }

    public function label(): string
    {
        return 'Any courier (webhook or manual)';
    }

    public function capabilities(): array
    {
        return [
            'book' => false,
            'cancel' => false,
            'track' => false,
            'webhook' => true,
            'pickup' => false,
        ];
    }

    public function book(CourierConnection $connection, Shipment $shipment): array
    {
        throw new RuntimeException(
            'This courier has no booking API. Enter the tracking number they give you, and their updates will match themselves to it.'
        );
    }

    public function cancel(CourierConnection $connection, Shipment $shipment): bool
    {
        return false;
    }

    public function track(CourierConnection $connection, Shipment $shipment): array
    {
        return [];
    }

    /**
     * Check a shared secret, if one was agreed.
     *
     * A connection with no secret accepts anything, which is a deliberate
     * choice rather than an oversight: a courier who cannot sign is still a
     * courier, and refusing them means the subscriber has no way to receive
     * updates at all. The URL carries an unguessable id, which is weaker than a
     * signature and much better than nothing — and the dashboard says which
     * kind of protection a connection has, so the trade-off is visible.
     */
    public function verifyWebhook(CourierConnection $connection, string $body, array $headers): bool
    {
        $secret = $connection->webhook_secret;

        if ($secret === null || $secret === '') {
            return true;
        }

        $normalised = [];

        foreach ($headers as $key => $value) {
            $normalised[strtolower((string) $key)] = is_array($value) ? ($value[0] ?? '') : $value;
        }

        // Whichever header they chose to put it in — there is no standard, and
        // insisting on one would exclude most of them.
        foreach (['x-signature', 'x-webhook-signature', 'x-hub-signature-256', 'signature', 'x-api-key', 'authorization'] as $header) {
            $sent = $normalised[$header] ?? null;

            if ($sent === null || $sent === '') {
                continue;
            }

            $sent = trim(preg_replace('/^(sha256=|Bearer\s+)/i', '', (string) $sent) ?? '');

            // Both shapes: a shared key sent as-is, and an HMAC of the body.
            // hash_equals throughout, because a timing-safe comparison costs
            // nothing and a naive one leaks the secret a byte at a time.
            if (hash_equals($secret, $sent) || hash_equals(hash_hmac('sha256', $body, $secret), $sent)) {
                return true;
            }
        }

        return false;
    }

    public function parseWebhook(CourierConnection $connection, array $payload): array
    {
        $settings = $connection->settings ?? [];

        // A payload may be one event or many, and couriers disagree about where
        // the list lives. Anything that looks like a list of objects is treated
        // as a batch.
        $rows = $this->rowsIn($payload);
        $events = [];

        foreach ($rows as $row) {
            $flat = $this->flatten($row);

            $status = $this->find($flat, $settings['status_key'] ?? null, self::STATUS_KEYS);

            if ($status === null || $status === '') {
                // Nothing that says what happened. Skipped here rather than
                // guessed at — the raw body is stored by the receiver either
                // way, so nothing is lost and somebody can look.
                continue;
            }

            $events[] = [
                'tracking_number' => $this->find($flat, $settings['tracking_key'] ?? null, self::TRACKING_KEYS),
                'raw_status' => (string) $status,
                'occurred_at' => $this->find($flat, $settings['time_key'] ?? null, self::TIME_KEYS),
                'description' => $this->find($flat, null, self::NOTE_KEYS),
                'location' => $this->find($flat, null, self::PLACE_KEYS),
            ];
        }

        return $events;
    }

    /**
     * The events inside a payload, however they are wrapped.
     *
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function rowsIn(array $payload): array
    {
        if ($this->isBatch($payload)) {
            return array_values($payload);
        }

        foreach (['events', 'data', 'items', 'results', 'shipments', 'orders', 'statuses', 'history'] as $key) {
            $candidate = $payload[$key] ?? null;

            if (is_array($candidate) && $this->isBatch($candidate)) {
                return array_values($candidate);
            }
        }

        return [$payload];
    }

    /**
     * Whether this is a list of events rather than one nested object.
     *
     * Every element must be an array, and the keys must be a list. Testing only
     * the first element — which is the obvious way to write this — mistakes
     * `{"data": {"shipment": {...}, "status": "x"}}` for a batch, because its
     * first value happens to be an object. The rows then come back as a mix of
     * arrays and strings and the parse dies on the first string.
     *
     * @param  array<mixed>  $value
     */
    private function isBatch(array $value): bool
    {
        if ($value === [] || ! array_is_list($value)) {
            return false;
        }

        foreach ($value as $row) {
            if (! is_array($row)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The first value under any of these names.
     *
     * The subscriber's own mapping is tried first and exactly; the guesses
     * afterwards match on the last segment of a dotted path, so
     * `data.shipment.awb` is found by `awb`.
     *
     * @param  array<string, mixed>  $flat
     * @param  list<string>  $candidates
     */
    private function find(array $flat, ?string $preferred, array $candidates): ?string
    {
        if ($preferred !== null && isset($flat[$preferred]) && ! is_array($flat[$preferred])) {
            return (string) $flat[$preferred];
        }

        $byLeaf = [];

        foreach ($flat as $path => $value) {
            if (is_array($value) || $value === null || $value === '') {
                continue;
            }

            $leaf = strtolower((string) (str_contains($path, '.') ? substr(strrchr($path, '.'), 1) : $path));
            $byLeaf[$leaf] ??= $value;
        }

        foreach ($candidates as $name) {
            if (isset($byLeaf[$name])) {
                return (string) $byLeaf[$name];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function flatten(array $data, string $prefix = ''): array
    {
        $out = [];

        foreach ($data as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value) && $value !== [] && ! array_is_list($value)) {
                $out += $this->flatten($value, $path);

                continue;
            }

            $out[$path] = $value;
        }

        return $out;
    }
}
