<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Models;

use App\Domain\Delivery\Models\CourierConnection;
use App\Domain\Integrations\Support\StatusMap;
use App\Domain\Shared\Concerns\HasPublicId;
use App\Domain\Tenancy\Concerns\BelongsToAccount;
use App\Domain\Tenancy\Models\Business;
use App\Models\Storefront;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * One connection between a business and an external shop.
 *
 * ── Why it belongs to a business ─────────────────────────────────────────────
 *
 * A shop sells for a business, not for an account and not for a workspace. Its
 * orders land in that business's books, in that business's currency, against
 * that business's stock. Scoping a connection any higher would mean an order
 * arriving with nowhere definite to go.
 *
 * One business may hold several — a Shopify shop, a WooCommerce site and a
 * bespoke one, each a separate row, each with its own credentials, its own
 * webhook token and its own field mapping.
 *
 * ── Credentials ──────────────────────────────────────────────────────────────
 *
 * `configuration` holds whatever the driver's own schema asked for, and it is
 * encrypted whole rather than field by field. Encrypting per field means every
 * new secret is a new accessor somebody has to remember to write, and the one
 * they forget is the one that sits in plain text — a denylist guarding a
 * boundary that wants an allowlist. Encrypting the column means a driver can
 * ask for anything and it is protected by default.
 *
 * The cost is that configuration cannot be queried in SQL. That is the right
 * trade: nothing should ever be searching inside somebody's API secrets.
 */
class Integration extends Model
{
    use BelongsToAccount, HasPublicId, SoftDeletes;

    protected $table = 'api_integrations';

    /** Working, as far as the last exchange with it could tell. */
    public const STATUS_ACTIVE = 'active';

    /** Reachable but refusing us, or answering with errors. */
    public const STATUS_ERROR = 'error';

    /** Deliberately switched off. Not a fault, and not to be retried. */
    public const STATUS_PAUSED = 'paused';

    protected $fillable = [
        'account_id',
        'business_id',
        'storefront_id',
        'courier_connection_id',
        'public_id',
        'name',
        'slug',
        'type',
        'provider',
        'version',
        'configuration',
        'field_mappings',
        'sync_settings',
        'bidirectional',
        'webhook_token',
        'status',
        'is_active',
        'description',
        'metadata',
        'created_by',
        'installed_at',
    ];

    /**
     * Never serialised to a client. `configuration` is the shop's credentials,
     * and the accessor below decrypts it — so without this an integration
     * handed to a JSON response would hand over the secrets in readable form.
     */
    protected $hidden = ['configuration', 'webhook_token'];

