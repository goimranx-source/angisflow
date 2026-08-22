<?php

declare(strict_types=1);

namespace App\Http\Api\V1;

use App\Domain\Media\Models\MediaItem;
use App\Domain\Money\Currencies;
use App\Domain\Money\CurrencyService;
use App\Domain\Settings\Settings;
use App\Domain\Settings\SettingsRegistry;
use App\Domain\Tenancy\TenantContext;
use App\Http\Api\Endpoint;
use App\Support\BootPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Settings, one group at a time.
 *
 * ── Why the group is in the URL ──────────────────────────────────────────────
 *
 * Somebody looking at the logo settings has no use for a hundred and fifty
 * exchange rates, and somebody looking at rates has no use for the media
 * library. The first version of this page sent all of it on every tab switch —
 * a third of a megabyte to show a dozen rows — so each group is fetched on its
 * own and cached on its own.
 *
 * It also makes a tab a real address: /settings/currency can be linked to, and
 * the back button returns to the tab you came from, which a purely client-side
 * tab strip cannot do.
 */
class SettingsEndpoint extends Endpoint
{
    public function __construct(
        private readonly Settings $settings,
        private readonly TenantContext $tenant,
    ) {}

    /** The tab strip: which groups exist and which this person may open. */
    public function index(): JsonResponse
    {
        /*
         * Groups with no tab of their own.
         *
         * Their keys are still live: 'appearance' holds the brand name, logo and
         * favicon the boot payload white-labels the shell with, and 'media' is
         * what the logo pickers read from. Removing the keys would strip the
         * product of its own name — so the settings remain, and only the tabs
         * go. Filtered here rather than deleted from the registry because
         * keysIn() and mediaKeysIn() address those groups by name.
         */
        $hidden = ['appearance', 'media'];

        $groups = [];

        foreach (SettingsRegistry::GROUPS as $key => $group) {
            if (in_array($key, $hidden, true)) {
                continue;
            }

            if (! Gate::allows($group['capability'])) {
                continue;
            }

            $groups[] = ['key' => $key] + $group;
        }

        return response()->json(['data' => $groups])
            ->header('Cache-Control', 'no-store, private');
    }

    /**
     * One group's values, plus whatever that group needs to render itself.
     *
     * The extras are attached here rather than fetched separately because they
     * are useless on their own: a currency screen with no rate list is a form
     * nobody can fill in, and two requests to draw one panel is the round trip
     * this architecture spends its time removing.
     */
    public function show(Request $request, string $group): JsonResponse
    {
        $this->authoriseGroup($group);

        $payload = [
            'group' => $group,
            'values' => $this->valuesFor($group),
            'fields' => $this->fieldsFor($group),
        ];

        $payload += match ($group) {
            'appearance' => ['media' => $this->mediaPayload($this->mediaInUse($group))],
            'currency' => $this->currencyExtras(),
            default => [],
        };

        return response()->json(['data' => $payload])
            ->header('Cache-Control', 'no-store, private');
    }

    /**
     * Save one group.
     *
     * Validated against the registry rather than against rules written here, so
     * adding a setting is a line in one file and not an edit in three.
     */
    public function update(Request $request, string $group): JsonResponse
    {
        $this->authoriseGroup($group, write: true);

        $rules = SettingsRegistry::rulesFor($group);

        if ($rules === []) {
            return response()->json(['message' => 'Nothing in that group can be saved.'], 422);
        }

        $validated = $request->validate($rules);

        // Back to storage keys. The body speaks short names because the group
        // is already in the URL; the table and the registry speak full ones.
        $values = [];

        foreach ($validated as $short => $value) {
            $values[SettingsRegistry::fullKey($group, $short)] = $value;
        }

        // A media key holds a public id, and the only ids worth accepting are
        // ones this subscriber actually owns. Without the check the value is a
        // string from a form, and a form can say anything.
        foreach (SettingsRegistry::mediaKeysIn($group) as $key) {
            if (! empty($values[$key]) && ! $this->ownsMedia((string) $values[$key])) {
                $values[$key] = '';
            }
        }

        $this->settings->put($values);

        // The shell shows the brand name and logo, so it comes back with the
        // save rather than being re-fetched a moment later.
        return response()->json([
            'message' => 'Saved.',
            'data' => [
                'group' => $group,
                'values' => $this->valuesFor($group),
            ],
            'boot' => BootPayload::build(),
        ]);
    }

