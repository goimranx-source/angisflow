<?php

declare(strict_types=1);

namespace App\Domain\Shared\ValueObjects;

use App\Domain\Money\Currencies;
use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * An amount of money, held as a whole number of minor units.
 *
 * ── Why not decimal, and why not float ───────────────────────────────────────
 *
 * A float cannot hold 0.1. Everyone knows this and everyone writes `0.1 + 0.2`
 * into an invoice total anyway, and the error only surfaces as a one-poisha
 * difference on a reconciliation that then takes an afternoon to explain.
 *
 * DECIMAL is exact, and it is the right choice in the database — but PHP has no
 * decimal type, so every DECIMAL read comes back as a string, gets cast to
 * float somewhere in the call chain, and the guarantee evaporates at the first
 * `round()`. The first version of Prism carried `decimal:6` casts for exactly
 * this reason and still had to reason carefully about where rounding happened.
 *
 * An integer count of the smallest unit — poisha, cents, fils — has neither
 * problem. It is exact, it is fast, it compares and sums in SQL without
 * surprises, and BIGINT holds ninety-two thousand trillion of them, which is
 * more than any book will need.
 *
 * The currency travels with the amount because an amount without one is not
 * money, it is a number — and adding two of them is the single commonest way a
 * multi-currency report comes out confidently wrong.
 */
final readonly class Money implements JsonSerializable, Stringable
{
    public function __construct(
        public int $minor,
        public string $currency,
    ) {
        if (strlen($currency) !== 3) {
            throw new InvalidArgumentException("Not a currency code: {$currency}");
        }
    }

    public static function of(int $minor, string $currency): self
    {
        return new self($minor, strtoupper($currency));
    }

    public static function zero(string $currency): self
    {
        return new self(0, strtoupper($currency));
    }

    /**
     * Build from what somebody typed — "1,250.75" — without ever touching a
     * float. The fractional part is read as digits and padded, so 1250.7
     * becomes 125070 rather than whatever the binary representation of 0.7
     * happens to round to.
     */
    public static function fromDecimalString(string $value, string $currency): self
    {
        $currency = strtoupper($currency);
        $scale = self::scaleFor($currency);

        $clean = preg_replace('/[^0-9.\-]/', '', $value) ?? '0';
        $negative = str_starts_with($clean, '-');
        $clean = ltrim($clean, '-');

        [$whole, $fraction] = array_pad(explode('.', $clean, 2), 2, '');

        $fraction = substr(str_pad($fraction, $scale, '0'), 0, $scale);
        $minor = (int) (($whole === '' ? '0' : $whole).$fraction);

        return new self($negative ? -$minor : $minor, $currency);
    }

    /**
     * How many minor units make one major one.
     *
     * Delegated to Currencies rather than answered here. This class used to
     * carry its own copies of the zero-decimal and three-decimal lists, which
     * is one fact written down twice: adding a currency to one list and not the
     * other would have this object and the formatter disagreeing about whether
     * ¥1,200 has a fractional part, and nothing would fail loudly when they
     * did.
     */
    public static function scaleFor(string $currency): int
    {
        return Currencies::scale($currency);
    }

    public function scale(): int
    {
        return self::scaleFor($this->currency);
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor + $other->minor, $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor - $other->minor, $this->currency);
    }

    /** Multiplication rounds half-up at the minor unit — the retail convention. */
    public function times(float|int $factor): self
    {
        return new self((int) round($this->minor * $factor, 0, PHP_ROUND_HALF_UP), $this->currency);
    }

    public function negated(): self
    {
        return new self(-$this->minor, $this->currency);
    }

    public function abs(): self
    {
        return new self(abs($this->minor), $this->currency);
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    public function isPositive(): bool
    {
        return $this->minor > 0;
    }

    public function isNegative(): bool
    {
        return $this->minor < 0;
    }

    /**
     * Split into n parts that add back to exactly the original.
     *
     * The remainder is handed out one minor unit at a time rather than
     * rounded away, so three ways of ৳10.00 is 3.34 + 3.33 + 3.33 and not
     * three lots of 3.33 with a poisha lost somewhere.
     *
     * @return list<self>
     */
    public function allocate(int $parts): array
    {
        if ($parts < 1) {
            throw new InvalidArgumentException('Cannot split money into fewer than one part.');
        }

        $base = intdiv($this->minor, $parts);
        $remainder = $this->minor - ($base * $parts);

        $out = [];

        for ($i = 0; $i < $parts; $i++) {
            $out[] = new self($base + ($i < abs($remainder) ? ($remainder <=> 0) : 0), $this->currency);
        }

        return $out;
    }

    /** The plain decimal string, for display and for handing to the client. */
    public function toDecimalString(): string
    {
        $scale = $this->scale();
        $sign = $this->minor < 0 ? '-' : '';
        $digits = str_pad((string) abs($this->minor), $scale + 1, '0', STR_PAD_LEFT);

        if ($scale === 0) {
            return $sign.$digits;
        }

        return $sign.substr($digits, 0, -$scale).'.'.substr($digits, -$scale);
    }

    public function jsonSerialize(): array
    {
        // Both, deliberately. The client renders the string and does arithmetic
        // on the integer — JavaScript numbers cannot be trusted with the first
        // and users cannot read the second.
        return [
            'minor' => $this->minor,
            'currency' => $this->currency,
            'formatted' => $this->toDecimalString(),
        ];
    }

    public function __toString(): string
    {
        return $this->toDecimalString().' '.$this->currency;
    }

    private function assertSameCurrency(self $other): void
    {
        if ($other->currency !== $this->currency) {
            throw new InvalidArgumentException(
                "Cannot combine {$this->currency} with {$other->currency} — convert one of them first."
            );
        }
    }
}
