<?php

declare(strict_types=1);

namespace App\Domain\Production;

use App\Domain\Catalogue\Models\ProductVariant;
use App\Domain\Production\Models\BillOfMaterials;
use App\Domain\Production\Models\ProductionOrder;
use App\Domain\Production\Models\ProductionOrderComponent;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Stock\Models\StockLocation;
use App\Domain\Stock\Models\StockReservation;
use App\Domain\Stock\StockService;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Turning materials into product.
 *
 * ── The costing rule everything else follows from ────────────────────────────
 *
 * What a finished unit costs is what the materials actually taken cost, plus
 * the labour and overhead of the run, divided by the number of *good* units it
 * produced. Not by the number attempted — a run that made ninety good and
 * scrapped ten cost the same as one that made a hundred, and the ninety have to
 * carry it. Dividing by the attempt understates cost of sales by the scrap rate
 * on every unit sold, and the error compounds quietly through every margin
 * report afterwards.
 *
 * Materials are costed from the batches actually consumed — StockService picks
 * them FEFO and reports what they cost — rather than from standard cost. A run
 * that happened to draw on an expensive batch cost more, and pretending
 * otherwise is how a business concludes a product is profitable when it is not.
 */
final class ProductionService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly StockService $stock,
    ) {}

    /**
     * Plan a run. Consumes nothing and promises nothing.
     */
    public function plan(
        BillOfMaterials $bom,
        float $quantity,
        StockLocation $location,
        array $options = [],
    ): ProductionOrder {
        if ($quantity <= 0) {
            throw new RuntimeException('A production run has to make something.');
        }

        return ProductionOrder::create([
            'bill_of_materials_id' => $bom->id,
            'product_variant_id' => $bom->product_variant_id,
            'stock_location_id' => $location->id,
            'number' => $options['number'] ?? $this->nextNumber(),
            'quantity_planned' => $quantity,
            'planned_for' => $options['planned_for'] ?? null,
            'currency' => $bom->currency,
            'notes' => $options['notes'] ?? null,
            'created_by' => auth()->id(),
        ]);
    }

    /**
     * Snapshot the recipe and reserve the materials.
     *
     * The snapshot is the point: from here the order no longer reads the BOM,
     * so editing the recipe tomorrow cannot restate what this run was supposed
     * to do. The reservation is the other point — it is what stops the same
     * flour being promised to two runs and a customer.
     *
     * @throws RuntimeException if the materials are not there
     */
    public function release(ProductionOrder $order, array $options = []): ProductionOrder
    {
        if ($order->status !== ProductionOrder::PLANNED) {
            throw new RuntimeException("{$order->number} has already been released.");
        }

        $order->loadMissing('bom.components.variant', 'location');
        $bom = $order->bom;

        if ($bom === null || $bom->components->isEmpty()) {
            throw new RuntimeException('That order has no recipe to follow.');
        }

        // How many times the recipe has to be run to make what was asked for.
        $runs = (float) $order->quantity_planned / max(0.0001, (float) $bom->output_quantity);

        return DB::transaction(function () use ($order, $bom, $runs, $options) {
            foreach ($bom->components as $component) {
                $needed = round($component->quantityWithScrap() * $runs, 4);

                $reservation = null;

                if (! $component->is_optional) {
                    // Refuses when the material is not there, which is the
                    // whole reason to release ahead of starting: finding out on
                    // the factory floor is finding out too late.
                    $reservation = $this->stock->reserve(
                        $component->variant,
                        $needed,
                        $order->location,
                        [
                            'subject_type' => ProductionOrder::class,
                            'subject_id' => $order->id,
                            'allow_oversell' => $options['allow_short'] ?? false,
                        ],
                    );
                }

                ProductionOrderComponent::create([
                    'production_order_id' => $order->id,
                    'product_variant_id' => $component->product_variant_id,
                    'line_no' => $component->line_no,
                    'quantity_planned' => $needed,
                    'scrap_percent' => $component->scrap_percent,
                    'is_optional' => $component->is_optional,
                    'currency' => $order->currency,
                    'stock_reservation_id' => $reservation?->id,
                ]);
            }

            $order->forceFill([
                'status' => ProductionOrder::RELEASED,
                // Labour and overhead scale with the number of runs, not with
                // the yield — an hour of work is an hour whether it went well.
                'labour_cost_minor' => (int) round($bom->labour_cost_minor * $runs),
                'overhead_cost_minor' => (int) round($bom->overhead_cost_minor * $runs),
            ])->save();

            return $order->refresh()->load('components.variant');
        });
    }

    /**
     * Consume the materials.
     *
     * Actual consumption may differ from plan — more was needed, or less. The
     * caller passes what was really used where it knows; otherwise the plan is
     * assumed, which is right far more often than it is wrong.
     *
     * @param  array<int, float>  $actual  variant id => quantity actually used
     */
    public function start(ProductionOrder $order, string $on, array $actual = []): ProductionOrder
    {
        if ($order->status !== ProductionOrder::RELEASED) {
            throw new RuntimeException("{$order->number} has to be released before it can start.");
        }

        $order->loadMissing('components.variant', 'location');

        return DB::transaction(function () use ($order, $on, $actual) {
            $materialCost = 0;

            foreach ($order->components as $line) {
                $quantity = $actual[$line->product_variant_id] ?? (float) $line->quantity_planned;

                if ($quantity <= 0) {
                    continue;
                }

                // The reservation is given back first: it was this order's
                // claim, and issuing against it would otherwise be refused for
                // stock this very call is entitled to.
                if ($line->stock_reservation_id !== null) {
                    $held = StockReservation::find($line->stock_reservation_id);

                    if ($held !== null) {
                        $this->stock->release($held);
                        $held->forceFill(['status' => StockReservation::CONSUMED])->save();
                    }
                }

                $result = $this->stock->issue(
                    $line->variant,
                    $quantity,
                    $order->location,
                    $on,
                    [
                        'kind' => 'issue',
                        'reason' => "Consumed by {$order->number}",
                        'subject_type' => ProductionOrder::class,
                        'subject_id' => $order->id,
                        'allow_negative' => true,
                    ],
                );

                $line->forceFill([
                    'quantity_consumed' => $quantity,
                    'cost_minor' => $result['cost']->minor,
                ])->save();

                $materialCost += $result['cost']->minor;
            }

            $order->forceFill([
                'status' => ProductionOrder::STARTED,
                'started_at' => now(),
                'material_cost_minor' => $materialCost,
            ])->save();

            return $order->refresh();
        });
    }

    /**
     * Receive the output at what it actually cost.
     *
     * @throws RuntimeException if nothing good came out
     */
    public function complete(
        ProductionOrder $order,
        float $good,
        string $on,
        float $scrapped = 0,
    ): ProductionOrder {
        if ($order->status !== ProductionOrder::STARTED) {
            throw new RuntimeException("{$order->number} has not been started, so there is nothing to receive.");
        }

        if ($good <= 0) {
            throw new RuntimeException(
                'A run that produced nothing good cannot be completed. Cancel it, or record the scrap and write the materials off.'
            );
        }

        $order->loadMissing('output', 'location');

        return DB::transaction(function () use ($order, $good, $scrapped, $on) {
            $total = $order->material_cost_minor + $order->labour_cost_minor + $order->overhead_cost_minor;

            // Divided by the good units only — the scrapped ones cost the same
            // to make and produced nothing to carry it.
            $unitCost = (int) round($total / $good);

            $this->stock->receive(
                $order->output,
                $good,
                new Money($unitCost, $order->currency),
                $order->location,
                $on,
                [
                    'kind' => 'receipt',
                    'reason' => "Produced by {$order->number}",
                    'subject_type' => ProductionOrder::class,
                    'subject_id' => $order->id,
                    'batch_number' => $order->number,
                ],
            );

            $order->forceFill([
                'status' => ProductionOrder::COMPLETED,
                'quantity_produced' => $good,
                'quantity_scrapped' => $scrapped,
                'unit_cost_minor' => $unitCost,
                'completed_at' => now(),
            ])->save();

            // The output's standard cost follows what it most recently really
            // cost to make. A costing that never learns is one that drifts
            // further from the truth with every price change upstream.
            $order->output->forceFill(['cost_minor' => $unitCost])->save();

            return $order->refresh();
        });
    }

    /**
     * Abandon a run and give the materials back.
     *
     * Only before it has started. Once materials are consumed they are gone;
     * undoing that is a write-off or a return to stores, both of which are
     * deliberate acts with their own postings rather than a cancellation.
     */
    public function cancel(ProductionOrder $order, ?string $reason = null): ProductionOrder
    {
        if (! $order->isOpen()) {
            throw new RuntimeException("{$order->number} is already closed.");
        }

        if ($order->status === ProductionOrder::STARTED) {
            throw new RuntimeException(
                "{$order->number} has already consumed its materials. Complete it with what came out, or write the materials off."
            );
        }

        return DB::transaction(function () use ($order, $reason) {
            foreach ($order->components as $line) {
                if ($line->stock_reservation_id === null) {
                    continue;
                }

                $held = StockReservation::find($line->stock_reservation_id);

                if ($held !== null) {
                    $this->stock->release($held);
                }
            }

            $order->forceFill([
                'status' => ProductionOrder::CANCELLED,
                'notes' => trim((string) $order->notes."\nCancelled: ".($reason ?? 'no reason given')),
            ])->save();

            return $order->refresh();
        });
    }

    /**
     * What this recipe needs that is not there.
     *
     * Answered before releasing, so a planner sees the shortfall rather than a
     * refusal. Returns only what is short.
     *
     * @return list<array{sku: string, needed: float, available: float, short: float}>
     */
    public function shortages(BillOfMaterials $bom, float $quantity, StockLocation $location): array
    {
        $bom->loadMissing('components.variant');
        $runs = $quantity / max(0.0001, (float) $bom->output_quantity);
        $short = [];

        foreach ($bom->components as $component) {
            if ($component->is_optional) {
                continue;
            }

            $needed = round($component->quantityWithScrap() * $runs, 4);
            $available = $this->availableAt($component->variant, $location);

            if ($available + 0.00005 >= $needed) {
                continue;
            }

            $short[] = [
                'sku' => $component->variant->sku,
                'needed' => $needed,
                'available' => $available,
                'short' => round($needed - $available, 4),
            ];
        }

        return $short;
    }

    private function availableAt(ProductVariant $variant, StockLocation $location): float
    {
        $level = \App\Domain\Stock\Models\StockLevel::where('product_variant_id', $variant->id)
            ->where('stock_location_id', $location->id)
            ->first();

        return $level?->available() ?? 0.0;
    }

    /** PROD-2026-0001, restarting each year. */
    private function nextNumber(?string $date = null): string
    {
        $prefix = 'PROD-'.substr($date ?? now()->toDateString(), 0, 4).'-';

        $last = ProductionOrder::query()
            ->where('business_id', $this->tenant->business()->id)
            ->where('number', 'like', $prefix.'%')
            ->orderByDesc('number')
            ->value('number');

        return $prefix.str_pad((string) ($last === null ? 1 : ((int) substr($last, strlen($prefix))) + 1), 4, '0', STR_PAD_LEFT);
    }
}
