<?php

declare(strict_types=1);

namespace App\Domain\Delivery;

/**
 * The vocabulary every courier is translated into.
 *
 * ── Why this is an enum in code and not a table ──────────────────────────────
 *
 * The first Prism made its canonical statuses per-user rows, alongside the
 * mappings. It reads as flexible and destroys the thing it was for: if each
 * subscriber decides what "completed" means, then nothing is canonical, no
 * report can span accounts, and no piece of logic can safely say "if the parcel
 * has been delivered, settle the cash". The whole point of a canonical model is
 * that it is the same everywhere.
 *
 * So the vocabulary is fixed and small, and everything else — which of a
 * courier's forty status strings maps to which of these ten — is data the
 * subscriber controls. Flexibility belongs in the mapping, not in the thing
 * being mapped to.
 *
 * ── Why UNKNOWN exists, and why it is not a failure ──────────────────────────
 *
 * A courier will one day send a status nobody has mapped. The old code returned
 * the raw string as though it were canonical, which quietly invents an
 * eleventh status that no logic handles and no report groups. Here it becomes
 * UNKNOWN, the raw value is kept beside it, and the connection is flagged as
 * having something to map. The parcel keeps moving, the operator is told, and
 * nothing downstream has to guess.
 */
enum ShipmentStatus: string
{
    /** Created with us, the courier has not been told yet. */
    case DRAFT = 'draft';

    /** Handed to the courier; they have acknowledged it. */
    case BOOKED = 'booked';

    /** Collected from us, or dropped at their hub. */
    case PICKED_UP = 'picked_up';

    /** Moving through their network. */
    case IN_TRANSIT = 'in_transit';

    /** With a rider, going to the door today. */
    case OUT_FOR_DELIVERY = 'out_for_delivery';

    /** A delivery was attempted and did not succeed. Not a failure yet. */
    case ATTEMPTED = 'attempted';

    /** The customer has it. For a cash-on-delivery parcel this is also the
     *  moment the courier starts owing us money. */
    case DELIVERED = 'delivered';

    /** Coming back to us, for any reason. */
    case RETURNING = 'returning';

    /** Back in our hands. Stock can go back on the shelf. */
    case RETURNED = 'returned';

    /** Gone, damaged beyond use, or written off by the courier. */
    case LOST = 'lost';

    case CANCELLED = 'cancelled';

    /** Sent by the courier, matched to nothing we know. */
    case UNKNOWN = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Not yet booked',
            self::BOOKED => 'Booked',
            self::PICKED_UP => 'Picked up',
            self::IN_TRANSIT => 'In transit',
            self::OUT_FOR_DELIVERY => 'Out for delivery',
            self::ATTEMPTED => 'Delivery attempted',
            self::DELIVERED => 'Delivered',
            self::RETURNING => 'On its way back',
            self::RETURNED => 'Returned to us',
            self::LOST => 'Lost or damaged',
            self::CANCELLED => 'Cancelled',
            self::UNKNOWN => 'Unrecognised status',
        };
    }

    /** Nothing more will happen to this parcel without somebody acting. */
    public function isFinal(): bool
    {
        return in_array($this, [self::DELIVERED, self::RETURNED, self::LOST, self::CANCELLED], true);
    }

    /** The parcel is somewhere between us and the customer. */
    public function isInFlight(): bool
    {
        return in_array($this, [
            self::BOOKED, self::PICKED_UP, self::IN_TRANSIT,
            self::OUT_FOR_DELIVERY, self::ATTEMPTED, self::RETURNING,
        ], true);
    }

    /**
     * Whether the goods are still ours.
     *
     * A parcel in transit is stock we own sitting in somebody's van, and it has
     * to stay on the balance sheet until the customer has it. Systems that
     * write stock off at despatch report inventory that is short by whatever is
     * on the road — which on a bad week is a lot.
     */
    public function isStillOurs(): bool
    {
        return ! in_array($this, [self::DELIVERED, self::LOST], true);
    }

    /**
     * Where this sits in the natural order of things.
     *
     * Couriers send events out of order — a webhook retried after a delay can
     * arrive behind a newer one. Comparing rank is how a late "in transit"
     * message is stopped from dragging a delivered parcel backwards.
     */
    public function rank(): int
    {
        return match ($this) {
            self::DRAFT => 0,
            self::BOOKED => 1,
            self::PICKED_UP => 2,
            self::IN_TRANSIT => 3,
            self::OUT_FOR_DELIVERY => 4,
            self::ATTEMPTED => 5,
            self::RETURNING => 6,
            self::DELIVERED, self::RETURNED, self::LOST, self::CANCELLED => 9,
            self::UNKNOWN => -1,
        };
    }

    /**
     * A first guess at what a courier's own word means.
     *
     * Used only to propose mappings when a connection is set up — never to
     * decide anything at runtime. A guess presented for confirmation is helpful;
     * the same guess applied silently is the bug this whole class exists to
     * prevent.
     */
    public static function guessFrom(string $raw): ?self
    {
        $s = strtolower(trim($raw));

        return match (true) {
            str_contains($s, 'deliver') && ! str_contains($s, 'out') && ! str_contains($s, 'undeliver') => self::DELIVERED,
            str_contains($s, 'out for') || str_contains($s, 'ofd') => self::OUT_FOR_DELIVERY,
            str_contains($s, 'transit') || str_contains($s, 'shipped') || str_contains($s, 'dispatch') => self::IN_TRANSIT,
            str_contains($s, 'pick') || str_contains($s, 'collect') => self::PICKED_UP,
            str_contains($s, 'attempt') || str_contains($s, 'hold') => self::ATTEMPTED,
            str_contains($s, 'return') || str_contains($s, 'rto') => self::RETURNING,
            str_contains($s, 'lost') || str_contains($s, 'damag') => self::LOST,
            str_contains($s, 'cancel') => self::CANCELLED,
            str_contains($s, 'book') || str_contains($s, 'created') || str_contains($s, 'pending') => self::BOOKED,
            default => null,
        };
    }
}
