<?php

declare(strict_types=1);

namespace App\Domain\Integrations;

use App\Domain\Integrations\Support\SyncMute;
use App\Domain\Integrations\Contracts\PullsRecords;
use App\Domain\Integrations\Models\Integration;
use App\Domain\Integrations\Models\IntegrationLink;
use App\Domain\Integrations\Reconcilers\CustomerReconciler;
use App\Domain\Integrations\Reconcilers\OrderReconciler;
use App\Domain\Integrations\Reconcilers\ProductReconciler;
use App\Domain\Integrations\Support\FieldMapSet;
use App\Domain\Integrations\Support\FieldPath;
use App\Domain\Integrations\Support\MappedRecord;
use App\Domain\Integrations\Support\Names;
use App\Domain\Integrations\Support\StatusMap;
use App\Domain\Integrations\Support\SyncReport;
use App\Domain\Sales\Models\Order;
use App\Domain\Tenancy\TenantContext;
use App\Models\Storefront;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Reading a shop, one page at a time, into this application.
 *
 * ── One loop, four platforms ─────────────────────────────────────────────────
 *
 * Nothing in this class names a platform. It asks the registry for a driver,
 * asks the driver for a page, translates each record through the connection's
 * own mappings, and hands the result to a reconciler. Adding a fifth platform
 * changes nothing here — which is the whole reason the driver contract exists.
 *
 * ── Why each record is its own transaction ───────────────────────────────────
 *
 * Wrapping the whole sync would mean one malformed order out of four hundred
 * rolls back the other three hundred and ninety-nine, and the next run meets
 * the same bad record and does it again. Wrapping each one means the bad record
 * is skipped, counted and reported, and everything around it lands.
 *
 * The link is written in the same transaction as the record it points at. Split
 * apart, a failure between them leaves a record with no link — which the next
 * sync cannot recognise, so it creates a second copy, and then a third.
 *
 * ── Where it resumes from ────────────────────────────────────────────────────
 *
 * The high-water mark is recorded per entity when a run finishes cleanly, and
 * only then. A run that failed halfway must not advance it: the records it
 * never reached would be skipped for ever, and nothing would ever say so.
 */
class PullSync
{
    /** A ceiling on one run, so a first sync of a large shop cannot run for ever. */
    private const MAX_PAGES = 200;

    /**
     * Each connection's shop currency, resolved at most once.
     *
     * Keyed by connection rather than held as one value, because a queue worker
     * syncs several in the same process and a single slot would hand the second
     * shop the first one's currency — silently, and only in production, where
     * more than one connection exists.
     *
     * An empty string means asked and there is none, which is not the same as
     * not yet asked.
     *
     * @var array<int, string>
     */
    private array $storeCurrency = [];

    public function __construct(
        private readonly PlatformRegistry $registry,
        private readonly EntityLinker $linker,
        private readonly TenantContext $tenant,
        private readonly CustomerReconciler $customers,
        private readonly ProductReconciler $products,
        private readonly OrderReconciler $orders,
    ) {}

    /**
     * Bring in everything this connection can give us.
     *
     * Customers before products before orders, on purpose: an order that
     * mentions a customer or a SKU already imported can link to it, and one
     * that arrives first has to invent a placeholder instead.
     *
     * @param  list<string>|null  $entities  null for everything the platform offers
     */
    public function run(Integration $integration, ?array $entities = null, bool $full = false): SyncReport
    {
        $report = new SyncReport;
        $driver = $this->registry->for($integration);

        if (! $driver instanceof PullsRecords) {
            return $report->fail('This connection cannot be read from — it only receives.');
        }

        if (! $integration->isUsable()) {
            return $report->fail('This connection is paused.');
        }

        // Every reconciler below reads the tenant to scope its queries and to
        // stamp account ids. Set from the connection rather than inherited from
        // whatever request or queue job called this.
        $this->openBooks($integration);

        $attempted = [];

        foreach ($entities ?? ['customer', 'product', 'order'] as $entity) {
            if (! $driver->supportsEntity($integration, $entity)) {
                $report->skipEntity($entity, 'not offered by this connection');

                continue;
            }

            $attempted[] = $entity;

            $this->runEntity($integration, $driver, $entity, $report, $full);
        }

        /*
         * A run fails only when every entity did.
         *
         * ── Why one entity is not the whole sync ─────────────────────────────
         *
         * They are read independently and they fail independently. A shop whose
         * product listing times out — a big catalogue, a busy host — still has
         * orders that came back perfectly, and orders are what somebody is
         * actually waiting on. Failing the run over the catalogue discards that:
         * the connection is marked failed, a failure is counted against it, and
         * the screen says something went wrong over a night that imported every
         * order the shop had.
         *
         * Every entity failing is different. That is not four problems, it is
         * one — the shop is down, or the credentials stopped working — and it
         * belongs on the connection.
         */
        if ($report->everyEntityFailed($attempted)) {
            $report->fail(implode(' ', $report->entityErrors()));
        }

        $integration->save();

        $report->failed()
            ? $integration->recordFailure((string) $report->error)
            : $integration->recordSuccess($report->written());

        return $report;
    }

