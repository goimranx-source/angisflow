<?php

declare(strict_types=1);

namespace App\Domain\Delivery;

use App\Domain\Delivery\Models\CourierConnection;
use App\Domain\Delivery\Models\CourierStatusMapping;
use Illuminate\Support\Facades\DB;

/**
 * Turning a courier's word into ours.
 *
 * ── What happens to a word nobody has mapped ─────────────────────────────────
 *
 * It becomes UNKNOWN, and three other things happen: the raw value is kept, a
 * mapping row is created carrying our best guess, and the connection's unmapped
 * count goes up so somebody is told.
 *
 * The guess is recorded but not obeyed. That distinction is the whole design.
 * The first Prism resolved an unmapped status by returning the raw string as
 * though it were canonical — so a courier inventing "Delivered to neighbour"
 * silently created an eleventh status that no report grouped and no rule
 * matched, and a cash-on-delivery parcel sat unsettled with nothing to show
 * why. Here the parcel visibly stops at "unrecognised", which somebody can act
 * on in a minute.
 *
 * ── Why matching is done on the raw string ───────────────────────────────────
 *
 * Not on a normalised version. Two couriers send "RETURNED" and "returned"
 * meaning different things often enough — one is back at the hub, the other is
 * back with the merchant — that quietly folding case is a bug waiting to
 * happen. Lookup is exact; a case-insensitive second pass only proposes.
 */
final class StatusTranslator
{
    /**
     * Resolve one courier status to ours.
     *
     * @return array{status: ShipmentStatus, raw: string, mapped: bool, guessed: bool}
     */
    public function translate(CourierConnection $connection, string $rawStatus): array
    {
        $raw = trim($rawStatus);

        if ($raw === '') {
            return ['status' => ShipmentStatus::UNKNOWN, 'raw' => $raw, 'mapped' => false, 'guessed' => false];
        }

        $mapping = CourierStatusMapping::query()
            ->where('courier_connection_id', $connection->id)
            ->where('raw_status', $raw)
            ->first();

        if ($mapping !== null) {
            // Counted on every hit, so the settings screen can put the statuses
            // that actually arrive at the top rather than in alphabetical order.
            $mapping->increment('seen_count');

            return [
                'status' => $mapping->status(),
                'raw' => $raw,
                'mapped' => true,
                'guessed' => $mapping->is_guess,
            ];
        }

        return $this->learn($connection, $raw);
    }

    /**
     * Record a status we have not seen, with a guess, and flag it.
     *
     * @return array{status: ShipmentStatus, raw: string, mapped: bool, guessed: bool}
     */
    private function learn(CourierConnection $connection, string $raw): array
    {
        $guess = ShipmentStatus::guessFrom($raw);

        DB::transaction(function () use ($connection, $raw, $guess) {
            CourierStatusMapping::firstOrCreate(
                ['courier_connection_id' => $connection->id, 'raw_status' => $raw],
                [
                    // The guess is stored so the settings screen can offer it
                    // pre-filled, and UNKNOWN when there is no guess at all —
                    // never a value that would be acted on.
                    'canonical' => ($guess ?? ShipmentStatus::UNKNOWN)->value,
                    'is_guess' => true,
                    'seen_count' => 1,
                ],
            );

            $connection->increment('unmapped_count');
        });

        // Deliberately UNKNOWN, not the guess. The guess is a suggestion for a
        // person; acting on it would be exactly the silent-invention bug this
        // class exists to prevent.
        return ['status' => ShipmentStatus::UNKNOWN, 'raw' => $raw, 'mapped' => false, 'guessed' => $guess !== null];
    }

    /**
     * A person confirms what one of their courier's statuses means.
     */
    public function confirm(CourierConnection $connection, string $raw, ShipmentStatus $canonical): CourierStatusMapping
    {
        return DB::transaction(function () use ($connection, $raw, $canonical) {
            $mapping = CourierStatusMapping::firstOrNew([
                'courier_connection_id' => $connection->id,
                'raw_status' => trim($raw),
            ]);

            $wasUnconfirmed = ! $mapping->exists || $mapping->confirmed_at === null;

            $mapping->fill([
                'canonical' => $canonical->value,
                'is_guess' => false,
                'confirmed_at' => now(),
            ])->save();

            if ($wasUnconfirmed && $connection->unmapped_count > 0) {
                $connection->decrement('unmapped_count');
            }

            return $mapping;
        });
    }

    /**
     * Seed a connection with sensible mappings for a courier we know.
     *
     * Marked as guesses, every one. A default we shipped and a decision the
     * subscriber made are different kinds of thing, and the moment they look
     * the same on screen is the moment somebody stops checking.
     *
     * @param  array<string, ShipmentStatus>  $mappings  their word => ours
     */
    public function seed(CourierConnection $connection, array $mappings): int
    {
        $made = 0;

        foreach ($mappings as $raw => $canonical) {
            $mapping = CourierStatusMapping::firstOrCreate(
                ['courier_connection_id' => $connection->id, 'raw_status' => $raw],
                ['canonical' => $canonical->value, 'is_guess' => true],
            );

            if ($mapping->wasRecentlyCreated) {
                $made++;
            }
        }

        return $made;
    }

    /**
     * Everything on this connection still waiting for somebody to decide.
     *
     * @return list<array<string, mixed>>
     */
    public function pending(CourierConnection $connection): array
    {
        return CourierStatusMapping::query()
            ->where('courier_connection_id', $connection->id)
            ->whereNull('confirmed_at')
            // Most-seen first: the status arriving forty times a day matters
            // more than the one seen once last March.
            ->orderByDesc('seen_count')
            ->get()
            ->map(fn (CourierStatusMapping $m) => [
                ...$m->toPayload(),
                'suggestion' => ShipmentStatus::guessFrom($m->raw_status)?->value,
            ])
            ->all();
    }
}
