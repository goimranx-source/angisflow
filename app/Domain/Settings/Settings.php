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

        if (isset($this->memo[$accountId])) {
            return $this->memo[$accountId];
        }

        $stored = Cache::remember(
            self::cacheKey($accountId),
            self::TTL,
            fn () => Setting::query()
                ->withoutGlobalScopes()
                ->where('account_id', $accountId)
                // Two columns, never *. This is read on every request; there is
                // no reason to drag timestamps across the wire with it.
                ->pluck('value', 'key')
                ->all(),
        );

        $resolved = [];

        foreach (SettingsRegistry::KEYS as $key => $meta) {
            $resolved[$key] = array_key_exists($key, $stored)
                ? SettingsRegistry::cast($key, $stored[$key])
                : $meta['default'];
        }

        return $this->memo[$accountId] = $resolved;
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
                'key' => $key,
                'value' => SettingsRegistry::serialise($key, $value),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows === []) {
            return;
        }

        DB::table('settings')->upsert($rows, ['account_id', 'key'], ['value', 'updated_at']);

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

        Cache::forget(self::cacheKey($accountId));
        unset($this->memo[$accountId]);
    }

    private static function cacheKey(int $accountId): string
    {
        return "settings:{$accountId}";
    }
}