    /**
     * Bring in one record that arrived on its own.
     *
     * What a webhook calls. Deliberately the same path a scheduled sync takes —
     * same mapping, same linking, same status translation, same reconcilers — so
     * an order that arrives by webhook cannot end up differing from the same
     * order arriving by sync. Two routes into the books that disagree is the
     * hardest kind of bug to see, because each one looks right on its own.
     *
     * @param  array<string, mixed>  $payload  exactly as the shop sent it
     */
    public function single(Integration $integration, string $entity, array $payload): SyncReport
    {
        $report = new SyncReport;

        if (! $integration->isUsable()) {
            return $report->fail('This connection is paused.');
        }

        $this->openBooks($integration);

        // The record a webhook brings is the freshest sample there is, so the
        // mapping screen gets its paths from it.
        $integration->rememberPayload($payload, $entity);

        if ($entity === 'order') {
            $integration->rememberStatuses([$this->rawStatus($payload)], 'order');
        }

        $this->one(
            $integration,
            $entity,
            $payload,
            FieldMapSet::for($integration, $entity),
            StatusMap::for($integration, $entity),
            $report,
        );

        $integration->save();

        return $report;
    }

    private function runEntity(
        Integration $integration,
        PullsRecords $driver,
        string $entity,
        SyncReport $report,
        bool $full,
    ): void {
        $maps = FieldMapSet::for($integration, $entity);
        $statuses = StatusMap::for($integration, $entity);
        $since = $full ? null : $this->highWaterMark($integration, $entity);
        $startedAt = CarbonImmutable::now();

        $cursor = null;
        $pages = 0;

        // Whether this run has kept its sample record yet — see below.
        $captured = false;

        do {
            $page = $driver->pull($integration, $entity, $since, $cursor);

            if ($page->failed) {
                // Stopped where it stands, and the mark is not advanced — so the
                // next run asks for the same window rather than stepping over
                // whatever was never read.
                //
                // Recorded against this entity rather than the run: the entities
                // after it are still worth reading. See run().
                $report->entityFailed($entity, (string) $page->message);

                return;
            }

            foreach ($page->records as $record) {
                if (! is_array($record)) {
                    continue;
                }

                /*
                 * One record kept per run, refreshing whatever was there.
                 *
                 * It is what the field mapping screen reads its paths from, so
                 * it must be a real record from this shop — but one is enough,
                 * and re-encoding four hundred of them into a JSON column to end
                 * up with the last is work for nothing.
                 *
                 * Refreshed rather than kept forever: a shop that adds a custom
                 * field this month must not have its paths discovered from a
                 * record captured last year.
                 */
                if (! $captured) {
                    $integration->rememberPayload($record, $entity);
                    $captured = true;
                }

                $this->one($integration, $entity, $record, $maps, $statuses, $report);
            }

            $cursor = $page->next;
            $pages++;
        } while ($page->hasMore() && $pages < self::MAX_PAGES);

        if ($pages >= self::MAX_PAGES && $cursor !== null) {
            // Honest about stopping early rather than reporting a clean finish
            // over a shop that still has pages left.
            $report->note($entity, 'stopped at the page limit; run again to continue');

            return;
        }

        /*
         * Marked from when the run *started*, not from when it ended. A record
         * changed while the sync was working would otherwise fall in the gap
         * between the two and never be seen again.
         */
        $this->markSynced($integration, $entity, $startedAt);
    }

