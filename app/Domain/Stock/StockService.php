<?php

declare(strict_types=1);

namespace App\Domain\Stock;

use App\Domain\Catalogue\Models\ProductVariant;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Stock\Models\StockBatch;
use App\Domain\Stock\Models\StockLevel;
use App\Domain\Stock\Models\StockLocation;
use App\Domain\Stock\Models\StockMovement;
use App\Domain\Stock\Models\StockReservation;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The only thing allowed to move stock.
 *
 * Same reasoning as Ledger: every rule that keeps stock believable is a rule
 * about a whole operation — write the movement, decrement the batch, update the
 * level, all or nothing — and a caller that writes a movement directly gets
 * none of it. So the models have no public write path for movements and levels;
 * everything comes through here.
 *
 * ── Which batch gets consumed, and why it matters ────────────────────────────
 *
 * Issuing a quantity is not one decision but several: five units may come from
 * three batches that cost three different amounts. Cost of sales is the sum of
 * what those particular units actually cost, so the choice of batch changes the
 * reported margin — which is why it is made here, consistently, rather than by
 * whichever screen happened to call.
 *
 * FEFO: soonest expiry first, oldest first within that. For anything perishable
 * FIFO and FEFO differ, and when they differ FIFO is the one that leaves
 * short-dated stock on the shelf to be thrown away.
 */
final class StockService
{
    public function __construct(
        private readonly TenantContext $tenant,
    ) {}

    /**
     * Bring stock in, as a new batch.
     *
     * @param  array<string, mixed>  $options
     */
    public function receive(
        ProductVariant $variant,
        float $quantity,
        Money $unitCost,
        StockLocation $location,
        string $on,
        array $options = [],
    ): StockMovement {
        if ($quantity <= 0) {
            throw new RuntimeException('A receipt has to bring something in. Use an adjustment to correct a count.');
        }

        return DB::transaction(function () use ($variant, $quantity, $unitCost, $location, $on, $options) {
            $batch = StockBatch::create([
                'product_variant_id' => $variant->id,
                'batch_number' => $options['batch_number'] ?? $this->nextBatchNumber($variant, $on),
                'supplier_lot' => $options['supplier_lot'] ?? null,
                'received_on' => $on,
                'expires_on' => $options['expires_on'] ?? null,
                'quantity_received' => $quantity,
                'quantity_remaining' => $quantity,
                'unit_cost_minor' => $unitCost->minor,
                'currency' => $unitCost->currency,
                'supplier_id' => $options['supplier_id'] ?? null,
                'bill_id' => $options['bill_id'] ?? null,
            ]);

            $movement = $this->writeMovement($variant, $location, [
                'kind' => $options['kind'] ?? StockMovement::RECEIPT,
                'quantity' => $quantity,
                'stock_batch_id' => $batch->id,
                'unit_cost_minor' => $unitCost->minor,
                'currency' => $unitCost->currency,
                'moved_on' => $on,
                'reason' => $options['reason'] ?? null,
                'subject_type' => $options['subject_type'] ?? null,
                'subject_id' => $options['subject_id'] ?? null,
            ]);

            $this->applyToLevel($variant, $location, $quantity);

            return $movement;
        });
    }