    protected function casts(): array
    {
        return [
            'field_mappings' => 'array',
            'sync_settings' => 'array',
            'metadata' => 'array',
            'bidirectional' => 'boolean',
            'is_active' => 'boolean',
            'last_sync_at' => 'datetime',
            'last_success_at' => 'datetime',
            'last_error_at' => 'datetime',
            'installed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Integration $integration): void {
            // Generated here rather than asked for, so no connection can exist
            // without one and no caller can supply a guessable value.
            $integration->webhook_token ??= Str::random(64);
            $integration->slug ??= Str::slug($integration->name ?: $integration->provider ?: 'connection');
            $integration->installed_at ??= now();
        });
    }

    // ── Credentials ─────────────────────────────────────────────────────────

    /** @param array<string, mixed>|string|null $value */
    public function setConfigurationAttribute(mixed $value): void
    {
        $this->attributes['configuration'] = $value === null || $value === []
            ? Crypt::encryptString('{}')
            : Crypt::encryptString(is_array($value) ? (string) json_encode($value) : (string) $value);
    }

    /** @return array<string, mixed> */
    public function getConfigurationAttribute(mixed $value): array
    {
        if (! $value) {
            return [];
        }

        try {
            return json_decode(Crypt::decryptString($value), true) ?: [];
        } catch (\Throwable) {
            // A row encrypted under a key this deploy no longer has. Returning
            // empty rather than throwing keeps the settings screen openable —
            // which is where somebody would go to re-enter the credentials and
            // fix precisely this.
            return [];
        }
    }

    /** One credential, without decrypting the whole set at every call site. */
    public function config(string $key, mixed $fallback = null): mixed
    {
        return data_get($this->configuration, $key, $fallback);
    }

    /**
     * The same set with every secret replaced by whether it is present.
     *
     * What a settings screen is allowed to see. A field that has been filled in
     * reads back as a masked marker so somebody can tell it is set without it
     * ever being sent to a browser again.
     *
     * @param  list<string>  $secretKeys  from the driver's own config schema
     * @return array<string, mixed>
     */
    public function safeConfiguration(array $secretKeys): array
    {
        $out = [];

        foreach ($this->configuration as $key => $value) {
            $out[$key] = $this->isSecretKey($key, $secretKeys)
                ? (filled($value) ? '••••••••' : null)
                : $value;
        }

        return $out;
    }

    /**
     * Is this configuration key one that must never be read back?
     *
     * ── Why the driver's list is not the whole answer ────────────────────────
     *
     * Because not every secret in here was asked for by a driver. Rotation
     * stores the secret it replaced as `webhook_secret_previous`, which is a
     * live credential — it still verifies an inbound call — but appears in no
     * configSchema, so a check against the declared list alone would have
     * returned it to the browser in plain text while carefully masking the
     * current one beside it.
     *
     * Derived rather than listed: any `x_previous` is as secret as `x`. A rule
     * cannot be forgotten the next time somebody adds a rotating credential,
     * which a second hardcoded string certainly would be.
     *
     * @param  list<string>  $secretKeys
     */
    private function isSecretKey(string $key, array $secretKeys): bool
    {
        if (in_array($key, $secretKeys, true)) {
            return true;
        }

        return str_ends_with($key, '_previous')
            && in_array(mb_substr($key, 0, -9), $secretKeys, true);
    }

    // ── Relations ───────────────────────────────────────────────────────────

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * The shop of ours this connection stands behind, where there is one.
     *
     * Nullable on purpose: a connection can be made before anybody has decided
     * which storefront record it corresponds to, and some never correspond to
     * one at all — a marketplace feed has no page of ours behind it.
     */
    public function storefront(): BelongsTo
    {
        return $this->belongsTo(Storefront::class);
    }

    /**
     * The courier connection this integration is for, where there is one.
     *
     * When this integration represents a courier's API connection, this links
     * back to the courier connection that owns it.
     */
    public function courierConnection(): BelongsTo
    {
        return $this->belongsTo(CourierConnection::class);
    }

    // ── Scopes ──────────────────────────────────────────────────────────────

    public function scopeForBusiness(Builder $query, int $businessId): Builder
    {
        return $query->where('business_id', $businessId);
    }

    /** Switched on, and not in a failed state nobody has looked at. */
    public function scopeUsable(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('status', '!=', self::STATUS_PAUSED);
    }

    // ── State ───────────────────────────────────────────────────────────────

    public function isUsable(): bool
    {
        return $this->is_active && $this->status !== self::STATUS_PAUSED;
    }

    /**
     * One setting about how this connection syncs.
     *
     * Read through here rather than from the array directly so a connection
     * saved before a setting existed still answers sensibly for it, instead of
     * every caller having to remember its own default.
     */
    public function syncSetting(string $key, mixed $fallback = null): mixed
    {
        return data_get($this->sync_settings ?? [], $key, $fallback);
    }

    // ── The last record this shop sent ──────────────────────────────────────

    /**
     * Beyond this, a payload is not kept.
     *
     * A shop with a hundred-line order, or one that returns its entire product
     * catalogue inside each record, would otherwise put half a megabyte into a
     * column that is read on every settings page load. The mapping screen needs
     * a representative record, not a large one.
     */
    private const MAX_PAYLOAD_BYTES = 96 * 1024;

    /**
     * Remember one record exactly as the shop sent it.
     *
     * ── Why this is kept at all ──────────────────────────────────────────────
     *
     * The field mapping screen has to offer the paths that exist in *this
     * shop's* records — including the custom fields its own plugins added, which
     * no documentation anywhere lists. Discovering those by calling the shop
     * every time somebody opens the screen is a slow round trip that fails when
     * the shop is down, and asking somebody to type `meta_data._delivery_slot`
     * from memory is asking them to get it wrong.
     *
     * So whichever arrives first — a sync or a webhook — leaves one record
     * behind, and the mapping screen reads its paths from that.
     *
     * Only the newest per entity is kept. This is an aid for a form, not a log.
     *
     * @param  array<string, mixed>  $payload
     */
    public function rememberPayload(array $payload, string $entity = 'order'): void
    {
        if ($payload === []) {
            return;
        }

        $encoded = json_encode($payload);

        if ($encoded === false || strlen($encoded) > self::MAX_PAYLOAD_BYTES) {
            return;
        }

        $metadata = $this->metadata ?? [];

        data_set($metadata, 'last_payload.'.$entity, [
            'at' => now()->toIso8601String(),
            'body' => $payload,
        ]);

        $this->metadata = $metadata;
    }

    /**
     * The last record this shop sent for an entity, if there is one.
     *
     * @return array<string, mixed>|null
     */
    public function lastPayload(string $entity = 'order'): ?array
    {
        $body = data_get($this->metadata ?? [], 'last_payload.'.$entity.'.body');

        return is_array($body) && $body !== [] ? $body : null;
    }

    /** When that record arrived — so a screen can say how current its paths are. */
    public function lastPayloadAt(string $entity = 'order'): ?string
    {
        $at = data_get($this->metadata ?? [], 'last_payload.'.$entity.'.at');

        return is_string($at) ? $at : null;
    }

    // ── Observed statuses ───────────────────────────────────────────────────

    /**
     * The statuses this shop has actually been seen to send.
     *
     * @return list<string>
     */
    public function seenStatuses(string $entity = 'order'): array
    {
        $seen = data_get($this->metadata ?? [], 'seen_statuses.'.$entity, []);

        return is_array($seen) ? array_values(array_filter($seen, 'is_string')) : [];
    }

    /**
     * What happened the last time this shop actually called us.
     *
     * ── Why this is recorded at all ──────────────────────────────────────────
     *
     * Because everything else about a webhook can look right while it does not
     * work. The shop lists it as active, at the correct address, and posts to
     * it faithfully — and every call is refused here for a signature that does
     * not match. Nothing on either side says so. The shop's delivery log knows,
     * but that is a screen in another application that nobody thinks to open,
     * because from here the connection looks connected.
     *
     * One line of state turns that into something a settings screen can say out
     * loud: called four minutes ago, and refused.
     *
     * Kept on metadata rather than in its own column because it is a hint for a
     * screen, not a record anything queries — and it is overwritten on every
     * call rather than appended, so a busy shop cannot grow this row without
     * bound.
     */
    public function rememberDelivery(bool $accepted, ?string $reason = null): void
    {
        $this->metadata = [
            ...($this->metadata ?? []),
            'last_webhook' => [
                'at' => now()->toIso8601String(),
                'accepted' => $accepted,
                'reason' => $reason,
            ],
        ];

        // Saved quietly. A shop is waiting on this response, and a webhook must
        // not fail because bookkeeping about the webhook failed.
        try {
            $this->saveQuietly();
        } catch (\Throwable) {
        }
    }

    /**
     * @return array{at: string, accepted: bool, reason: string|null}|null
     */
    public function lastDelivery(): ?array
    {
        $last = $this->metadata['last_webhook'] ?? null;

        return is_array($last) ? $last : null;
    }

    /**
     * The secrets an inbound call may legitimately be signed with.
     *
     * Two at most: the one in use, and the one it replaced. See
     * RestDriver::signedWithOurSecret for why the old one is still honoured,
     * and rotateSigningSecret() for how it gets there.
     *
     * @return list<string>
     */
    public function signingSecrets(): array
    {
        $secrets = [
            (string) $this->config('webhook_secret'),
            (string) $this->config('webhook_secret_previous'),
        ];

        return array_values(array_filter($secrets, static fn (string $s): bool => $s !== ''));
    }

    /**
     * Put a new signing secret in place, keeping the old one valid.
     *
     * ── Why the old one is kept rather than discarded ────────────────────────
     *
     * Rotating is a sequence, not an instant: the new secret is stored here,
     * then written to each webhook on the shop one call at a time. Between the
     * first of those calls and the last, the shop is signing with a mixture of
     * both — and any delivery already in flight was signed before any of it
     * started. Discarding the old secret at the top of that sequence refuses
     * every one of them, which for a busy shop means losing real orders as the
     * price of a security hygiene task.
     *
     * Only one generation is kept. Two is enough to cover a rotation; keeping
     * more would mean a secret leaked a year ago still opens the door.
     */
    public function rotateSigningSecret(string $secret): void
    {
        $previous = (string) $this->config('webhook_secret');

        $this->configuration = [
            ...($this->configuration ?? []),
            'webhook_secret' => $secret,
            'webhook_secret_previous' => $previous,
        ];

        $this->save();
    }

    /**
     * Keep the statuses the shop itself reported.
     *
     * Remembered rather than fetched on every render because it costs a call to
     * the shop, and the mapping screen should not sit behind a spinner to show a
     * list that changes when somebody installs a plugin — which is to say, twice
     * a year. Refreshed whenever that screen is genuinely opened, so a status
     * added this morning appears this morning.
     *
     * @param  list<array{value: string, label: string}>  $statuses
     */
    public function rememberPlatformStatuses(array $statuses, string $entity = 'order'): void
    {
        if ($statuses === []) {
            // The shop could not be asked. Keeping what was last known beats
            // replacing a good list with an empty one because the network
            // hiccuped while somebody opened a screen.
            return;
        }

        $metadata = $this->metadata ?? [];
        $metadata['platform_statuses'][$entity] = array_values($statuses);

        $this->metadata = $metadata;
        $this->saveQuietly();
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function platformStatuses(string $entity = 'order'): array
    {
        $held = $this->metadata['platform_statuses'][$entity] ?? [];

        return is_array($held) ? array_values(array_filter($held, 'is_array')) : [];
    }

    /**
     * Remember a status arriving from this shop.
     *
     * ── Why observe at all ───────────────────────────────────────────────────
     *
     * The mapping screen has to offer somebody a list of their own shop's
     * statuses to choose from. For a bespoke site there is no documented list to
     * offer, and even on WooCommerce a plugin can register statuses that appear
     * on no list anywhere. Asking somebody to type 'ready_for_pickup' exactly as
     * their own site spells it is asking them to get it wrong.
     *
     * So the sync records what turns up, and the screen offers it back. The
     * business recognises their own words rather than recalling them, and a
     * status nobody has explained can be pointed out rather than waited for.
     *
     * Deliberately capped and de-duplicated: this is a hint for a form, not an
     * audit log, and a shop with a status per order must not grow this without
     * bound.
     *
     * @param  list<string|null>  $statuses
     */
    public function rememberStatuses(array $statuses, string $entity = 'order'): void
    {
        $existing = $this->seenStatuses($entity);
        $merged = $existing;

        foreach ($statuses as $status) {
            $status = trim((string) $status);

            if ($status === '' || mb_strlen($status) > 60) {
                continue;
            }

            /*
             * Compared through the mapping's own notion of sameness, so
             * 'Awaiting Courier' and 'awaiting-courier' do not both occupy the
             * list — they are one status, and mapping either explains both. The
             * spelling first seen is the one kept, because that is the one the
             * shop's own screen shows the person reading this.
             */
            foreach ($merged as $known) {
                if (StatusMap::key($known) === StatusMap::key($status)) {
                    continue 2;
                }
            }

            $merged[] = $status;
        }

        if ($merged === $existing) {
            return;
        }

        // Oldest dropped first: a status that stopped being used a year ago is
        // less use on the form than one that arrived this morning.
        if (count($merged) > 40) {
            $merged = array_slice($merged, -40);
        }

        $metadata = $this->metadata ?? [];
        data_set($metadata, 'seen_statuses.'.$entity, array_values($merged));
        $this->metadata = $metadata;
    }

    public function recordSuccess(int $records = 0): void
    {
        $this->forceFill([
            'status' => self::STATUS_ACTIVE,
            'last_sync_at' => now(),
            'last_success_at' => now(),
            'last_error_message' => null,
            'total_syncs' => $this->total_syncs + 1,
            'successful_syncs' => $this->successful_syncs + 1,
            'records_synced_total' => $this->records_synced_total + $records,
            'records_synced_today' => $this->records_synced_today + $records,
        ])->save();

        $this->refreshSuccessRate();
    }

    public function recordFailure(string $message): void
    {
        $this->forceFill([
            'status' => self::STATUS_ERROR,
            'last_sync_at' => now(),
            'last_error_at' => now(),
            // Trimmed: a stack trace or an HTML error page from a shop would
            // otherwise sit in a column that gets rendered on a settings screen.
            'last_error_message' => Str::limit($message, 500),
            'total_syncs' => $this->total_syncs + 1,
            'failed_syncs' => $this->failed_syncs + 1,
        ])->save();

        $this->refreshSuccessRate();
    }

    private function refreshSuccessRate(): void
    {
        if ($this->total_syncs > 0) {
            $this->forceFill([
                'success_rate' => round($this->successful_syncs / $this->total_syncs * 100, 2),
            ])->save();
        }
    }
}