    /**
     * One external record, all the way in.
     *
     * @param  array<string, mixed>  $payload
     */
    private function one(
        Integration $integration,
        string $entity,
        array $payload,
        FieldMapSet $maps,
        StatusMap $statuses,
        SyncReport $report,
    ): void {
        $externalId = $this->externalId($integration, $payload);

        if ($externalId === null) {
            // Nothing to link it by. Importing it would mean a fresh duplicate
            // on every subsequent run, for ever.
            $report->skip($entity, 'no identifier in the record');

            return;
        }

        $mapped = $maps->apply($payload, ['currency' => $this->currencyOf($integration, $payload)]);

        /*
         * Name parts folded into the single field each belongs to, before
         * anything is split or written — so an order arriving by webhook and one
         * arriving by sync compose names identically.
         */
        $mapped = new MappedRecord(
            Names::compose($mapped->attributes),
            $mapped->custom,
            $mapped->skipped,
        );

        // Remembered whatever happens to the record, so the settings screen can
        // offer this shop's own words even for orders that failed to import.
        if ($entity === 'order') {
            $integration->rememberStatuses([$this->rawStatus($payload)], 'order');
        }

        try {
            /*
             * Nothing written by this import is pushed straight back out.
             *
             * ── The circle this prevents ─────────────────────────────────────
             *
             * Importing writes to our tables, and writing to our tables is what
             * tells the catalogue observers to send changes to the shops. Left
             * alone, importing a product from a shop would immediately push
             * that same product back to the shop it came from, which the shop
             * would then report as a change, which we would import again.
             *
             * The fingerprint on the link catches that one lap later. This
             * stops the lap being run at all — cheaper, and far easier to
             * follow when something does go wrong.
             */
            SyncMute::while(fn () => DB::transaction(function () use ($integration, $entity, $payload, $mapped, $statuses, $externalId, $report): void {
                $link = $this->linker->find($integration, $entity, $externalId);
                $localId = $link?->linkable_id;

                // No link yet — is this something we already hold under another
                // name? Asked only now, because a link outranks any resemblance.
                if ($localId === null) {
                    $localId = $this->linker->match($integration, $entity, $mapped, $payload);
                }

                /*
                 * An order somebody threw away stays thrown away.
                 *
                 * The shop still has it and will keep offering it on every sync.
                 * Re-importing would quietly undo a deliberate act; inserting a
                 * second one is refused by the unique index on the number. So it
                 * is left alone and said out loud, which is the only one of the
                 * three that a person can act on.
                 */
                if ($entity === IntegrationLink::ORDER && $localId !== null
                    && Order::withTrashed()->whereKey($localId)->value('deleted_at') !== null) {
                    /*
                     * Linked even so, before leaving.
                     *
                     * ── Why identity is not data ─────────────────────────────
                     *
                     * That our order is the shop's 9580 stays true whether or
                     * not we take this sync's changes. Skipping the import is
                     * the deliberate act; forgetting which order it is was an
                     * accident of returning early, and it cost more than the
                     * skip saved.
                     *
                     * Without the link a restored order looks, to a push, like
                     * an order the shop has never seen — so the push creates a
                     * second one rather than updating the first. The shop ends
                     * up holding a duplicate, and the local order is then bound
                     * to the duplicate instead of the original.
                     *
                     * $localId is already known here: either the link was found
                     * or it was matched above, so this writes what we resolved
                     * rather than guessing.
                     */
                    $this->linker->link($integration, $entity, $localId, $externalId);

                    $report->skip($entity, "This order is in the trash here, so the shop's copy was left alone. Restore it to start syncing it again.");

                    return;
                }

                $existed = $localId !== null;

                $model = match ($entity) {
                    IntegrationLink::CUSTOMER => $this->customers->apply($integration, $mapped, $payload, $localId),
                    IntegrationLink::PRODUCT => $this->products->apply($integration, $mapped, $payload, $localId),
                    IntegrationLink::ORDER => $this->orders->apply(
                        $integration,
                        new MappedRecord(self::orderPart($mapped->attributes), $mapped->custom, $mapped->skipped),
                        $payload,
                        $localId,
                        $this->customerFor($integration, $payload, $mapped),
                        $statuses->translate($this->rawStatus($payload)),
                    ),
                    default => null,
                };

                if ($model === null) {
                    $report->skip($entity, 'could not be turned into a record');

                    return;
                }

                $link = $this->linker->link(
                    $integration,
                    $entity,
                    (int) $model->id,
                    $externalId,
                    $this->externalReference($payload),
                );

                // Custom fields belong to the link, not the record — the same
                // product in two shops can carry a different delivery slot in
                // each, and on the link that is simply two rows.
                if ($mapped->custom !== []) {
                    $link->mergeCustom($mapped->custom);
                }

                $link->forceFill(['last_pulled_at' => now()])->save();

                $existed ? $report->updated($entity) : $report->created($entity);
            }));
        } catch (\Throwable $e) {
            // Counted and carried, not thrown. One unusable record out of four
            // hundred must not cost the other three hundred and ninety-nine.
            $report->skip($entity, $e->getMessage());
        }
    }

