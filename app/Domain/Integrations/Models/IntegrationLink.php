<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Models;

use App\Domain\Sales\Models\Order;
use App\Domain\Tenancy\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One record here, and the record it corresponds to on one shop.
 *
 * A product listed in three shops has three of these. An order has one, because
 * it was placed in exactly one place. Same table either way — the difference is
 * how many rows exist, not how they work.
 */
class IntegrationLink extends Model
{
    use BelongsToAccount;

    public const ORDER = 'order';

    public const PRODUCT = 'product';

    public const CUSTOMER = 'customer';

    protected $fillable = [
        'account_id',
        'business_id',
        'integration_id',
        'entity',
        'linkable_id',
        'external_id',
        'external_reference',
        'custom_fields',
        'push_fingerprint',
        'last_pulled_at',
        'last_pushed_at',
    ];

    protected function casts(): array
    {
        return [
            'custom_fields' => 'array',
            'last_pulled_at' => 'datetime',
            'last_pushed_at' => 'datetime',
            'push_pending_at' => 'datetime',
        ];
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    /**
     * The order this link points at, where it points at one.
     *
     * Not a morphTo. `linkable_id` carries no type column of its own — `entity`
     * says what it is, in the same three-word vocabulary the mappings and the
     * driver capabilities use. A morph map would introduce a fourth spelling of
     * the same idea and a second place for it to disagree with itself.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'linkable_id');
    }

    // ── Scopes ──────────────────────────────────────────────────────────────

    public function scopeEntity(Builder $query, string $entity): Builder
    {
        return $query->where('entity', $entity);
    }

    public function scopeForIntegration(Builder $query, int $integrationId): Builder
    {
        return $query->where('integration_id', $integrationId);
    }

    /** Everywhere one of our records is listed. */
    public function scopeForLocal(Builder $query, string $entity, int $localId): Builder
    {
        return $query->where('entity', $entity)->where('linkable_id', $localId);
    }

    // ── Custom fields ───────────────────────────────────────────────────────

    public function custom(string $key, mixed $fallback = null): mixed
    {
        return data_get($this->custom_fields ?? [], $key, $fallback);
    }

    /**
     * Merge in what a sync just read, keeping what it did not mention.
     *
     * Merged rather than replaced because a webhook carries a fraction of a
     * record. Replacing would let a payload that happened not to include the
     * delivery slot erase the one already stored.
     *
     * @param  array<string, mixed>  $values
     */
    public function mergeCustom(array $values): void
    {
        if ($values === []) {
            return;
        }

        $this->custom_fields = [...($this->custom_fields ?? []), ...$values];
    }

    // ── Echo detection ──────────────────────────────────────────────────────

    /**
     * A stable fingerprint of what a push sent.
     *
     * Keys sorted and values normalised so that two payloads meaning the same
     * thing hash the same. Without that, a shop echoing our own values back in a
     * different key order — which several of them do — reads as a genuine edit,
     * and the sync answers its own push forever.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fingerprint(array $payload): string
    {
        return hash('sha256', (string) json_encode(self::normalise($payload)));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function normalise(array $payload): array
    {
        ksort($payload);

        foreach ($payload as $key => $value) {
            $payload[$key] = match (true) {
                is_array($value) => self::normalise($value),
                is_bool($value) => $value ? '1' : '0',
                // Trimmed and folded: a trailing space or a change of case
                // coming back from a shop is not somebody editing an order.
                default => mb_strtolower(trim((string) $value)),
            };
        }

        return $payload;
    }

    /**
     * Is this inbound payload merely our own push coming back?
     *
     * Compared by content rather than by a time window. A window wide enough to
     * cover the echo is also wide enough to swallow a genuine edit made seconds
     * after a push — and that edit is somebody's actual work, silently dropped.
     *
     * @param  array<string, mixed>  $payload
     */
    public function isEcho(array $payload): bool
    {
        return $this->push_fingerprint !== null
            && hash_equals($this->push_fingerprint, self::fingerprint($payload));
    }

    /**
     * A local change this shop has not accepted yet.
     *
     * ── Why the debt is written before the attempt ───────────────────────────
     *
     * So that it survives the attempt never happening. Recording only failures
     * cannot catch a push that was never scheduled, a worker that was not
     * running, or a process that died between the save and the dispatch — and
     * those are the cases that produced silent divergence here, not a shop
     * answering with an error.
     *
     * Written in the same transaction as the change it refers to, so there is
     * no instant where the order has moved and nothing knows the shop needs
     * telling.
     */
    public function markPushPending(): void
    {
        $this->forceFill([
            'push_pending_at' => $this->push_pending_at ?? now(),
            // Last time's reason is not this time's; a fresh attempt starts
            // without the old complaint attached to it.
            'push_error' => null,
        ])->save();
    }

    /** Why the last attempt did not land. The debt stays until one does. */
    public function recordPushFailure(string $reason): void
    {
        $this->forceFill([
            'push_pending_at' => $this->push_pending_at ?? now(),
            'push_error' => mb_substr($reason, 0, 1000),
        ])->save();
    }

    /** @param array<string, mixed> $payload */
    public function recordPush(array $payload): void
    {
        $this->forceFill([
            'push_fingerprint' => self::fingerprint($payload),
            'last_pushed_at' => now(),

            // Settled: this is the only thing that clears the debt, which is
            // what makes the debt trustworthy.
            'push_pending_at' => null,
            'push_error' => null,
        ])->save();
    }

    /** Changes made here that the shop has not taken. */
    public function scopeUnsent(Builder $query): Builder
    {
        return $query->whereNotNull('push_pending_at');
    }

    public function recordPull(): void
    {
        $this->forceFill(['last_pulled_at' => now()])->save();
    }
}
