<?php

declare(strict_types=1);

namespace App\Http\Api\V1;

use App\Domain\Integrations\Contracts\ListsStatuses;
use App\Domain\Integrations\Contracts\PullsRecords;
use App\Domain\Integrations\Models\Integration;
use App\Domain\Integrations\PlatformRegistry;
use App\Domain\Integrations\PullSync;
use App\Domain\Integrations\StorefrontCurrencyDetector;
use App\Domain\Integrations\Support\CustomFields;
use App\Domain\Integrations\Support\DemoPayloads;
use App\Domain\Integrations\Support\EntityFields;
use App\Domain\Integrations\Support\FieldMap;
use App\Domain\Integrations\Support\FieldMapSet;
use App\Domain\Integrations\Support\FieldPath;
use App\Domain\Integrations\Support\PlatformSchema;
use App\Domain\Integrations\Support\StatusMap;
use App\Domain\Integrations\Support\Transform;
use App\Domain\Integrations\WebhookProvisioner;
use App\Domain\Storefront\StorefrontService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Storefront;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The connections a business has to the shops it sells through.
 *
 * ── Why credentials only ever travel one way ─────────────────────────────────
 *
 * Nothing here ever returns a secret. A connection is read back through
 * safeConfiguration(), which replaces every field the driver declared secret
 * with a marker saying it is set. A screen that renders somebody's API secret
 * back to them has put it in a page cache, a screenshot and a support ticket,
 * and there is no reason to: the only thing a person needs to know about a
 * saved secret is whether one is there.
 *
 * The consequence is that an update carrying a blank secret means "leave it
 * alone" rather than "clear it" — otherwise every save from a form that could
 * not show the value would wipe it.
 */
class IntegrationsEndpoint
{
    public function __construct(
        private readonly PlatformRegistry $registry,
        private readonly TenantContext $tenant,
    ) {}

    /** Every connection this business has, with its secrets masked. */
    public function index(): JsonResponse
    {
        $businessId = $this->businessId();

        $connections = Integration::query()
            ->where('business_id', $businessId)
            ->with(['storefront:id,public_id,name', 'business'])
            ->orderBy('name')
            ->get()
            ->map(fn (Integration $i): array => $this->present($i))
            ->all();

        return response()->json([
            'data' => $connections,

            // The shops available to attach a connection to, so the form can
            // offer them rather than asking somebody to know an id.
            'stores' => $this->stores($businessId),
        ]);
    }

    /** @return list<array{id: string, name: string}> */
    private function stores(int $businessId): array
    {
        return Storefront::query()
            ->where('business_id', $businessId)
            ->orderBy('name')
            ->get(['public_id', 'name'])
            ->map(fn (Storefront $s): array => ['id' => $s->public_id, 'name' => $s->name])
            ->all();
    }

    /**
     * Which of our shops this connection feeds.
     *
     * ── Why this is asked at connect time and not later ──────────────────────
     *
     * Because everything downstream depends on it. An order arriving with no
     * storefront is indistinguishable from a sale taken at the counter — it
     * reads as "Walk-in" on every screen, cannot be filtered by shop, and its
     * revenue cannot be attributed to the channel that earned it. Left optional
     * "for now", every order imported before somebody notices is wrong, and
     * fixing them afterwards means guessing.
     *
     * A shop can be created here rather than sending somebody to another screen
     * first: the connection already knows its own name, and being made to leave
     * a half-filled form to go and create a prerequisite is how people abandon
     * setup.
     *
     * @param  array<string, mixed>  $input
     */
    private function resolveStorefront(array $input, int $businessId): ?int
    {
        $given = trim((string) ($input['storefront_id'] ?? ''));

        if ($given !== '') {
            $id = Storefront::query()
                ->where('business_id', $businessId)
                ->where('public_id', $given)
                ->value('id');

            // A public id that is not this business's own. Refused rather than
            // ignored — silently attaching to nothing is how the walk-in bug
            // happens in the first place.
            abort_if($id === null, 422, 'That shop does not belong to this business.');

            return (int) $id;
        }

        $create = trim((string) ($input['new_store_name'] ?? ''));

        if ($create === '') {
            return null;
        }

        $slug = Str::slug($create) ?: 'shop';
        $suffix = 2;

        while (Storefront::query()->where('business_id', $businessId)->where('slug', $slug)->exists()) {
            $slug = Str::slug($create).'-'.$suffix++;
        }

        /*
         * Through the service that already knows how to build one.
         *
         * A storefront has two dozen columns with sensible defaults —
         * currency, payment methods, theme, caching — and half of them are NOT
         * NULL. Creating one here with three fields fails on the fourth column,
         * and getting it right by hand would mean copying those defaults into a
         * second place for them to drift apart.
         */
        return (int) app(StorefrontService::class)
            ->createStorefront(['name' => $create, 'slug' => $slug])
            ->id;
    }