    /**
     * The customer an order belongs to.
     *
     * Read by running the *customer* mapping over the *order* payload, which
     * works because every one of these platforms repeats the buyer's details on
     * the order itself. That reuse is why a shop's orders can be imported
     * without its customer list, and why the customer that results obeys the
     * same mapping as one imported directly.
     *
     * @param  array<string, mixed>  $payload
     */
    private function customerFor(Integration $integration, array $payload, MappedRecord $order): ?int
    {
        /*
         * The buyer's details as mapped on the order, where a shop actually
         * sends them. Anything the order map did not cover falls back to the
         * customer map, so a connection configured before this still works.
         */
        $fromOrder = self::customerPart($order->attributes);

        $mapped = $fromOrder === []
            ? FieldMapSet::for($integration, 'customer')->apply($payload)
            : new MappedRecord($fromOrder);

        // Nothing identifying in the order — a guest checkout with no email or
        // phone. Better an order with no customer than one filed against
        // somebody it does not belong to.
        if (($mapped->attributes['email'] ?? '') === '' && ($mapped->attributes['phone'] ?? '') === '') {
            return null;
        }

        $existing = $this->linker->match($integration, 'customer', $mapped, $payload);
        $customer = $this->customers->apply($integration, $mapped, $payload, $existing);

        return $customer?->id;
    }