    /**
     * Take stock out, drawing from batches in FEFO order.
     *
     * @return array{movements: list<StockMovement>, cost: Money}  what left, and what it cost
     *
     * @throws RuntimeException if there is not enough, unless allow_negative is set
     */
    public function issue(
        ProductVariant $variant,
        float $quantity,
        StockLocation $location,
        string $on,
        array $options = [],
    ): array {
        if ($quantity <= 0) {
            throw new RuntimeException('An issue has to take something out.');
        }

        return DB::transaction(function () use ($variant, $quantity, $location, $on, $options) {
            $level = $this->lockLevel($variant, $location);
            $onHand = (float) ($level?->on_hand ?? 0);

            if ($onHand < $quantity && ! ($options['allow_negative'] ?? false)) {
                throw new RuntimeException(sprintf(
                    'Only %s of %s at %s, and %s was asked for.',
                    rtrim(rtrim(number_format($onHand, 4, '.', ''), '0'), '.') ?: '0',
                    $variant->sku,
                    $location->name,
                    rtrim(rtrim(number_format($quantity, 4, '.', ''), '0'), '.'),
                ));
            }

            $batches = StockBatch::query()
                ->where('product_variant_id', $variant->id)
                ->available()
                ->fefo()
                ->lockForUpdate()
                ->get();

            $currency = $options['currency']
                ?? $batches->first()?->currency
                ?? $variant->currency;

            $remaining = $quantity;
            $movements = [];
            $costMinor = 0;

            foreach ($batches as $batch) {
                if ($remaining <= 0) {
                    break;
                }

                $take = min($remaining, (float) $batch->quantity_remaining);

                $movements[] = $this->writeMovement($variant, $location, [
                    'kind' => $options['kind'] ?? StockMovement::ISSUE,
                    'quantity' => -$take,
                    'stock_batch_id' => $batch->id,
                    'unit_cost_minor' => $batch->unit_cost_minor,
                    'currency' => $batch->currency,
                    'moved_on' => $on,
                    'reason' => $options['reason'] ?? null,
                    'subject_type' => $options['subject_type'] ?? null,
                    'subject_id' => $options['subject_id'] ?? null,
                ]);

                $costMinor += (int) round($batch->unit_cost_minor * $take);
                $batch->forceFill(['quantity_remaining' => (float) $batch->quantity_remaining - $take])->save();
                $remaining -= $take;
            }

            // Nothing left to draw from, but the caller allowed it — an oversell
            // that will be reconciled when stock arrives. Costed at the
            // variant's standard cost, because there is no batch to ask.
            if ($remaining > 0) {
                $movements[] = $this->writeMovement($variant, $location, [
                    'kind' => $options['kind'] ?? StockMovement::ISSUE,
                    'quantity' => -$remaining,
                    'stock_batch_id' => null,
                    'unit_cost_minor' => $variant->cost_minor,
                    'currency' => $currency,
                    'moved_on' => $on,
                    'reason' => trim(($options['reason'] ?? '').' (no batch available)'),
                    'subject_type' => $options['subject_type'] ?? null,
                    'subject_id' => $options['subject_id'] ?? null,
                ]);

                $costMinor += (int) round($variant->cost_minor * $remaining);
            }

            $this->applyToLevel($variant, $location, -$quantity);

            return ['movements' => $movements, 'cost' => new Money($costMinor, $currency)];
        });
    }

    /**
     * Set what is actually there, after a count.
     *
     * Takes the counted figure rather than a difference, because that is what
     * somebody holding a clipboard has. Working out the delta is arithmetic;
     * asking them to do it is how a stocktake introduces the error it was meant
     * to find.
     */
    public function count(
        ProductVariant $variant,
        float $counted,
        StockLocation $location,
        string $on,
        ?string $reason = null,
    ): ?StockMovement {
        return DB::transaction(function () use ($variant, $counted, $location, $on, $reason) {
            $level = $this->lockLevel($variant, $location);
            $delta = $counted - (float) ($level?->on_hand ?? 0);

            if (abs($delta) < 0.00005) {
                // Counted and correct. Recording a zero movement would bury the
                // real ones, so only the count date is stamped.
                $level?->forceFill(['counted_at' => now()])->save();

                return null;
            }

            $movement = $this->writeMovement($variant, $location, [
                'kind' => StockMovement::ADJUSTMENT,
                'quantity' => $delta,
                'unit_cost_minor' => $variant->cost_minor,
                'currency' => $variant->currency,
                'moved_on' => $on,
                'reason' => $reason ?? 'Stocktake',
            ]);

            $this->applyToLevel($variant, $location, $delta);
            $this->levelFor($variant, $location)->forceFill(['counted_at' => now()])->save();

            // A count that found more is stock that arrived without a batch.
            // One is opened at standard cost so the extra units can be issued
            // later rather than being unsellable.
            if ($delta > 0) {
                StockBatch::create([
                    'product_variant_id' => $variant->id,
                    'batch_number' => $this->nextBatchNumber($variant, $on, 'COUNT'),
                    'received_on' => $on,
                    'quantity_received' => $delta,
                    'quantity_remaining' => $delta,
                    'unit_cost_minor' => $variant->cost_minor,
                    'currency' => $variant->currency,
                ]);
            } else {
                $this->drawDownBatches($variant, abs($delta));
            }

            return $movement;
        });
    }