    /** What can be connected, and what each one needs. Built from the drivers. */
    public function catalogue(): JsonResponse
    {
        return response()->json([
            'data' => $this->registry->catalogue(),
            // What can be connected, before which platform — see PlatformRegistry.
            'kinds' => $this->registry->kinds(),
        ]);
    }

    /**
     * Try credentials before anything is saved.
     *
     * Deliberately available for an unsaved connection: being told the key is
     * wrong while the form is still open is worth far more than being told
     * after a row exists and a sync has failed overnight.
     */
    public function test(Request $request): JsonResponse
    {
        $provider = (string) $request->input('provider');

        if (! $this->registry->supports($provider)) {
            return response()->json(['message' => 'That platform is not one this application knows.'], 422);
        }

        $integration = new Integration(['provider' => $provider]);
        $integration->configuration = $this->configurationFor(
            $provider,
            (array) $request->input('configuration', []),
            $request->filled('id') ? $this->find((string) $request->input('id')) : null,
        );

        $result = $this->registry->driver($provider)->testConnection($integration);

        return response()->json([
            'ok' => $result->ok,
            'message' => $result->message,
        ], $result->ok ? 200 : 422);
    }

    public function store(Request $request, WebhookProvisioner $provisioner, StorefrontCurrencyDetector $currencyDetector): JsonResponse
    {
        $provider = (string) $request->input('provider');

        if (! $this->registry->supports($provider)) {
            return response()->json(['message' => 'That platform is not one this application knows.'], 422);
        }

        $businessId = $this->businessId();

        /*
         * What this connection is for. Chosen in step one of the form, and it
         * decides everything after it — which platforms are offered, and whether
         * a storefront is asked for at all. A courier has no storefront.
         */
        $kind = $this->registry->kindOf($provider);

        if (in_array((string) $request->input('kind'), ['store', 'courier', 'other'], true)) {
            $kind = (string) $request->input('kind');
        }

        $validated = $request->validate([
            'kind' => ['nullable', Rule::in(['store', 'courier', 'other'])],
            'name' => ['required', 'string', 'max:120'],
            'storefront_id' => ['nullable', 'string', 'max:64'],
            'new_store_name' => ['nullable', 'string', 'max:120'],
            'bidirectional' => ['boolean'],
            ...$this->registry->rules($provider),
        ]);

        $integration = Integration::query()->create([
            'account_id' => $this->tenant->accountId(),
            'business_id' => $businessId,
            'type' => $kind,
            'storefront_id' => $kind === 'store' ? $this->resolveStorefront($validated, $businessId) : null,
            'name' => $validated['name'],
            'provider' => $provider,
            'configuration' => (array) $request->input('configuration', []),
            // Default to bidirectional (both ways) so orders/products sync in both directions:
            // - Pull from platform (import new orders/products)
            // - Push to platform (export updates and new items created here)
            'bidirectional' => (bool) ($validated['bidirectional'] ?? true),
            'status' => Integration::STATUS_ACTIVE,
            'is_active' => true,
            'created_by' => $request->user()?->id,
        ]);

        /*
         * Auto-detect and set storefront currency from the platform.
         *
         * ── Why this runs here ───────────────────────────────────────────────
         *
         * When a user connects a WooCommerce or Shopify store, we can fetch the
         * store's configured currency directly from its API. This means:
         * - No manual currency setup required
         * - Currency is always accurate (matches the actual store settings)
         * - Works immediately on first sync
         *
         * Best-effort like webhooks: if it fails, the integration is still
         * created successfully, and the user can set the currency manually.
         */
        $currency = null;

        if ($integration->storefront_id !== null && $currencyDetector->supported($integration)) {
            try {
                $currencyResult = $currencyDetector->detectAndSet($integration);
                $currency = $currencyResult['set'] ? $currencyResult['currency'] : null;
            } catch (\Throwable $e) {
                \Log::warning('Failed to auto-detect currency during integration creation', [
                    'integration_id' => $integration->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        /*
         * Wire the shop up to call us, here, while somebody is watching.
         *
         * ── Why this is not left for later ───────────────────────────────────
         *
         * A connection that pulls but is not called is the failure nobody
         * catches. It tests green, it syncs when asked, and it silently misses
         * every order placed between one manual sync and the next — which,
         * for a shop, is most of them.
         *
         * Best-effort rather than required: the connection itself is saved and
         * valid either way, and a shop whose key turns out to be read-only
         * should get a connection and a warning, not a failed save and no
         * record of the credentials just typed in. The result rides along so
         * the screen can say which it was.
         */
        $webhooks = null;

        if ($provisioner->supported($integration)) {
            try {
                $webhooks = $provisioner->reconcile($integration);
            } catch (\Throwable $e) {
                $webhooks = ['ok' => false, 'message' => $e->getMessage()];
            }
        }

        return response()->json([
            'data' => $this->present($integration->refresh()),
            'webhooks' => $webhooks,
            'currency' => $currency,
        ], 201);
    }

    public function update(Request $request, string $id, StorefrontCurrencyDetector $currencyDetector): JsonResponse
    {
        $integration = $this->find($id);
        $provider = (string) $integration->provider;

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'storefront_id' => ['nullable', 'string', 'max:64'],
            'new_store_name' => ['nullable', 'string', 'max:120'],
            'is_active' => ['boolean'],
            'bidirectional' => ['boolean'],
            'status' => ['sometimes', Rule::in([Integration::STATUS_ACTIVE, Integration::STATUS_PAUSED])],
        ]);

        $integration->fill($request->only(['name', 'is_active', 'bidirectional', 'status']));

        $storefrontChanged = false;

        // Only touched when the form actually sent something about the shop, so
        // saving a renamed connection cannot detach it from its storefront.
        if ($request->has('storefront_id') || $request->filled('new_store_name')) {
            $oldStorefrontId = $integration->storefront_id;
            $integration->storefront_id = $this->resolveStorefront($validated, (int) $integration->business_id);
            $storefrontChanged = $oldStorefrontId !== $integration->storefront_id;
        }

        if ($request->has('configuration')) {
            $integration->configuration = $this->configurationFor(
                $provider,
                (array) $request->input('configuration', []),
                $integration,
            );
        }

        $integration->save();

        /*
         * Auto-detect currency when storefront changes.
         *
         * If the user just assigned a storefront to this integration, try to
         * auto-detect and set its currency from the platform.
         */
        $currency = null;

        if ($storefrontChanged && $integration->storefront_id !== null && $currencyDetector->supported($integration)) {
            try {
                $currencyResult = $currencyDetector->detectAndSet($integration);
                $currency = $currencyResult['set'] ? $currencyResult['currency'] : null;
            } catch (\Throwable $e) {
                \Log::warning('Failed to auto-detect currency during integration update', [
                    'integration_id' => $integration->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'data' => $this->present($integration->fresh()),
            'currency' => $currency,
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        $this->find($id)->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    /** Read this shop now, rather than waiting for the schedule. */
    public function sync(Request $request, string $id, PullSync $sync): JsonResponse
    {
        $integration = $this->find($id);

        $report = $sync->run(
            $integration,
            $request->filled('entity') ? [(string) $request->input('entity')] : null,
            (bool) $request->boolean('full'),
        );

        /*
         * A sync that could not reach the shop answers 422 with the reason as
         * `message`.
         *
         * Without the top-level message the client had only a status code — the
         * driver's actual sentence sat inside data.error and was never shown, so
         * every failure looked like the same anonymous red toast. The reason is
         * the entire value of the attempt.
         */
        if ($report->failed()) {
            return response()->json([
                'message' => $report->error,
                'data' => $report->toArray(),
            ], 422);
        }

        return response()->json(['data' => $report->toArray()]);
    }

    /**
     * Whether this shop is actually set up to call us.
     *
     * Read separately from the connection itself because it costs a round trip
     * to the shop, and the list of connections should not wait on four of them.
     */
    public function webhooks(string $id, WebhookProvisioner $provisioner): JsonResponse
    {
        return response()->json(['data' => $provisioner->status($this->find($id))]);
    }

    /**
     * Create what is missing and repair what has drifted.
     *
     * ── Why this is a button and not a page of instructions ──────────────────
     *
     * Setting a webhook up by hand means creating one per event in the shop's
     * admin, pasting a delivery URL, inventing a secret, and pasting that same
     * secret into a second screen here. Every step is a place to make a silent
     * mistake, and the symptom of any of them is identical: everything looks
     * connected and no orders arrive.
     *
     * It also does not stay done. The delivery URL changes when this
     * application moves; the shop disables a webhook of its own accord after a
     * run of failures. Both are invisible from here and both are repaired by
     * pressing this again.
     */
    public function repairWebhooks(Request $request, string $id, WebhookProvisioner $provisioner): JsonResponse
    {
        $integration = $this->find($id);

        if (! $provisioner->supported($integration)) {
            return response()->json([
                'message' => 'This platform cannot have its webhooks set up from here.',
            ], 422);
        }

        // Rotation is asked for explicitly. Quietly minting a new secret on
        // every repair would mean the previous-secret window covers a
        // rotation nobody wanted, for no benefit.
        $result = $provisioner->reconcile($integration, $request->boolean('rotate'));

        // The shop refusing to write is almost always one thing — an API key
        // with read permission only — and saying so beats a red toast.
        return response()->json([
            'message' => $result['message'],
            'data' => $result,
        ], $result['ok'] ? 200 : 422);
    }

    /**
     * Everything the field mapping screen needs for one entity.
     *
     * ── Where the paths come from ────────────────────────────────────────────
     *
     * The last real record this shop sent, when there is one — so the paths
     * offered include the custom fields this shop's own plugins added, which no
     * documentation anywhere lists. Failing that, the platform's documented
     * sample, so mapping can be set up the afternoon a shop is connected rather
     * than waiting on a first sale.
     *
     * Which of the two it was is reported, because a mapping built against a
     * sample deserves a second look once real records arrive.
     */
    public function samplePaths(Request $request, string $id): JsonResponse
    {
        $integration = $this->find($id);

        $entity = (string) $request->query('entity', 'order');

        if (! EntityFields::isEntity($entity)) {
            return response()->json(['message' => 'There is no such kind of record.'], 422);
        }

        $live = $integration->lastPayload($entity);
        $payload = $live ?? DemoPayloads::for((string) $integration->provider, $entity) ?? [];

        /*
         * What the shop says about itself, on top of what it has sent.
         *
         * These answer different questions and neither replaces the other. The
         * record knows which custom fields this shop's plugins invented, which
         * no schema anywhere lists. The schema knows every standard field —
         * including the ones that happen to be empty on the record, which is
         * how a shop with no coupon on its last order ends up unable to map
         * coupons at all.
         */
        $described = $this->describe($integration, $entity);

        /*
         * A connection that predates remembering starts its memory from the
         * record it already has, rather than from nothing. Otherwise the first
         * person to open this screen after the change sees fewer fields than
         * before it, which is a strange way to deliver an improvement.
         */
        if ($live !== null && $integration->seenFields($entity) === []) {
            $integration->rememberFieldsOnly($live, $entity);
            $integration->saveQuietly();
        }

        $paths = [];
        $seen = [];

        foreach (FieldPath::flatten($payload) as $path => $sample) {
            $field = $described[$path] ?? null;
            $seen[$path] = true;

            $paths[] = [
                'path' => $path,
                // Trimmed: a description field can run to a page, and this is a
                // dropdown label rather than the record itself.
                'sample' => is_scalar($sample) ? Str::limit((string) $sample, 60) : null,
                'label' => $field?->label,
                'readonly' => $field?->readonly ?? false,
                'suggest' => $field?->transform(),
                'choices' => $field?->choices() ?? [],
                'note' => $field?->description,
            ];
        }

        /*
         * Then the custom fields this shop has sent before but not this time.
         *
         * Without this the list changes with every order that arrives: an order
         * placed on the website carries a delivery slot, one taken over the
         * phone does not, and whichever landed most recently decides whether
         * anybody can map delivery slots today. Remembered keys make the screen
         * the same screen each time it is opened.
         */
        foreach ($integration->seenFields($entity) as $path => $example) {
            if (isset($seen[$path])) {
                continue;
            }

            $seen[$path] = true;

            $paths[] = [
                'path' => $path,
                'sample' => is_scalar($example) ? Str::limit((string) $example, 60) : null,
                'label' => null,
                'readonly' => false,
                'suggest' => null,
                'choices' => [],
                'note' => 'Sent by this shop before, though not on the record read here.',
            ];
        }

        /*
         * Then the described fields no record showed.
         *
         * Marked so the screen can say why they have no value beside them —
         * "this shop has never had a coupon" reads very differently from "this
         * field is broken", and without the distinction the second is assumed.
         */
        foreach ($described as $path => $field) {
            if (isset($seen[$path])) {
                continue;
            }

            $paths[] = [
                'path' => $path,
                'sample' => null,
                'label' => $field->label,
                'readonly' => $field->readonly,
                'suggest' => $field->transform(),
                'choices' => $field->choices(),
                'note' => $field->description,
                'unused' => true,
            ];
        }

        return response()->json([
            'data' => [
                'entity' => $entity,
                'paths' => $paths,
                'source' => $live !== null ? 'live' : ($payload === [] ? 'none' : 'sample'),

                // Whether this shop was able to describe itself, so the screen
                // can offer a refresh rather than leaving somebody wondering
                // why another shop lists more fields than theirs.
                'described' => $described !== [],
                'captured_at' => $integration->lastPayloadAt($entity),

                // What a mapping may point at, and how a value may be treated.
                // Sent with the paths so the screen needs one request, not three.
                /*
                 * The built-in fields, then the ones this business named for
                 * itself. Both in one list because to somebody mapping a shop
                 * they are the same thing: a place for a value to land.
                 */
                'targets' => [
                    ...EntityFields::options($entity),
                    ...array_map(
                        fn (array $field): array => [
                            'value' => 'custom.'.$field['key'],
                            'label' => $field['label'],
                            'transform' => $field['type'],
                            'custom' => true,
                        ],
                        CustomFields::for($integration->business, $entity),
                    ),
                ],
                'transforms' => Transform::options(),

                /*
                 * Which types the screen has to ask more about.
                 *
                 * Sent rather than repeated in the front end, so that adding a
                 * type here is the whole change — a list duplicated in
                 * TypeScript is a list that drifts the first time one is added.
                 */
                'needs_options' => Transform::NEEDS_OPTIONS,
                'media_types' => Transform::MEDIA,

                'maps' => FieldMapSet::for($integration, $entity)->toArray(),
            ],
        ]);
    }

    /**
     * What this shop says about its own fields, read at most once a day.
     *
     * ── Why it is cached ─────────────────────────────────────────────────────
     *
     * It is a network round trip to somebody else's shop, and the field mapping
     * screen is opened repeatedly while a mapping is worked out. A schema
     * changes when a plugin is installed or WooCommerce is updated — measured
     * in months — so fetching it on every page open would spend a second of
     * somebody's time to learn nothing, several times an hour.
     *
     * Stored against the integration rather than in the cache store, because it
     * describes that shop rather than this request, and a cache flush should
     * not silently shrink the list of fields somebody can map.
     *
     * @return array<string, PlatformSchema>
     */
    private function describe(Integration $integration, string $entity, bool $refresh = false): array
    {
        $key = 'field_schema.'.$entity;
        $stored = data_get($integration->metadata ?? [], $key);

        $fresh = is_array($stored)
            && filled($stored['at'] ?? null)
            && now()->diffInHours(Carbon::parse($stored['at']), true) < 24;

        if (! $refresh && $fresh) {
            return PlatformSchema::mappable(PlatformSchema::flatten(
                PlatformSchema::fromJsonSchema($stored['properties'] ?? []),
            ));
        }

        $driver = $this->registry->driver((string) $integration->provider);

        // Only some platforms can answer. The screen worked before any of them
        // could, and works unchanged for the ones that cannot.
        $described = method_exists($driver, 'describeFields')
            ? $driver->describeFields($integration, $entity)
            : null;

        if ($described === null) {
            /*
             * Keep whatever was stored before.
             *
             * A shop that answered last week and is briefly unreachable today
             * should not lose every field it described — the list would shrink
             * without explanation and mappings would look as though they point
             * at nothing.
             */
            return is_array($stored)
                ? PlatformSchema::mappable(PlatformSchema::flatten(PlatformSchema::fromJsonSchema($stored['properties'] ?? [])))
                : [];
        }

        $metadata = $integration->metadata ?? [];
        data_set($metadata, $key, ['at' => now()->toIso8601String(), 'properties' => $described]);

        /*
         * Saved without touching updated_at.
         *
         * Reading a schema is this application looking something up, not the
         * shop changing — and every screen that shows when a connection was
         * last touched would otherwise report a change that nobody made, every
         * day, for ever.
         */
        $integration->metadata = $metadata;
        $integration->saveQuietly();

        return PlatformSchema::mappable(PlatformSchema::flatten(PlatformSchema::fromJsonSchema($described)));
    }

    /**
     * Save the field maps for one entity.
     *
     * Validated against the same allowlist the sync obeys, because these end up
     * written into columns — a mapping naming `account_id` would otherwise be a
     * subscriber moving records between tenants.
     */
    public function saveFieldMaps(Request $request, string $id): JsonResponse
    {
        $integration = $this->find($id);

        $entity = (string) $request->input('entity', 'order');

        if (! EntityFields::isEntity($entity)) {
            return response()->json(['message' => 'There is no such kind of record.'], 422);
        }

        $clean = [];
        $refused = [];
        $collided = [];

        /*
         * Keyed by target while building, because that is how it will be read
         * back and there is no point storing a row that loading will discard.
         *
         * A target holds one rule. Two rows aimed at the same one is a genuine
         * conflict with no sensible resolution, and it happens easily: every
         * WordPress shop writes its custom fields twice, plainly and with a
         * leading underscore, and both spellings reduce to the same target.
         *
         * The old code wrote both and let the reader silently keep whichever
         * came last. What somebody saw was a row they had just added quietly
         * replacing one that was already working — no error, no warning, and
         * the evicted field then offered back to them as though it were new.
         */
        foreach ((array) $request->input('maps', []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $map = FieldMap::fromArray($row, $entity);

            // isUsable checks the target against EntityFields and the entity
            // against the known list — the same gate the sync applies, so a row
            // that would be ignored at sync time is refused at save time
            // instead of sitting in the settings looking configured.
            if (! $map->isUsable()) {
                $refused[] = $map->source !== '' ? $map->source : '(blank row)';

                continue;
            }

            if (isset($clean[$map->target])) {
                $collided[] = $map->source.' and '.$clean[$map->target]['source'];

                continue;
            }

            $clean[$map->target] = $map->toArray();
        }

        $mappings = $integration->field_mappings ?? [];
        $mappings[$entity] = array_values($clean);
        $integration->field_mappings = $mappings;
        $integration->save();

        return response()->json([
            'data' => FieldMapSet::for($integration->fresh(), $entity)->toArray(),

            /*
             * What was not kept, and why.
             *
             * A save that quietly stores less than it was given is the whole
             * defect this replaces. Reported rather than logged, because the
             * only person who can resolve a conflict between two of their own
             * mappings is the one looking at the screen.
             */
            'skipped' => [
                'refused' => array_values(array_unique($refused)),
                'collided' => array_values(array_unique($collided)),
            ],
        ]);
    }

    /**
     * Define a field this tool does not ship with.
     *
     * Named in words — "Manage Stock" — and keyed from that, so every shop's own
     * spelling can be pointed at one field rather than each landing under
     * whatever it happened to arrive as.
     */
    public function addField(Request $request, string $id): JsonResponse
    {
        $integration = $this->find($id);

        $validated = $request->validate([
            'label' => ['required', 'string', 'max:60'],
            'entity' => ['required', 'string'],
            'type' => ['nullable', 'string', 'max:40'],
        ]);

        $business = $this->tenant->business();

        $key = $business === null ? null : CustomFields::add(
            $business,
            (string) $validated['entity'],
            (string) $validated['label'],
            (string) ($validated['type'] ?? 'trim'),
        );

        if ($key === null) {
            return response()->json(['message' => 'That field could not be added.'], 422);
        }

        return response()->json(['data' => ['key' => $key, 'target' => 'custom.'.$key]], 201);
    }

    /** Their statuses on one side, ours on the other. */
    public function statusMap(Request $request, string $id): JsonResponse
    {
        $integration = $this->find($id);
        $entity = (string) $request->input('entity', 'order');

        /*
         * Ask the shop what statuses it has, while somebody is looking at the
         * screen that needs them.
         *
         * ── Why here and not on a schedule ───────────────────────────────────
         *
         * This is the only moment the answer matters, and it is the moment it
         * must be current: somebody has come to map a status, and the reason
         * they came is usually that a new one exists. A nightly refresh would
         * mean installing a plugin in the morning and being unable to map its
         * status until tomorrow, with nothing on screen explaining why.
         *
         * One call, and a failure costs nothing — the last known list is kept
         * and the screen renders from that. See rememberPlatformStatuses.
         */
        $driver = $this->registry->for($integration);

        if ($driver instanceof ListsStatuses) {
            try {
                $integration->rememberPlatformStatuses($driver->platformStatuses($integration, $entity), $entity);
            } catch (\Throwable) {
                // The shop is unreachable. Not worth failing the screen over.
            }
        }

        return response()->json(['data' => StatusMap::for($integration->refresh(), $entity)->catalogue()]);
    }

    public function saveStatusMap(Request $request, string $id): JsonResponse
    {
        $integration = $this->find($id);

        StatusMap::store($integration, (array) $request->input('rules', []));
        $integration->save();

        return response()->json(['data' => StatusMap::for($integration->fresh())->catalogue()]);
    }

    /**
     * Add a status to this tool's own list.
     *
     * To ours, never to theirs — a shop's statuses are whatever its software
     * sends, and inventing one here would produce a mapping to a word the shop
     * has never heard of.
     */
    public function addStatus(Request $request, string $id): JsonResponse
    {
        $integration = $this->find($id);

        $request->validate(['label' => ['required', 'string', 'max:40']]);

        $business = $this->tenant->business();
        $key = $business === null ? null : OrderStatuses::add($business, (string) $request->input('label'));

        // Null means it collided with a built-in. Refused with a reason rather
        // than silently doing nothing, since the row would simply not appear.
        if ($key === null) {
            return response()->json(['message' => 'This tool already has that status.'], 422);
        }

        return response()->json(['data' => StatusMap::for($integration->fresh())->catalogue()], 201);
    }

    // ── Internals ───────────────────────────────────────────────────────────

    /**
     * Merge a submitted configuration over the stored one.
     *
     * A secret arriving blank means the form could not show it and the person
     * did not retype it — so the stored value stands. Without this, every save
     * of an otherwise unrelated field would silently clear the credentials and
     * the connection would fail on its next run for no visible reason.
     *
     * @param  array<string, mixed>  $submitted
     * @return array<string, mixed>
     */
    private function configurationFor(string $provider, array $submitted, ?Integration $existing): array
    {
        $configuration = $existing?->configuration ?? [];
        $secrets = $this->registry->secretKeys($provider);

        foreach ($submitted as $key => $value) {
            if (in_array($key, $secrets, true) && trim((string) $value) === '') {
                continue;
            }

            $configuration[$key] = $value;
        }

        return $configuration;
    }

    private function find(string $id): Integration
    {
        /*
         * business and storefront are eager-loaded because everything that
         * follows reaches for them — the status list comes off the business, the
         * shop name off the storefront — and lazy loading is prevented outside
         * production. Left to load themselves, opening the settings page throws.
         */
        return Integration::query()
            ->with(['storefront:id,public_id,name', 'business'])
            ->where('business_id', $this->businessId())
            ->where(fn ($q) => $q->where('public_id', $id)->orWhere('id', $id))
            ->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function present(Integration $integration): array
    {
        $driver = $this->registry->for($integration);

        return [
            'id' => $integration->public_id,
            'name' => $integration->name,
            'provider' => $integration->provider,
            'platform' => $driver->label(),
            // What it is for. Stored on the row rather than derived, because a
            // generic REST connection could be any of them.
            'kind' => $this->registry->kindFor($integration),
            /*
             * The shop this connection feeds. Null is a problem rather than a
             * neutral state — orders imported without it read as counter sales
             * on every screen — so the screen says so instead of leaving it
             * blank.
             */
            'store' => $integration->storefront === null ? null : [
                'id' => $integration->storefront->public_id,
                'name' => $integration->storefront->name,
            ],
            'status' => $integration->status,
            'is_active' => (bool) $integration->is_active,
            'bidirectional' => (bool) $integration->bidirectional,
            'can_pull' => $driver instanceof PullsRecords,
            'capabilities' => $driver->capabilities()->toArray(),

            // Masked, never the real values — see the class note.
            'configuration' => $integration->safeConfiguration($this->registry->secretKeys((string) $integration->provider)),
            'fields' => array_map(fn ($f): array => $f->toArray(), $driver->configSchema()),

            'last_sync_at' => $integration->last_sync_at?->toIso8601String(),
            'last_success_at' => $integration->last_success_at?->toIso8601String(),
            'last_error_message' => $integration->last_error_message,
            'success_rate' => $integration->success_rate,
            'records_synced_total' => $integration->records_synced_total,

            // How many of this shop's own words still need explaining. The one
            // number that tells somebody there is something here to do.
            // Words this shop sends that no status of ours claims — orders are
            // arriving with them and nothing is happening.
            'unmapped_statuses' => count(StatusMap::for($integration)->unclaimed()),

            /*
             * The address this shop should post to. Shown once here because
             * there is nowhere else to get it — the token is generated on
             * create and never shown again in any listing.
             */
            'webhook_url' => url('/api/v1/webhooks/integrations/'.$integration->getAttributes()['webhook_token']),
        ];
    }

    private function businessId(): int
    {
        $id = $this->tenant->business()?->id;

        abort_if($id === null, 409, 'No business is open, so there is nothing to connect a shop to.');

        return (int) $id;
    }
}