    /**
     * The currency this particular record is denominated in.
     *
     * ── Currency ALWAYS from Payload (Store's Actual Currency) ───────────────
     *
     * Orders arrive with amounts in the store's actual currency. If WooCommerce
     * sends {"currency": "BDT", "total": 131}, that's BDT 131.00, NOT USD 131.00
     * even if storefront is configured as USD.
     *
     * **Priority:**
     * 1. Payload currency (what the store actually sent)
     * 2. Storefront currency (fallback if payload has no currency)
     * 3. Business base currency (final fallback)
     *
     * **Why Payload First?**
     * - Preserves accurate currency labeling
     * - Store sends BDT 131 → We save BDT 131 → We display BDT ৳131.00
     * - Prevents misinterpretation (BDT amount shown as USD)
     *
     * **Storefront Currency Role:**
     * - Auto-detected and saved for convenience
     * - Used as fallback when payload has no currency field
     * - Helps with filtering and reporting
     * - But NEVER overrides payload currency
     *
     * @param  array<string, mixed>  $payload
     */
    private function currencyOf(Integration $integration, array $payload): string
    {
        // PRIORITY 1: Detect currency from payload FIRST
        $detected = null;
        foreach (['currency', 'currency_code', 'presentment_currency', 'orderTotal.unit'] as $path) {
            $value = FieldPath::resolve($payload, $path);

            if (is_string($value) && mb_strlen(trim($value)) === 3) {
                $detected = mb_strtoupper(trim($value));
                break;
            }
        }

        // If payload has currency, use it and auto-set storefront if empty
        if ($detected !== null) {
            // Convenience: Auto-set storefront currency from first order
            if ($integration->storefront_id !== null) {
                try {
                    $updated = Storefront::query()
                        ->withoutGlobalScopes()
                        ->whereKey($integration->storefront_id)
                        ->whereNull('currency') // Only if not set
                        ->update(['currency' => $detected]);

                    if ($updated > 0) {
                        \Log::info('Auto-set storefront currency from order payload', [
                            'integration_id' => $integration->id,
                            'storefront_id' => $integration->storefront_id,
                            'currency' => $detected,
                        ]);

                        unset($this->storeCurrency[(int) $integration->id]);
                    }
                } catch (\Throwable $e) {
                    \Log::error('Failed to auto-set storefront currency', [
                        'integration_id' => $integration->id,
                        'currency' => $detected,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return $detected;
        }

        // PRIORITY 2: Storefront currency (fallback if payload has no currency)
        $storefront = $this->storeCurrency($integration);
        if ($storefront !== null) {
            return $storefront;
        }

        // PRIORITY 3: Business base currency (final fallback)
        return (string) ($integration->business?->base_currency ?? 'USD');
    }

    /**
     * What the shop this connection feeds is set to sell in.
     *
     * Read once per run rather than per record: a sync of four hundred orders
     * must not be four hundred queries for a value that cannot change while it
     * is running. Null means there is no storefront, or it has no currency —
     * both of which send currencyOf() on to the payload.
     *
     * Empty string means asked and the storefront exists but has no currency
     * configured — this triggers auto-detection.
     */
    private function storeCurrency(Integration $integration): ?string
    {
        $id = (int) $integration->id;

        if (! array_key_exists($id, $this->storeCurrency)) {
            if ($integration->storefront_id === null) {
                $this->storeCurrency[$id] = '';

                return null;
            }

            $currency = Storefront::query()
                ->withoutGlobalScopes()
                ->whereKey($integration->storefront_id)
                ->value('currency');

            // NULL or empty string both mean "not configured yet"
            $currency = is_string($currency) ? mb_strtoupper(trim($currency)) : '';

            // Store empty string to indicate "checked but needs detection"
            $this->storeCurrency[$id] = (mb_strlen($currency) === 3) ? $currency : '';
        }

        return $this->storeCurrency[$id] === '' ? null : $this->storeCurrency[$id];
    }

    /**
     * The shop's own id for this record.
     *
     * @param  array<string, mixed>  $payload
     */
    private function externalId(Integration $integration, array $payload): ?string
    {
        $configured = trim((string) $integration->config('external_id_path', ''));

        $paths = $configured !== ''
            ? [$configured]
            // The conventions, in the order they are worth trying. A bespoke
            // site that uses none of them says so in its configuration.
            : ['id', '_id', 'orderId', 'order_id', 'uuid', 'reference'];

        foreach ($paths as $path) {
            $value = FieldPath::resolve($payload, $path);

            if ($value !== null && $value !== '' && ! is_array($value)) {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * The reference a person would recognise — '#1001', a SKU.
     *
     * @param  array<string, mixed>  $payload
     */
    private function externalReference(array $payload): ?string
    {
        foreach (['number', 'name', 'sku', 'orderId', 'email'] as $path) {
            $value = FieldPath::resolve($payload, $path);

            if ($value !== null && $value !== '' && ! is_array($value)) {
                return mb_substr((string) $value, 0, 191);
            }
        }

        return null;
    }

    /**
     * The customer half of a mapped order, unprefixed.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private static function customerPart(array $attributes): array
    {
        $customer = [];

        foreach ($attributes as $key => $value) {
            if (str_starts_with($key, 'customer.')) {
                $customer[mb_substr($key, 9)] = $value;
            }
        }

        return $customer;
    }

    /**
     * The order's own half — everything that is not the buyer.
     *
     * Split out because `customer.email` is a real mapping target but not an
     * order column: filling it would throw on mass assignment, and quietly
     * dropping it would leave the mapping looking configured and doing nothing.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private static function orderPart(array $attributes): array
    {
        return array_filter(
            $attributes,
            fn (string $key): bool => ! str_starts_with($key, 'customer.'),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /** @param array<string, mixed> $payload */
    private function rawStatus(array $payload): string
    {
        foreach (['status', 'financial_status', 'orderStatus', 'state'] as $path) {
            $value = FieldPath::resolve($payload, $path);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * Only what changed since last time, where the platform can answer that.
     *
     * A connection that cannot filter by date re-reads everything every run —
     * which is why its capabilities say supportsIncremental is false, and why
     * that is worth knowing before somebody schedules it hourly.
     */
    private function highWaterMark(Integration $integration, string $entity): ?CarbonImmutable
    {
        $at = $integration->syncSetting('high_water.'.$entity);

        if ($at === null) {
            return null;
        }

        try {
            // Overlapped by a minute. A record saved on the boundary second, or
            // a shop whose clock is slightly out, would otherwise sit exactly in
            // the gap between two runs and never be read.
            return CarbonImmutable::parse((string) $at)->subMinute();
        } catch (\Throwable) {
            return null;
        }
    }

    private function markSynced(Integration $integration, string $entity, CarbonImmutable $at): void
    {
        $settings = $integration->sync_settings ?? [];
        data_set($settings, 'high_water.'.$entity, $at->utc()->toIso8601String());
        $integration->sync_settings = $settings;
    }

    /**
     * Put this connection's business in front of everything that follows.
     *
     * Without it the reconcilers write with whatever tenant the caller happened
     * to have — which for a queued job is none, and for a request is whichever
     * business the user was looking at.
     */
    private function openBooks(Integration $integration): void
    {
        $business = $integration->business;

        if ($business !== null) {
            $this->tenant->setAccount($business->account);
            $this->tenant->setBusiness($business);
        }
    }
}
