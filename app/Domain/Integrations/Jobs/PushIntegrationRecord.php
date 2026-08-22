<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Jobs;

use App\Domain\Catalogue\Models\Product;
use App\Domain\Integrations\Contracts\PushesRecords;
use App\Domain\Integrations\Contracts\ReadsRecord;
use App\Domain\Integrations\EntityLinker;
use App\Domain\Integrations\Models\Integration;
use App\Domain\Integrations\Models\IntegrationLink;
use App\Domain\Integrations\PlatformRegistry;
use App\Domain\Integrations\Support\FieldMapSet;
use App\Domain\Integrations\Support\LineItems;
use App\Domain\Integrations\Support\StatusMap;
use App\Domain\Integrations\Support\StatusVocabulary;
use App\Domain\Sales\Models\Order;
use App\Domain\Tenancy\Models\Account;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

final class PushIntegrationRecord implements ShouldQueue
{
    /*
     * Batchable so a bulk change can send its pushes as one tracked group.
     *
     * The trait is what makes $this->batch() available and what lets a batch
     * count its own progress; without it Bus::batch() refuses the job outright.
     * It changes nothing about how a single push behaves when dispatched on its
     * own — batch() is simply null there.
     */
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public readonly int $integrationId,
        public readonly string $entity,
        public readonly int $localId,
    ) {}

    /**
     * The last word on an attempt that will not be retried again.
     *
     * ── Why the reason is kept on the link ───────────────────────────────────
     *
     * Because failed_jobs is a place for an operator, not for the person whose
     * order did not reach the shop. They are looking at the order, and what
     * they need is there: that it has not been taken, and what the shop said
     * about it. The pending stamp is left standing — the change is still owed —
     * and only a push that succeeds clears either.
     *
     * Laravel calls this after the final attempt, so a shop that was briefly
     * unreachable does not leave a complaint behind once a retry works.
     */
    public function failed(\Throwable $e): void
    {
        $integration = Integration::withoutGlobalScopes()->find($this->integrationId);

        if ($integration === null) {
            return;
        }

        IntegrationLink::withoutGlobalScopes()
            ->where('integration_id', $this->integrationId)
            ->where('entity', $this->entity)
            ->where('linkable_id', $this->localId)
            ->first()
            ?->recordPushFailure($e->getMessage());
    }

    public function handle(EntityLinker $linker): void
    {
        $integration = Integration::withoutGlobalScopes()->find($this->integrationId);

        if ($integration === null || ! $integration->isUsable() || ! $integration->bidirectional) {
            return;
        }

        $account = Account::withoutGlobalScopes()->find($integration->account_id);

        if ($account === null) {
            return;
        }

        app(TenantContext::class)->runAs($account, function () use ($integration, $linker): void {
            $this->push($integration, $linker);
        });
    }

    private function push(Integration $integration, EntityLinker $linker): void
    {
        $driver = app(PlatformRegistry::class)->for($integration);

        if (! $driver instanceof PushesRecords) {
            Log::warning('Integration push is not supported by provider', [
                'integration_id' => $integration->id,
                'provider' => $integration->provider,
                'entity' => $this->entity,
                'local_id' => $this->localId,
            ]);

            return;
        }

        $model = match ($this->entity) {
            IntegrationLink::ORDER => Order::query()->find($this->localId),
            IntegrationLink::PRODUCT => Product::query()->with('variants')->find($this->localId),
            default => null,
        };

        if ($model === null) {
            return;
        }

        $link = IntegrationLink::withoutGlobalScopes()
            ->where('integration_id', $integration->id)
            ->where('entity', $this->entity)
            ->where('linkable_id', $this->localId)
            ->first();
        $attributes = $this->attributes($model);
        $custom = $link?->custom_fields ?? [];
        /*
         * The record's own currency, so money is un-scaled correctly on the way
         * out. Without it every amount would be divided by a hundred regardless
         * of what it is denominated in, which is silently wrong for yen and
         * wrong by a thousand for dinars.
         */
        $context = ['currency' => $model instanceof Order
            ? (string) $model->currency
            : (string) ($model->defaultVariant()?->currency ?? 'USD')];

        $payload = FieldMapSet::for($integration, $this->entity)->reverse($attributes, $custom, $context);

        if ($model instanceof Order) {
            $status = StatusMap::for($integration)->toPlatform($model->status)
                ?? StatusVocabulary::toPlatform((string) $integration->provider, $model->status);

            if ($status !== null) {
                $payload['status'] = $status;
            }

            $payload += $this->lineItems($integration, $driver, $model, $link?->external_id, $context['currency']);
        }

        if ($payload === []) {
            return;
        }

        $result = $link === null
            ? $driver->create($integration, $this->entity, $payload)
            : $driver->update($integration, $this->entity, $link->external_id, $payload);

        if (! ($result['ok'] ?? false)) {
            $message = $result['message'] ?? 'Unknown error';

            Log::warning('Integration push failed', [
                'integration_id' => $integration->id,
                'entity' => $this->entity,
                'local_id' => $this->localId,
                'message' => $message,
            ]);

            throw new \RuntimeException($message);
        }

        $externalId = $link?->external_id ?? ($result['external_id'] ?? null);

        if ($externalId !== null) {
            $link = $linker->link($integration, $this->entity, $this->localId, (string) $externalId);
            $link->recordPush($payload);
        }

        /*
         * And settled by identity, not by instance.
         *
         * ── Why the model above is not enough ────────────────────────────────
         *
         * recordPush clears the debt on the row it was handed, which is the row
         * the linker returned — not necessarily the one loaded at the top of
         * this method, and not necessarily the only one if a link was rebuilt
         * while the shop was being waited on. Anything left holding a stale
         * pending stamp would be reported for ever as a change the shop never
         * took, when it plainly did.
         *
         * The debt exists to be believed. A cheap keyed write is worth more than
         * an argument about which instance was current.
         */
        IntegrationLink::withoutGlobalScopes()
            ->where('integration_id', $integration->id)
            ->where('entity', $this->entity)
            ->where('linkable_id', $this->localId)
            ->whereNotNull('push_pending_at')
            ->update(['push_pending_at' => null, 'push_error' => null]);
    }

    /**
     * What the shop should do to this order's items.
     *
     * ── Why the shop is read first ───────────────────────────────────────────
     *
     * To find the removals. An item taken off an order here leaves nothing
     * behind to compare against — the row is gone and its id with it — so the
     * only way to know the shop is still holding it is to ask. Skipping that
     * would make removal the one edit that silently never happened.
     *
     * Costs one call, on a background job, and only for orders that have been
     * pushed before. A failure to read is not a failure to push: the update
     * still goes, without the removals, which is the same behaviour as before
     * this existed rather than a regression on top of it.
     *
     * @return array<string, mixed>
     */
    private function lineItems(
        Integration $integration,
        PushesRecords $driver,
        Order $order,
        ?string $externalId,
        string $currency,
    ): array {
        $lines = $order->lines()->with('variant')->get();

        if ($lines->isEmpty()) {
            // An order with no lines here is far more likely to be one whose
            // lines were never imported than one the shop should be emptied of.
            return [];
        }

        /*
         * The shop's id for the product behind each line.
         *
         * Resolved in one query rather than one per line, and needed before the
         * remote lines are matched below as well as when new ones are built.
         */
        $productIds = $lines->pluck('variant.product_id')->filter()->unique()->all();

        $links = $productIds === [] ? collect() : IntegrationLink::withoutGlobalScopes()
            ->where('integration_id', $integration->id)
            ->where('entity', IntegrationLink::PRODUCT)
            ->whereIn('linkable_id', $productIds)
            ->pluck('external_id', 'linkable_id');

        $remoteIds = [];
        $remoteBySku = [];
        $remoteByProduct = [];

        if ($externalId !== null && $driver instanceof ReadsRecord) {
            $remote = $driver->fetchOne($integration, IntegrationLink::ORDER, $externalId);

            foreach ($remote['line_items'] ?? [] as $row) {
                if (! is_array($row) || ! isset($row['id'])) {
                    continue;
                }

                $id = (string) $row['id'];
                $remoteIds[] = $id;

                $sku = trim((string) ($row['sku'] ?? ''));

                if ($sku !== '') {
                    $remoteBySku[mb_strtolower($sku)] ??= $id;
                }

                if (! empty($row['product_id'])) {
                    $remoteByProduct[(string) $row['product_id']] ??= $id;
                }
            }
        }

        /*
         * Lines imported before their ids were kept are adopted, not replaced.
         *
         * Without this the first push of any existing order would send every
         * line as new and ask for every line the shop has to be removed. The end
         * state would be right and the route to it would not: the customer's
         * order would be rebuilt from scratch, losing the rows the shop's own
         * tax, stock and fulfilment records point at.
         *
         * Matched on SKU first — the identifier both sides agree on — then on
         * the shop's product id. Each remote line can only be claimed once, so
         * two of the same product on one order cannot both adopt the same row.
         */
        foreach ($lines as $line) {
            if ($line->external_id !== null) {
                continue;
            }

            $sku = mb_strtolower(trim((string) $line->sku));
            $productId = (string) ($links[$line->variant?->product_id] ?? '');

            $match = ($sku !== '' ? ($remoteBySku[$sku] ?? null) : null)
                ?? ($productId !== '' ? ($remoteByProduct[$productId] ?? null) : null);

            if ($match === null) {
                continue;
            }

            unset($remoteBySku[$sku], $remoteByProduct[$productId]);

            $line->forceFill(['external_id' => $match])->save();
        }

        $ours = $lines->map(fn ($line): array => [
            'external_id' => $line->external_id,
            'product_external_id' => $links[$line->variant?->product_id] ?? null,
            'name' => (string) $line->description,
            'quantity' => (float) $line->quantity,
            'unit_price_minor' => (int) $line->unit_price_minor,
            'total_minor' => (int) $line->total_minor,
        ])->all();

        return LineItems::toPlatform($integration, $ours, $remoteIds, $currency);
    }

    /** @return array<string, mixed> */
    private function attributes(Order|Product $model): array
    {
        if ($model instanceof Order) {
            return $model->only([
                'number', 'ordered_on', 'status', 'fulfilment_status', 'payment_status',
                'channel', 'external_ref', 'is_cod', 'currency', 'subtotal_minor',
                'discount_minor', 'shipping_minor', 'tax_minor', 'total_minor', 'paid_minor',
                'shipping_name', 'shipping_phone', 'shipping_address', 'shipping_city',
                'shipping_postcode', 'shipping_country', 'notes',
            ]);
        }

        $variant = $model->defaultVariant();

        return [
            ...$model->only(['name', 'slug', 'description', 'summary', 'brand', 'kind', 'tax_rate', 'is_stocked', 'is_active']),
            'variant.sku' => $variant?->sku,
            'variant.barcode' => $variant?->barcode,
            'variant.name' => $variant?->name,
            'variant.price_minor' => $variant?->price_minor,
            'variant.compare_at_minor' => $variant?->compare_at_minor,
            'variant.cost_minor' => $variant?->cost_minor,
            'variant.currency' => $variant?->currency,
            'variant.weight_grams' => $variant?->weight_grams,
        ];
    }
}
