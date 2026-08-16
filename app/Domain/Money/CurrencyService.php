<?php

declare(strict_types=1);

namespace App\Domain\Money;

use App\Domain\Money\Models\ExchangeRate;
use App\Domain\Settings\Settings;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * One currency for the books, whatever the storefronts charge in.
 *
 * A business selling through several shops takes money in several currencies.
 * Adding those totals without converting them produces a number that looks like
 * money and is not — the commonest way a multi-store report ends up confidently
 * wrong.
 *
 * ── Two figures per amount, and only one is a fact ───────────────────────────
 *
 *   amount + currency   what actually changed hands. Written once, never
 *                       touched again by anything here.
 *   base_*              what that is worth in the books' currency. Always
 *                       derived from the pair above, never from a previous
 *                       base_* figure.
 *
 * Because the second is always recomputed from the first, changing the base
 * currency cannot corrupt anything: switch from taka to dollars and back and
 * you get exactly the numbers you started with. A ৳300 expense is ৳300 for
 * ever; in dollar mode it reads as its dollar value, and back in taka mode it
 * reads ৳300 again.
 *
 * ── Who owns a rate ──────────────────────────────────────────────────────────
 *
 * The mode decides, and only one of them does at a time. On manual, rates are
 * typed in and nothing touches them. On automatic, the service is the source of
 * truth and the boxes are read-only — a rate that looks editable but is replaced
 * on the next fetch is worse than one that plainly says it is not yours to edit.
 */
final class CurrencyService
{
    /** Rates change daily at most, and a page can read them a dozen times. */
    private const CACHE_TTL = 900;

