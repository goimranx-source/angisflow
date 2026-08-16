<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Adapters;

use App\Domain\Delivery\Models\CourierConnection;
use App\Domain\Delivery\Models\Shipment;

/**
 * How to talk to one courier.
 *
 * ── Everything here is optional except reading ───────────────────────────────
 *
 * The temptation is to require book(), cancel(), track() and a webhook parser,
 * and it excludes most of the world. A great many couriers — the ones a small
 * business in Dhaka or Lagos actually uses — have no API at all. You ring them,
 * they collect, and a spreadsheet arrives on Thursday. A framework that cannot
 * represent that courier is a framework that does not work where it is needed
 * most.
 *
 * So capabilities are declared rather than assumed. A driver that can only
 * parse a webhook says so; one that can only be updated by hand says so too,
 * and the dashboard draws the buttons it can honestly offer. GenericAdapter is
 * the floor: no API, no parsing rules, and still a first-class courier in every
 * screen.
 *
 * ── Adapters never write ─────────────────────────────────────────────────────
 *
 * They translate. Turning a payload into a canonical event is this layer's job;
 * deciding whether that event should move the parcel belongs to ShipmentTracker,
 * which knows about ordering and staleness. An adapter that saved would have to
 * duplicate that reasoning, and the duplicate would drift.
 */
interface CourierAdapter
{
    /** Adapter slugs this class claims. Matched against couriers.adapter. */
    public static function handles(): array;

    public function label(): string;

    /**
     * What this courier can actually do, so the interface offers no button
     * that will fail.
     *
     * @return array{book: bool, cancel: bool, track: bool, webhook: bool, pickup: bool}
     */
    public function capabilities(): array;

    /**
     * Hand a parcel over and get their reference back.
     *
     * @return array{tracking_number: ?string, external_id: ?string, raw: array<string, mixed>}
     */
    public function book(CourierConnection $connection, Shipment $shipment): array;

    public function cancel(CourierConnection $connection, Shipment $shipment): bool;

    /**
     * Ask where a parcel is, for couriers that will answer.
     *
     * Polling is the fallback, not the design. A courier that pushes is better
     * for everyone — but plenty do not, and a parcel nobody can ask about is a
     * parcel nobody can chase.
     *
     * @return list<array<string, mixed>>  events in canonical shape
     */
    public function track(CourierConnection $connection, Shipment $shipment): array;

    /**
     * Prove an inbound request really came from this courier.
     *
     * Returns false rather than throwing: a bad signature is an ordinary event
     * — scanners find these URLs within days of them existing — and it should
     * be logged and dropped, not raised as an error somebody has to triage.
     *
     * @param  array<string, string>  $headers
     */
    public function verifyWebhook(CourierConnection $connection, string $body, array $headers): bool;

    /**
     * Turn one inbound payload into events we understand.
     *
     * A single webhook may carry several: couriers batch, and a night sync can
     * arrive as one request holding a day of movement. Returning a list rather
     * than one event is what stops the rest being silently dropped.
     *
     * @param  array<string, mixed>  $payload
     * @return list<array{tracking_number: ?string, raw_status: string, occurred_at: ?string, description: ?string, location: ?string}>
     */
    public function parseWebhook(CourierConnection $connection, array $payload): array;
}
