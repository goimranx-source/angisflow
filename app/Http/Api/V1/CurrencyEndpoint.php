<?php

declare(strict_types=1);

namespace App\Http\Api\V1;

use App\Domain\Money\Currencies;
use App\Domain\Money\CurrencyService;
use App\Domain\Settings\Settings;
use App\Domain\Tenancy\TenantContext;
use App\Http\Api\Endpoint;
use App\Support\BootPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The rates behind the currency tab.
 *
 * Separate from SettingsEndpoint because these are rows rather than settings:
 * they are added, edited and deleted one at a time, and there can be a hundred
 * and fifty of them. Folding them into a settings group would mean sending the
 * lot on every save of an unrelated toggle.
 */
class CurrencyEndpoint extends Endpoint
{
    public function __construct(
        private readonly CurrencyService $currency,
        private readonly Settings $settings,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * Change what the books are counted in.
     *
     * ── Why this is not just a setting ───────────────────────────────────────
     *
     * Moving the base currency changes what every stored figure is *worth*, so
     * the derived amounts have to be recomputed from what actually changed
     * hands. There is no version of this anybody would want where the label
     * moves and the numbers do not — offering one is how ৳300 becomes $300.
     *
     * Today there are no transactional tables to rebuild, so this records the
     * change and stamps the time. The rebuild is wired in with the Ledger, and
     * the stamp is what tells the screen when it last happened.
     */
    public function setBase(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'base' => ['required', 'string', 'size:3', Rule::in(array_keys(Currencies::ALL))],
        ]);

        $account = $this->tenant->account();

        abort_if($account === null, 404);

        $from = $account->base_currency;
        $to = strtoupper($validated['base']);

        if ($from === $to) {
            return response()->json(['message' => "Already counting in {$to}."]);
        }

        $account->forceFill(['base_currency' => $to])->save();

        $this->settings->set('currency.rebuilt_at', now()->toIso8601String());
        $this->currency->forget();

        return response()->json([
            'message' => "Now counting in {$to} instead of {$from}. Every figure is worked out again "
                .'from what actually changed hands, so nothing recorded has moved.',
            'boot' => BootPayload::build(),
        ]);
    }

    /** A rate typed by hand, which no fetch will overwrite. */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'size:3', Rule::in(array_keys(Currencies::ALL))],
            'rate' => ['required', 'numeric', 'gt:0'],
        ], [
            'rate.gt' => 'A rate has to be greater than zero — a zero rate would value every sale at nothing.',
        ]);

        abort_if(
            strtoupper($validated['code']) === $this->currency->base(),
            422,
        );

        $this->currency->setManualRate($validated['code'], (float) $validated['rate']);

        return response()->json([
            'message' => strtoupper($validated['code']).' rate saved.',
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'size:3'],
        ]);

        $this->currency->forgetRate($validated['code']);

        return response()->json(['message' => 'Rate removed.']);
    }

    /**
     * Fetch today's rates.
     *
     * Throttled hard on its route: it calls out to somebody else's server while
     * a user watches a button, and nobody needs to do that six times a minute.
     */
    public function refresh(): JsonResponse
    {
        $result = $this->currency->refresh();

        return response()->json(
            ['message' => $result['message'], 'updated' => $result['updated']],
            $result['ok'] ? 200 : 422,
        );
    }
}
