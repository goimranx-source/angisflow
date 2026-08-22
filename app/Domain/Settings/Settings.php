<?php

declare(strict_types=1);

namespace App\Domain\Settings;

use App\Domain\Settings\Models\Setting;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Reading and writing a subscriber's settings.
 *
 * ── The one thing this class exists for ──────────────────────────────────────
 *
 * The brand name and the logo are needed on *every* page load — they go into
 * the boot payload the document carries. Fetching a subscriber's rows each time
 * would be a query per request to find out what the tool is called; at a
 * million subscribers that is the busiest query in the system and the least
 * interesting one.
 *
 * So the whole set is read once, cached per account, and served from memory
 * afterwards. It is dropped the moment anything is written, which is the only
 * time the answer can change. The common path touches no table at all.
 *
 * ── And why it is all-or-nothing ─────────────────────────────────────────────
 *
 * The cache holds every key for an account, not one key per entry. Settings are
 * read as a set — the appearance group is wanted at once, or none of it — and a
 * key-per-entry cache would turn one page load into eight cache round trips to
 * rebuild something that fits in a few hundred bytes.
 */
final class Settings
{
    /** Long, because a write invalidates it — the TTL is only a backstop. */
    private const TTL = 86400;

    /** Memoised per request, so a page reading eight keys does one cache read. */
    private array $memo = [];

    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * Everything, cast, with defaults filled in for anything never saved.
     *
     * @return array<string, mixed>
     */
    public function all(?int $accountId = null): array
    {
        $accountId ??= $this->tenant->accountId();

        if ($accountId === null) {
            return SettingsRegistry::defaults();
        }

        $workspaceId = $this->tenant->workspace()?->getAttributes()['id'] ?? null;

        // Memoised per scope, not per account — two workspaces on one account
        // hold genuinely different answers and sharing a memo key would serve
        // the first one's settings to the second for the rest of the request.
        $memoKey = $accountId.':'.($workspaceId ?? 'account');

        if (isset($this->memo[$memoKey])) {
            return $this->memo[$memoKey];
        }

        /*
         * Two rows deep, nearer wins.
         *
         * The account row is the default every workspace inherits; a workspace
         * row overrides it for that workspace alone. Both are read in one query
         * and ordered so the workspace's own value is applied last — so a
         * workspace that has never set anything still gets sensible answers,
         * and one that has gets only the keys it actually changed.
         */
        $stored = Cache::remember(
            self::cacheKey($accountId, $workspaceId),
            self::TTL,
            fn () => Setting::query()
                ->withoutGlobalScopes()
                ->where('account_id', $accountId)
                ->where(function ($query) use ($workspaceId) {
                    $query->whereNull('workspace_id');

                    if ($workspaceId !== null) {
                        $query->orWhere('workspace_id', $workspaceId);
                    }
                })
                // Account defaults first so the workspace's own row overwrites
                // it in the map rather than the other way round.
                ->orderByRaw('workspace_id IS NULL DESC')
                // Three columns, never *. This is read on every request; there
                // is no reason to drag timestamps across the wire with it.
                ->get(['key', 'value'])
                ->pluck('value', 'key')
                ->all(),
        );

        $resolved = [];

        foreach (SettingsRegistry::KEYS as $key => $meta) {
            $resolved[$key] = array_key_exists($key, $stored)
                ? SettingsRegistry::cast($key, $stored[$key])
                : $meta['default'];
        }

        return $this->memo[$memoKey] = $resolved;
    }

    public function get(string $key, mixed $fallback = null): mixed
    {
        if (! SettingsRegistry::exists($key)) {
            throw new InvalidArgumentException("No such setting: {$key}");
        }

        return $this->all()[$key] ?? $fallback;
    }

    /** One group's keys, without their prefix — the shape a form wants. */
    public function group(string $group): array
    {
        $all = $this->all();
        $out = [];

        foreach (SettingsRegistry::keysIn($group) as $key) {
            $out[$key] = $all[$key];
        }

        return $out;
    }

    /**
     * Write several at once.
     *
     * A single upsert rather than a query per key: saving the appearance tab is
     * six settings, and six round trips to store six short strings is five more
     * than it needs.
     *
     * @param  array<string, mixed>  $values
     */
    public function put(array $values, ?int $accountId = null): void
    {
        $accountId ??= $this->tenant->requireAccountId();
        $workspaceId = $this->tenant->workspace()?->getAttributes()['id'] ?? null;

        $rows = [];
        $now = now();

        foreach ($values as $key => $value) {
            if (! SettingsRegistry::exists($key)) {
                // Silently ignored rather than thrown. A client sending a key
                // this build does not know about — an older tab, a newer
                // deploy — should not fail the whole save of the keys it does.
                continue;
            }

            $rows[] = [
                'account_id' => $accountId,
                // Written against the open workspace, so saving the currency
                // while looking at one set of books cannot silently change it
                // for the others on the account.
                'workspace_id' => $workspaceId,
                'key' => $key,
                'value' => SettingsRegistry::serialise($key, $value),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows === []) {
            return;
        }

        DB::table('settings')->upsert(
            $rows,
            ['account_id', 'workspace_id', 'key'],
            ['value', 'updated_at'],
        );

        $this->forget($accountId);
    }

    public function set(string $key, mixed $value, ?int $accountId = null): void
    {
        $this->put([$key => $value], $accountId);
    }

    public function forget(?int $accountId = null): void
    {
        $accountId ??= $this->tenant->accountId();

        if ($accountId === null) {
            return;
        }

        $workspaceId = $this->tenant->workspace()?->getAttributes()['id'] ?? null;

        // Both scopes dropped, not just the open one: writing an account-level
        // default has to invalidate the workspaces inheriting it, and there is
        // no cheap way to know which those are.
        Cache::forget(self::cacheKey($accountId, $workspaceId));
        Cache::forget(self::cacheKey($accountId, null));

        foreach (array_keys($this->memo) as $key) {
            if (str_starts_with((string) $key, $accountId.':')) {
                unset($this->memo[$key]);
            }
        }
    }

    private static function cacheKey(int $accountId, ?int $workspaceId = null): string
    {
        return "settings:{$accountId}:".($workspaceId ?? 'account');
    }
}
