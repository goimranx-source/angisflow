<?php

declare(strict_types=1);

namespace App\Domain\Shared\Casts;

use App\Domain\Shared\ValueObjects\Money;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * A money column, read and written as a Money rather than a bare integer.
 *
 * ── Why this exists at all ───────────────────────────────────────────────────
 *
 * Money already knows how to be exact. What it could not do until now was
 * survive a trip through the database: every read handed back an int and every
 * write took one, so the currency was dropped at the boundary and picked up
 * again from whatever column the calling code happened to remember. That is the
 * same defect the value object was written to prevent, moved one layer down and
 * made invisible.
 *
 * With this, `$invoice->total` is a Money on the way out and accepts a Money on
 * the way in, and the pairing of amount and currency is enforced by the model
 * definition instead of by everyone remembering.
 *
 * ── The currency column ──────────────────────────────────────────────────────
 *
 *     protected function casts(): array
 *     {
 *         return ['total' => MoneyCast::class.':currency'];
 *     }
 *
 * The argument names the sibling column holding the code. It defaults to
 * `currency`, which is what most tables will call it. Rows that are always in
 * one known currency can pass a literal three-letter code instead —
 * `MoneyCast::class.':BDT'` — for cases like a platform price list that is not
 * per-tenant.
 *
 * ── Why writing sets the currency column too ─────────────────────────────────
 *
 * Assigning a Money whose currency disagrees with the row's own is either a bug
 * or a conversion somebody forgot to do, and silently keeping the old code
 * would turn it into a wrong number that looks right. So a write carries the
 * currency with it, and a write against a fixed literal code refuses outright.
 */
final class MoneyCast implements CastsAttributes
{
    public function __construct(
        private readonly string $currencySource = 'currency',
    ) {}

    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        if ($value === null) {
            return null;
        }

        return new Money((int) $value, $this->currencyFor($attributes, $key));
    }

    /**
     * @return array<string, mixed>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [$key => null];
        }

        if (is_int($value)) {
            // A bare integer is accepted, because a great deal of arithmetic
            // legitimately produces one and forcing a wrapper at every call
            // site would just breed helper functions that do it badly. The
            // currency already on the row is the right one for it.
            return [$key => $value];
        }

        if (! $value instanceof Money) {
            throw new InvalidArgumentException(
                sprintf('%s expects a Money or an integer of minor units, got %s.', $key, get_debug_type($value)),
            );
        }

        if ($this->isLiteral()) {
            $fixed = strtoupper($this->currencySource);

            if ($value->currency !== $fixed) {
                throw new InvalidArgumentException(
                    "{$key} is always in {$fixed}, so a {$value->currency} amount cannot be stored in it. Convert it first.",
                );
            }

            return [$key => $value->minor];
        }

        return [
            $key => $value->minor,
            $this->currencySource => $value->currency,
        ];
    }

    /**
     * A three-letter argument is the currency itself; anything else names a
     * column to read it from.
     */
    private function isLiteral(): bool
    {
        return strlen($this->currencySource) === 3;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function currencyFor(array $attributes, string $key): string
    {
        if ($this->isLiteral()) {
            return strtoupper($this->currencySource);
        }

        $code = $attributes[$this->currencySource] ?? null;

        if (! is_string($code) || $code === '') {
            // Guessing here is how a report ends up adding taka to dollars and
            // presenting the result with a confident total.
            throw new InvalidArgumentException(
                "Cannot read {$key} as money: {$this->currencySource} is not set on this row.",
            );
        }

        return strtoupper($code);
    }
}