    /**
     * Promise stock to somebody without moving it.
     *
     * @throws RuntimeException if there is not enough left unpromised
     */
    public function reserve(
        ProductVariant $variant,
        float $quantity,
        StockLocation $location,
        array $options = [],
    ): StockReservation {
        if ($quantity <= 0) {
            throw new RuntimeException('A reservation has to be for something.');
        }

        return DB::transaction(function () use ($variant, $quantity, $location, $options) {
            $level = $this->lockLevel($variant, $location) ?? $this->levelFor($variant, $location);
            $available = max(0, (float) $level->on_hand - (float) $level->reserved);

            if ($available < $quantity && ! ($options['allow_oversell'] ?? false)) {
                throw new RuntimeException(sprintf(
                    'Only %s of %s can still be promised at %s — %s is on hand and %s is already spoken for.',
                    rtrim(rtrim(number_format($available, 4, '.', ''), '0'), '.') ?: '0',
                    $variant->sku,
                    $location->name,
                    rtrim(rtrim(number_format((float) $level->on_hand, 4, '.', ''), '0'), '.'),
                    rtrim(rtrim(number_format((float) $level->reserved, 4, '.', ''), '0'), '.'),
                ));
            }

            $level->forceFill(['reserved' => (float) $level->reserved + $quantity])->save();

            return StockReservation::create([
                'product_variant_id' => $variant->id,
                'stock_location_id' => $location->id,
                'quantity' => $quantity,
                'subject_type' => $options['subject_type'] ?? null,
                'subject_id' => $options['subject_id'] ?? null,
                'expires_at' => $options['expires_at'] ?? null,
                'created_by' => $options['actor_id'] ?? auth()->id(),
            ]);
        });
    }

    /** Give back a claim without shipping it. */
    public function release(StockReservation $reservation): StockReservation
    {
        if ($reservation->status !== StockReservation::HELD) {
            return $reservation;
        }

        return DB::transaction(function () use ($reservation) {
            $level = StockLevel::where('product_variant_id', $reservation->product_variant_id)
                ->where('stock_location_id', $reservation->stock_location_id)
                ->lockForUpdate()
                ->first();

            $level?->forceFill([
                // Never below zero: a release against a level that was rebuilt
                // or corrected in between should not push reserved negative and
                // make everything look available.
                'reserved' => max(0, (float) $level->reserved - (float) $reservation->quantity),
            ])->save();

            $reservation->forceFill([
                'status' => StockReservation::RELEASED,
                'released_at' => now(),
            ])->save();

            return $reservation;
        });
    }

    /**
     * Ship what was reserved: the claim becomes a movement.
     *
     * @return array{movements: list<StockMovement>, cost: Money}
     */
    public function fulfil(StockReservation $reservation, string $on, array $options = []): array
    {
        if ($reservation->status !== StockReservation::HELD) {
            throw new RuntimeException('That reservation is no longer held, so there is nothing to ship against it.');
        }

        return DB::transaction(function () use ($reservation, $on, $options) {
            $variant = ProductVariant::findOrFail($reservation->product_variant_id);
            $location = StockLocation::findOrFail($reservation->stock_location_id);

            // Released first, so the issue below sees the stock as its own
            // rather than refusing on a claim this very call is redeeming.
            $this->release($reservation);

            $result = $this->issue($variant, (float) $reservation->quantity, $location, $on, [
                ...$options,
                'subject_type' => $options['subject_type'] ?? $reservation->subject_type,
                'subject_id' => $options['subject_id'] ?? $reservation->subject_id,
            ]);

            $reservation->forceFill(['status' => StockReservation::CONSUMED])->save();

            return $result;
        });
    }

