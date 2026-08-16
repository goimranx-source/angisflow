<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One way a customer can be recognised.
 *
 * `normalised` is what matching uses, and `value` is what people see. Two
 * people typing +880 1712-345678 and 01712345678 must still be one person, and
 * the display form has to survive that.
 */
class CustomerIdentity extends Model
{
    use BelongsToBusiness;

    public const EMAIL = 'email';
    public const PHONE = 'phone';
    public const WHATSAPP = 'whatsapp';
    public const MESSENGER = 'messenger';
    public const INSTAGRAM = 'instagram';
    public const TIKTOK = 'tiktok';
    public const EXTERNAL = 'external';

    protected $attributes = ['is_verified' => false, 'is_primary' => false];

    protected $fillable = [
        'account_id', 'business_id', 'customer_id', 'kind', 'value',
        'normalised', 'source', 'is_verified', 'is_primary', 'last_seen_at',
    ];

    protected function casts(): array
    {
        return ['is_verified' => 'boolean', 'is_primary' => 'boolean', 'last_seen_at' => 'datetime'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** How many trailing digits of a phone number are compared. */
    private const PHONE_SIGNIFICANT = 9;

    /**
     * Reduce a value to something comparable.
     *
     * ── Phone numbers, and why the tail is what matters ──────────────────────
     *
     * The same number is written +880 1712-345678, 01712345678, 8801712345678
     * and 0171 234 5678. Stripping punctuation is not enough: what varies is
     * the *front* — a country code, a trunk zero, both, or neither — while the
     * subscriber's own number at the end never changes.
     *
     * So the last nine digits are the key. A first attempt here stripped the
     * trunk zero and kept everything else, which produced 1712345678 for the
     * local form and 8801712345678 for the international one — two keys for one
     * phone, and the storefront visitor arriving after the same person bought
     * at the till became a second customer.
     *
     * Nine digits is a deliberate compromise. Longer misses countries with
     * shorter national numbers; shorter starts colliding between unrelated
     * people. Two different real customers sharing a nine-digit tail is
     * possible and rare — and identify() treats a lone phone match as weak
     * anyway, so a collision surfaces as a suggestion rather than a merge.
     *
     * Email is lowercased and nothing more. Stripping dots or plus-tags is
     * tempting and wrong: they are significant on plenty of mail servers, and
     * folding them merges two people who deliberately kept themselves apart.
     */
    public static function normalise(string $kind, string $value): string
    {
        $value = trim($value);

        if ($kind === self::EMAIL) {
            return strtolower($value);
        }

        if (in_array($kind, [self::PHONE, self::WHATSAPP], true)) {
            $digits = preg_replace('/\D+/', '', $value) ?? '';

            return strlen($digits) > self::PHONE_SIGNIFICANT
                ? substr($digits, -self::PHONE_SIGNIFICANT)
                : $digits;
        }

        return strtolower($value);
    }

    public function scopeOfKind(Builder $q, string $kind): Builder
    {
        return $q->where('kind', $kind);
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'kind' => $this->kind,
            'value' => $this->value,
            'source' => $this->source,
            'is_verified' => $this->is_verified,
            'is_primary' => $this->is_primary,
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
        ];
    }
}