    // ── Group extras ─────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function currencyExtras(): array
    {
        $currency = app(CurrencyService::class);
        $base = $currency->base();
        $rates = $currency->rates();
        $inUse = $currency->inUse();

        // The ones that matter first — a currency a business actually trades in
        // — then everything else. With the whole list fetched, the handful that
        // matter would otherwise be buried somewhere around the Guinean franc.
        $listed = collect(array_keys($rates))
            ->merge(collect($inUse)->reject(fn ($c) => $c === $base))
            ->unique()
            ->sortBy(fn ($code) => (in_array($code, $inUse, true) ? '0' : '1').$code)
            ->values();

        /*
         * What each set of books will actually do with what it sends.
         *
         * A rate table on its own is abstract — a column of numbers against
         * currency codes. This turns it into the question the subscriber is
         * really asking: my Berlin shop charges in euros, so what does that
         * become in the currency I report in, and is there a rate for it at all?
         *
         * A hundred units is the sample rather than one, because rates below
         * 0.01 round to nothing at a single unit and the row reads as broken.
         */
        $sample = 100;

        $businesses = collect($this->tenant->workspace()?->businesses()->orderBy('name')->get()
            ?? $this->tenant->account()?->businesses()->orderBy('name')->get()
            ?? [])
            ->map(function ($business) use ($currency, $base, $sample) {
                $code = strtoupper($business->base_currency ?: $base);
                $rate = $code === $base ? 1.0 : $currency->rate($code, $base);

                return [
                    'id' => $business->public_id,
                    'name' => $business->name,
                    'short_code' => $business->short_code,
                    'code' => $code,
                    'is_base' => $code === $base,
                    'rate' => $rate,
                    // Null, never a guess. A figure converted at par because no
                    // rate existed is wrong by whatever the real rate is and
                    // looks entirely plausible sitting in a table.
                    'converted' => $rate === null
                        ? null
                        : round($sample * $rate, Currencies::scale($base)),
                ];
            })
            ->values()
            ->all();

        return [
            'currency' => [
                'base' => $base,
                'base_name' => Currencies::name($base),
                'base_symbol' => Currencies::symbol($base),
                'sample' => $sample,
                'businesses' => $businesses,
                'mode' => $currency->mode(),
                'provider' => $currency->provider(),
                'providers' => collect(CurrencyService::PROVIDERS)
                    ->map(fn ($p, $key) => ['key' => $key, 'label' => $p['label'], 'needs_key' => $p['key']])
                    ->values()
                    ->all(),
                'in_use' => $inUse,
                'missing' => $currency->missing(),
                'last_refreshed' => $currency->lastRefreshed()?->toIso8601String(),
                'rebuilt_at' => $this->settings->get('currency.rebuilt_at') ?: null,
                'options' => Currencies::options(),
                /*
                 * The row is looked up once and allowed to be absent.
                 *
                 * A listed currency with no rate yet is the normal state — it is
                 * exactly what the "missing" list above is for — but the three
                 * lines here each reached into $rates separately and only two of
                 * them guarded it, so `fetched_at` threw "Undefined array key"
                 * and took the whole Currency tab down with a 500. Any account
                 * whose base currency had never been given a rate could not open
                 * the screen that exists to give it one.
                 */
                'rates' => $listed->map(function (string $code) use ($rates, $inUse) {
                    $rate = $rates[$code] ?? null;

                    return [
                        'code' => $code,
                        'name' => Currencies::name($code),
                        'rate' => $rate !== null ? (float) $rate->rate : null,
                        'source' => $rate?->source,
                        'fetched_at' => $rate?->fetched_at?->toIso8601String(),
                        'in_use' => in_array($code, $inUse, true),
                    ];
                })->all(),
            ],
        ];
    }

    /**
     * The declared shape of a group, so the client renders controls from the
     * registry rather than from a second copy of it written in TypeScript.
     */
    private function fieldsFor(string $group): array
    {
        $out = [];

        foreach (SettingsRegistry::keysIn($group) as $key) {
            if (SettingsRegistry::isReadonly($key)) {
                continue;
            }

            $meta = SettingsRegistry::KEYS[$key];

            $out[] = [
                'key' => SettingsRegistry::shortKey($key),
                'type' => $meta['type'],
                'label' => $meta['label'] ?? $key,
                'help' => $meta['help'] ?? null,
            ];
        }

        return $out;
    }

    private function valuesFor(string $group): array
    {
        $stored = $this->settings->group($group);
        $out = [];

        foreach ($stored as $key => $value) {
            $out[SettingsRegistry::shortKey($key)] = $value;
        }

        // A media key holds an id; the screen needs somewhere to point an
        // <img>. Resolved here so the client never has to know how a media id
        // becomes a URL.
        foreach (SettingsRegistry::mediaKeysIn($group) as $key) {
            $short = SettingsRegistry::shortKey($key);
            $out[$short.':url'] = $this->urlForMedia((string) ($stored[$key] ?? ''));
        }

        return $out;
    }

    /** @return array<int, string> the media ids a group currently points at */
    private function mediaInUse(string $group): array
    {
        $values = $this->settings->group($group);

        return array_values(array_filter(array_map(
            fn (string $key) => (string) ($values[$key] ?? ''),
            SettingsRegistry::mediaKeysIn($group),
        )));
    }

    /** @param array<int, string> $ids */
    private function mediaPayload(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return MediaItem::query()
            ->whereIn('public_id', $ids)
            ->get()
            ->map(fn (MediaItem $item) => $item->toPayload())
            ->all();
    }

    private function urlForMedia(string $publicId): ?string
    {
        if ($publicId === '') {
            return null;
        }

        return MediaItem::query()->wherePublicId($publicId)->first()?->url();
    }

    private function ownsMedia(string $publicId): bool
    {
        // The account scope does the owning check — a media item belonging to
        // another subscriber is not visible to this query at all.
        return MediaItem::query()->wherePublicId($publicId)->exists();
    }

    private function authoriseGroup(string $group, bool $write = false): void
    {
        abort_unless(isset(SettingsRegistry::GROUPS[$group]), 404);

        $capability = SettingsRegistry::GROUPS[$group]['capability'];

        // A group readable with settings.view is still only writable with
        // settings.edit — reading what the tool is called and renaming it are
        // different permissions.
        Gate::authorize($write && $capability === 'settings.view' ? 'settings.edit' : $capability);
    }
}