    /**
     * Rate services that can be connected.
     *
     * The first needs no account at all, which matters: a business should not
     * have to register with anybody to see its own totals in one currency. The
     * others are for anyone who already pays for one and wants its figures
     * rather than a free feed's.
     *
     * Every one of them quotes "one base buys N of X", so each is inverted on
     * the way in — see refresh().
     */
    public const PROVIDERS = [
        'erapi' => [
            'label' => 'Open Exchange Rates (free, no account)',
            'url' => 'https://open.er-api.com/v6/latest/',
            'key' => false,
            'rates' => 'rates',
        ],
        'frankfurter' => [
            'label' => 'Frankfurter — European Central Bank (free)',
            'url' => 'https://api.frankfurter.app/latest?from=',
            'key' => false,
            'rates' => 'rates',
        ],
        'exchangerate' => [
            'label' => 'ExchangeRate-API (your own key)',
            'url' => 'https://v6.exchangerate-api.com/v6/{key}/latest/',
            'key' => true,
            'rates' => 'conversion_rates',
        ],
    ];

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly Settings $settings,
    ) {}

    /**
     * The one currency this subscriber counts and reports in.
     *
     * Held on the account row rather than in settings because it is read on
     * every request that formats money, and because it is a fact about the
     * business rather than a preference.
     */
    public function base(): string
    {
        return strtoupper($this->tenant->account()?->base_currency ?? 'BDT');
    }

    public function mode(): string
    {
        return $this->settings->get('currency.mode') === 'auto' ? 'auto' : 'manual';
    }

    public function provider(): string
    {
        $key = (string) $this->settings->get('currency.provider');

        return isset(self::PROVIDERS[$key]) ? $key : 'erapi';
    }

    public function providerKey(): ?string
    {
        return ((string) $this->settings->get('currency.provider_key')) ?: null;
    }

    public function symbol(?string $code = null): string
    {
        return Currencies::symbol($code ?: $this->base());
    }

    /**
     * Every rate recorded against the current base, keyed by currency.
     *
     * Cached: a page showing forty amounts in three currencies would otherwise
     * read this table forty times to answer the same question.
     *
     * @return array<string, ExchangeRate>
     */
    public function rates(): array
    {
        $accountId = $this->tenant->accountId();

        if ($accountId === null) {
            return [];
        }

        return Cache::remember(
            self::cacheKey($accountId, $this->base()),
            self::CACHE_TTL,
            fn () => ExchangeRate::query()
                ->where('base', $this->base())
                ->get()
                ->keyBy('code')
                ->all(),
        );
    }

    /**
     * How many units of $to one unit of $from is worth.
     *
     * Null when it cannot be worked out, which callers must treat as "do not
     * know" rather than as 1. Silently converting at par is how a report comes
     * out looking plausible and being wrong by two orders of magnitude.
     */
    public function rate(string $from, string $to): ?float
    {
        $from = strtoupper($from);
        $to = strtoupper($to);

        if ($from === $to) {
            return 1.0;
        }

        $base = $this->base();
        $rates = $this->rates();

        if ($to === $base) {
            return isset($rates[$from]) ? (float) $rates[$from]->rate : null;
        }

        if ($from === $base) {
            return isset($rates[$to]) && (float) $rates[$to]->rate > 0
                ? 1 / (float) $rates[$to]->rate
                : null;
        }

        // Neither is the base: go through it.
        if (! isset($rates[$from], $rates[$to]) || (float) $rates[$to]->rate <= 0) {
            return null;
        }

        return (float) $rates[$from]->rate / (float) $rates[$to]->rate;
    }

    /**
     * Convert an amount in minor units, staying in minor units throughout.
     *
     * Never returns a float of major units — that is where the rounding errors
     * this whole design avoids would come back in.
     */
    public function convertMinor(int $minor, string $from, ?string $to = null): ?int
    {
        $rate = $this->rate($from, $to ?: $this->base());

        if ($rate === null) {
            return null;
        }

        $target = $to ?: $this->base();

        // Scales can differ — 100 JPY is 100 minor units, 100 BDT is 10,000 —
        // so the amount is taken to major, converted, and put back at the
        // target's scale rather than multiplied blindly.
        $fromScale = 10 ** Currencies::scale($from);
        $toScale = 10 ** Currencies::scale($target);

        return (int) round(($minor / $fromScale) * $rate * $toScale);
    }

    /**
     * The same conversion, keeping the currency attached to the answer.
     *
     * convertMinor returns a bare integer, which is correct as far as it goes
     * and drops the one fact that made the conversion worth doing. A caller
     * that converts three amounts into the base currency and sums them has, at
     * that point, three integers and a memory of what they mean.
     *
     * Null on an unknown rate rather than converting at par, for the reason the
     * whole of this class exists: a figure wrong by a hundredfold that looks
     * plausible gets added to a total, while a missing one gets asked about.
     */
    public function convert(Money $amount, ?string $to = null): ?Money
    {
        $target = strtoupper($to ?: $this->base());

        if ($amount->currency === $target) {
            return $amount;
        }

        $minor = $this->convertMinor($amount->minor, $amount->currency, $target);

        return $minor === null ? null : new Money($minor, $target);
    }

    /**
     * Add up amounts that may be in different currencies.
     *
     * Anything that cannot be converted is returned separately rather than
     * dropped or counted at par, so a caller can show "৳48,200 (2 amounts not
     * included — no rate for VUV)" instead of a total that is confidently wrong
     * by whatever those two happened to be.
     *
     * @param  iterable<Money>  $amounts
     * @return array{0: Money, 1: list<Money>}  the total, and what it left out
     */
    public function total(iterable $amounts, ?string $to = null): array
    {
        $target = strtoupper($to ?: $this->base());
        $sum = Money::zero($target);
        $skipped = [];

        foreach ($amounts as $amount) {
            $converted = $this->convert($amount, $target);

            if ($converted === null) {
                $skipped[] = $amount;

                continue;
            }

            $sum = $sum->plus($converted);
        }

        return [$sum, $skipped];
    }

    /** Record a rate somebody typed: one $code is worth $rate base units. */
    public function setManualRate(string $code, float $rate): ExchangeRate
    {
        $row = ExchangeRate::updateOrCreate(
            ['account_id' => $this->tenant->requireAccountId(), 'base' => $this->base(), 'code' => strtoupper($code)],
            ['rate' => $rate, 'source' => 'manual', 'fetched_at' => now()],
        );

        $this->forget();

        return $row;
    }

    public function forgetRate(string $code): void
    {
        ExchangeRate::query()
            ->where('base', $this->base())
            ->where('code', strtoupper($code))
            ->delete();

        $this->forget();
    }

    /**
     * Currencies this business actually deals in.
     *
     * Taken from the businesses it runs rather than offering the whole world: a
     * rate is only worth keeping for money that exists. Orders and stores join
     * this list as those modules land.
     *
     * @return list<string>
     */
    public function inUse(): array
    {
        $codes = collect([$this->base()]);

        $account = $this->tenant->account();

        if ($account !== null) {
            $codes = $codes->merge($account->businesses()->pluck('base_currency'));
        }

        return $codes->filter()->map(fn ($c) => strtoupper((string) $c))->unique()->values()->all();
    }

    /** Currencies in use that have no rate yet — the ones worth chasing. */
    public function missing(): array
    {
        $rates = $this->rates();
        $base = $this->base();

        return array_values(array_filter(
            $this->inUse(),
            fn ($code) => $code !== $base && ! isset($rates[$code]),
        ));
    }

    public function lastRefreshed(): ?\Carbon\Carbon
    {
        $at = ExchangeRate::query()->where('base', $this->base())->max('fetched_at');

        return $at ? \Carbon\Carbon::parse($at) : null;
    }

    /**
     * Fetch today's rates.
     *
     * Manual rates are left alone. Somebody who typed the rate their bank gave
     * them wants that figure in the books, not the mid-market one — overwriting
     * it on a schedule would quietly change what their accounts say.
     *
     * @return array{ok: bool, updated: int, skipped: int, message: string}
     */
    public function refresh(): array
    {
        $base = $this->base();
        $provider = self::PROVIDERS[$this->provider()];

        if ($provider['key'] && ! $this->providerKey()) {
            return $this->result(false, 0, 0, 'This service needs an API key. Add one, or pick a service that does not.');
        }

        $url = str_replace('{key}', (string) $this->providerKey(), $provider['url']).$base;

        try {
            // Short timeout: this runs while somebody is watching a button. A
            // rate service having a bad day must not hold a PHP worker for
            // thirty seconds.
            $response = Http::timeout(10)->acceptJson()->get($url);
        } catch (\Throwable $e) {
            Log::warning('Exchange rate fetch failed', ['error' => $e->getMessage()]);

            return $this->result(false, 0, 0, 'Could not reach the rate service.');
        }

        if (! $response->successful()) {
            return $this->result(false, 0, 0, 'The rate service answered '.$response->status().'.');
        }

        $quoted = $response->json($provider['rates']) ?? [];

        if ($quoted === []) {
            return $this->result(false, 0, 0, "The rate service returned nothing for {$base}.");
        }

        $existing = $this->rates();
        $accountId = $this->tenant->requireAccountId();
        $manualMode = $this->mode() !== 'auto';

        $rows = [];
        $skipped = 0;
        $now = now();

        foreach (array_keys(Currencies::ALL) as $code) {
            if ($code === $base) {
                continue;
            }

            // On manual the typed rate counts, so a fetch leaves it alone. On
            // automatic the service owns every rate, which is what makes the
            // boxes read-only rather than merely ignored.
            if ($manualMode && isset($existing[$code]) && $existing[$code]->isManual()) {
                $skipped++;

                continue;
            }

            if (! isset($quoted[$code]) || (float) $quoted[$code] <= 0) {
                continue;
            }

            $rows[] = [
                'account_id' => $accountId,
                'base' => $base,
                'code' => $code,
                // Services quote "one base buys N of code"; we keep the
                // reverse, because that is the direction anyone reads a rate in.
                'rate' => 1 / (float) $quoted[$code],
                'source' => 'auto',
                'fetched_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // One statement for a hundred and fifty rates rather than a hundred and
        // fifty round trips.
        foreach (array_chunk($rows, 200) as $chunk) {
            ExchangeRate::query()->upsert(
                $chunk,
                ['account_id', 'base', 'code'],
                ['rate', 'source', 'fetched_at', 'updated_at'],
            );
        }

        $this->forget();

        return $this->result(true, count($rows), $skipped, sprintf(
            '%d rate(s) updated against %s%s.',
            count($rows),
            $base,
            $skipped ? ", {$skipped} left alone because you set them by hand" : '',
        ));
    }

    public function forget(): void
    {
        $accountId = $this->tenant->accountId();

        if ($accountId !== null) {
            Cache::forget(self::cacheKey($accountId, $this->base()));
        }
    }

    private function result(bool $ok, int $updated, int $skipped, string $message): array
    {
        return compact('ok', 'updated', 'skipped', 'message');
    }

    private static function cacheKey(int $accountId, string $base): string
    {
        return "rates:{$accountId}:{$base}";
    }
}