    /**
     * Rebuild on_hand from the movements, and say what was wrong.
     *
     * The check that makes stock_levels a cache rather than a second source of
     * truth. Run it after an import, after a restore, or whenever a figure is
     * disbelieved — it is the difference between a number you trust and a
     * number you hope about.
     *
     * @return list<array{sku: string, location: string, was: float, now: float, drift: float}>
     */
    public function recalculate(?ProductVariant $variant = null): array
    {
        $businessId = $this->businessId();

        $sums = DB::table('stock_movements')
            ->where('business_id', $businessId)
            ->when($variant !== null, fn ($q) => $q->where('product_variant_id', $variant->id))
            ->groupBy('product_variant_id', 'stock_location_id')
            ->selectRaw('product_variant_id, stock_location_id, SUM(quantity) AS total')
            ->get();

        $drift = [];

        foreach ($sums as $row) {
            $level = StockLevel::where('product_variant_id', $row->product_variant_id)
                ->where('stock_location_id', $row->stock_location_id)
                ->first();

            $was = (float) ($level?->on_hand ?? 0);
            $now = (float) $row->total;

            if (abs($was - $now) < 0.00005) {
                continue;
            }

            if ($level === null) {
                $level = StockLevel::create([
                    'business_id' => $businessId,
                    'product_variant_id' => $row->product_variant_id,
                    'stock_location_id' => $row->stock_location_id,
                ]);
            }

            $level->forceFill(['on_hand' => $now])->save();

            $drift[] = [
                'sku' => ProductVariant::find($row->product_variant_id)?->sku ?? '?',
                'location' => StockLocation::find($row->stock_location_id)?->name ?? '?',
                'was' => $was,
                'now' => $now,
                'drift' => round($now - $was, 4),
            ];
        }

        return $drift;
    }

    /** Everything held past its promise, given back. */
    public function expireStaleReservations(): int
    {
        $count = 0;

        foreach (StockReservation::query()->stale()->get() as $reservation) {
            $this->release($reservation);
            $count++;
        }

        return $count;
    }

    /** What could still be promised, across every sellable place. */
    public function availableAcrossLocations(ProductVariant $variant): float
    {
        return (float) StockLevel::query()
            ->where('product_variant_id', $variant->id)
            ->whereHas('location', fn ($q) => $q->where('is_sellable', true)->where('is_active', true))
            ->get()
            ->sum(fn (StockLevel $level) => $level->available());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function writeMovement(ProductVariant $variant, StockLocation $location, array $attributes): StockMovement
    {
        return StockMovement::create([
            'product_variant_id' => $variant->id,
            'stock_location_id' => $location->id,
            'created_by' => auth()->id(),
            ...$attributes,
        ]);
    }

    private function applyToLevel(ProductVariant $variant, StockLocation $location, float $delta): StockLevel
    {
        $level = $this->levelFor($variant, $location);
        $level->forceFill(['on_hand' => (float) $level->on_hand + $delta])->save();

        return $level;
    }

    private function levelFor(ProductVariant $variant, StockLocation $location): StockLevel
    {
        return StockLevel::firstOrCreate(
            ['product_variant_id' => $variant->id, 'stock_location_id' => $location->id],
            ['business_id' => $this->businessId()],
        );
    }

    private function lockLevel(ProductVariant $variant, StockLocation $location): ?StockLevel
    {
        return StockLevel::where('product_variant_id', $variant->id)
            ->where('stock_location_id', $location->id)
            ->lockForUpdate()
            ->first();
    }

    /** Take a quantity out of the batches without writing movements — used by count(). */
    private function drawDownBatches(ProductVariant $variant, float $quantity): void
    {
        $remaining = $quantity;

        foreach (StockBatch::where('product_variant_id', $variant->id)->available()->fefo()->get() as $batch) {
            if ($remaining <= 0) {
                break;
            }

            $take = min($remaining, (float) $batch->quantity_remaining);
            $batch->forceFill(['quantity_remaining' => (float) $batch->quantity_remaining - $take])->save();
            $remaining -= $take;
        }
    }

    private function nextBatchNumber(ProductVariant $variant, string $on, string $prefix = 'B'): string
    {
        $stem = $prefix.'-'.str_replace('-', '', substr($on, 0, 10));
        $n = StockBatch::where('product_variant_id', $variant->id)
            ->where('batch_number', 'like', $stem.'%')
            ->count();

        return $stem.'-'.str_pad((string) ($n + 1), 3, '0', STR_PAD_LEFT);
    }

    private function businessId(): int
    {
        $business = $this->tenant->business();

        if ($business === null) {
            throw new RuntimeException('No set of books is open, so there is nowhere to hold stock.');
        }

        return $business->id;
    }
}
