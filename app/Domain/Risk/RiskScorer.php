<?php

declare(strict_types=1);

namespace App\Domain\Risk;

use App\Domain\Delivery\Models\Shipment;
use App\Domain\Delivery\ShipmentStatus;
use App\Domain\Risk\Models\CustomerRiskFlag;
use App\Domain\Risk\Models\CustomerRiskProfile;
use App\Domain\Sales\Models\Customer;
use App\Domain\Sales\Models\CustomerIdentity;
use App\Domain\Sales\Models\Order;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Who is likely to cost money, and the sentence that says why.
 *
 * ── Every signal carries its own evidence ────────────────────────────────────
 *
 * Not a weight and a score, but a weight, a score and a sentence a person can
 * read. The whole difference between a risk system that gets used and one that
 * gets ignored is whether the operator can see what it noticed. "73" is
 * dismissed; "4 of their last 6 parcels came back undelivered, and this order
 * is the largest they have placed" is acted on.
 *
 * ── Weights are the subscriber's, not ours ───────────────────────────────────
 *
 * A 30% return rate is ordinary for fashion in one market and alarming for
 * electronics in another. Shipping fixed weights would be wrong for most
 * subscribers in one direction or the other, so what ships is defaults that can
 * be tuned — and a signal a business does not care about can be switched off
 * rather than argued with.
 *
 * ── Nothing here blocks anything ─────────────────────────────────────────────
 *
 * assess() returns advice. A false positive turns away a real customer and the
 * business never learns it happened, so the machine's opinion can require
 * prepayment or ask for a confirmation call — all reversible — and only an
 * explicit human block refuses an order outright.
 */
final class RiskScorer
{
    /**
     * The signals, with what each is worth by default and where it starts to
     * count.
     *
     * @var array<string, array{weight: int, threshold: float|null, label: string}>
     */
    public const SIGNALS = [
        // The big one in a cash-on-delivery market: parcels that came back
        // because nobody took them.
        'rto_rate' => ['weight' => 30, 'threshold' => 0.34, 'label' => 'Parcels returned undelivered'],
        'rto_count' => ['weight' => 15, 'threshold' => 2, 'label' => 'Repeated failed deliveries'],
        'failed_attempts' => ['weight' => 10, 'threshold' => 3, 'label' => 'Riders unable to hand over'],
        'return_rate' => ['weight' => 12, 'threshold' => 0.4, 'label' => 'Goods sent back after delivery'],
        // The classic fraud shape: no history, large, and nothing paid up
        // front. Weighted at 25 rather than 20 after watching it fire: at 20 it
        // reached 28 alongside an unverified contact, two points under the
        // watch threshold, so the single most predictive signal in the set
        // produced no action at all. A signal that cannot change an outcome is
        // decoration. At 25 the same order asks for a confirmation call —
        // which is the proportionate response, not a refusal.
        'new_and_large_cod' => ['weight' => 25, 'threshold' => null, 'label' => 'First order, large, cash on delivery'],
        'unverified_contact' => ['weight' => 8, 'threshold' => null, 'label' => 'No confirmed way to reach them'],
        'velocity' => ['weight' => 15, 'threshold' => 3, 'label' => 'Several orders in a short window'],
        // One phone across several customer records is either a family, an
        // office, or somebody making themselves hard to recognise.
        'shared_identity' => ['weight' => 10, 'threshold' => 2, 'label' => 'Contact details shared with other records'],
        'high_cod_exposure' => ['weight' => 12, 'threshold' => null, 'label' => 'A lot already out on delivery'],
    ];

    /** Where a total stops being ordinary. */
    private const WATCH_AT = 30;

    private const HIGH_AT = 60;

    public function __construct(
        private readonly TenantContext $tenant,
    ) {}

    /**
     * Work out and store a customer's profile.
     */
    public function score(Customer $customer): CustomerRiskProfile
    {
        $facts = $this->gather($customer);
        $weights = $this->weights();

        $signals = [];
        $total = 0;

        foreach ($this->evaluate($customer, $facts) as $key => $finding) {
            $config = $weights[$key] ?? null;

            if ($config === null || ! $config['enabled']) {
                continue;
            }

            // A signal fires or it does not; the weight is what it is worth.
            // Scaling by "how badly" it fired sounds cleverer and produces a
            // number nobody can predict or explain.
            $points = $config['weight'];
            $total += $points;

            $signals[] = [
                'signal' => $key,
                'label' => self::SIGNALS[$key]['label'] ?? $key,
                'points' => $points,
                'evidence' => $finding,
            ];
        }

        // An explicit human decision overrules the arithmetic entirely, in
        // either direction. Somebody who has looked at this customer knows more
        // than the signals do.
        $flags = $this->activeFlags($customer);

        if ($flags->contains('kind', CustomerRiskFlag::TRUSTED)) {
            $total = 0;
            $signals = [['signal' => 'trusted', 'label' => 'Marked trusted', 'points' => 0,
                'evidence' => 'Somebody has vouched for this customer']];
        }

        $score = min(100, $total);

        return DB::transaction(function () use ($customer, $facts, $signals, $score, $flags) {
            $profile = CustomerRiskProfile::firstOrNew(['customer_id' => $customer->id]);

            $profile->fill([
                'business_id' => $customer->business_id,
                'score' => $score,
                'band' => $this->band($score, $flags),
                'signals' => $signals,
                'orders_total' => $facts['orders_total'],
                'orders_delivered' => $facts['delivered'],
                'orders_rto' => $facts['rto'],
                'orders_returned' => $facts['returned'],
                'failed_attempts' => $facts['failed_attempts'],
                'cod_exposure_minor' => $facts['cod_exposure'],
                'computed_at' => now(),
            ])->save();

            return $profile->refresh();
        });
    }

    /**
     * What to do about an order somebody is about to place.
     *
     * The call a checkout, a till or an order screen makes. Advice, with the
     * reasons attached — never a silent refusal.
     *
     * @return array{decision: string, score: int, band: string, reasons: list<string>, message: ?string}
     */
    public function assess(Customer $customer, ?Money $orderValue = null, bool $isCod = false): array
    {
        $flags = $this->activeFlags($customer);

        if ($flags->contains('kind', CustomerRiskFlag::BLOCK)) {
            $flag = $flags->firstWhere('kind', CustomerRiskFlag::BLOCK);

            return [
                'decision' => 'refuse',
                'score' => 100,
                'band' => CustomerRiskProfile::HIGH,
                'reasons' => [$flag->reason],
                // Names the human decision rather than implying a machine
                // judged them. Somebody has to be able to say who decided this
                // and undo it.
                'message' => 'This customer has been blocked: '.$flag->reason,
            ];
        }

        $profile = $customer->riskProfile ?? $this->score($customer);

        if ($isCod && $flags->contains('kind', CustomerRiskFlag::PREPAY_ONLY)) {
            return [
                'decision' => 'require_prepayment',
                'score' => $profile->score,
                'band' => $profile->band,
                'reasons' => [$flags->firstWhere('kind', CustomerRiskFlag::PREPAY_ONLY)->reason],
                'message' => 'This customer pays in advance.',
            ];
        }

        $reasons = $profile->reasons();

        // Only cash on delivery is worth intervening over on score alone. A
        // prepaid order from a risky customer costs nothing to accept — the
        // money is already there — and refusing it is pure lost revenue.
        $decision = match (true) {
            $isCod && $profile->band === CustomerRiskProfile::HIGH => 'require_prepayment',
            $isCod && $profile->band === CustomerRiskProfile::WATCH => 'confirm_first',
            default => 'accept',
        };

        return [
            'decision' => $decision,
            'score' => $profile->score,
            'band' => $profile->band,
            'reasons' => $reasons,
            'message' => match ($decision) {
                'require_prepayment' => 'Ask for payment up front — '.($reasons[0] ?? 'high risk of a failed delivery').'.',
                'confirm_first' => 'Worth a confirmation call before despatch — '.($reasons[0] ?? 'some risk').'.',
                default => null,
            },
        ];
    }

    /**
     * Record a decision about a customer.
     */
    public function flag(Customer $customer, string $kind, string $reason, ?string $expiresAt = null): CustomerRiskFlag
    {
        if (trim($reason) === '') {
            // The reason is the whole value of the flag. A block with no reason
            // is one nobody can review, defend or lift.
            throw new RuntimeException('A risk flag needs a reason — somebody will have to justify it later.');
        }

        return DB::transaction(function () use ($customer, $kind, $reason, $expiresAt) {
            $flag = CustomerRiskFlag::create([
                'customer_id' => $customer->id,
                'kind' => $kind,
                'reason' => $reason,
                'expires_at' => $expiresAt,
                'created_by' => auth()->id(),
            ]);

            $this->score($customer->refresh());

            return $flag;
        });
    }

    public function lift(CustomerRiskFlag $flag, ?string $reason = null): CustomerRiskFlag
    {
        if (! $flag->isActive()) {
            throw new RuntimeException('That flag is not active.');
        }

        $flag->forceFill([
            'lifted_at' => now(),
            'lifted_reason' => $reason,
            'lifted_by' => auth()->id(),
        ])->save();

        $this->score($flag->customer);

        return $flag->refresh();
    }

    /**
     * The facts every signal is drawn from.
     *
     * @return array<string, mixed>
     */
    private function gather(Customer $customer): array
    {
        $orders = Order::query()
            ->where('customer_id', $customer->id)
            ->whereNot('status', Order::DRAFT)
            ->get(['id', 'total_minor', 'is_cod', 'ordered_on', 'status']);

        $shipments = Shipment::query()
            ->whereIn('order_id', $orders->pluck('id'))
            ->get(['status', 'attempt_count', 'is_cod', 'cod_amount_minor', 'cod_settled_minor']);

        return [
            'orders_total' => $orders->count(),
            'orders' => $orders,
            'delivered' => $shipments->where('status', ShipmentStatus::DELIVERED->value)->count(),
            'rto' => $shipments->whereIn('status', [
                ShipmentStatus::RETURNED->value, ShipmentStatus::RETURNING->value,
            ])->count(),
            'returned' => $customer->return_count,
            'failed_attempts' => (int) $shipments->sum('attempt_count'),
            // What is on the road right now with money attached — the amount
            // actually at stake if this customer turns out to be a problem.
            'cod_exposure' => (int) $shipments
                ->filter(fn ($s) => $s->is_cod && (ShipmentStatus::tryFrom($s->status) ?? ShipmentStatus::UNKNOWN)->isInFlight())
                ->sum('cod_amount_minor'),
            'recent_orders' => $orders->filter(
                fn ($o) => $o->ordered_on !== null && $o->ordered_on->gt(now()->subDays(7)),
            )->count(),
        ];
    }

    /**
     * Which signals fire, and the sentence for each.
     *
     * @param  array<string, mixed>  $facts
     * @return array<string, string>  signal => evidence
     */
    private function evaluate(Customer $customer, array $facts): array
    {
        $found = [];
        $settled = $facts['delivered'] + $facts['rto'];
        $config = self::SIGNALS;

        if ($settled >= 3 && $facts['rto'] / $settled >= $config['rto_rate']['threshold']) {
            $found['rto_rate'] = sprintf(
                '%d of their last %d parcels came back undelivered',
                $facts['rto'], $settled,
            );
        }

        if ($facts['rto'] > $config['rto_count']['threshold']) {
            $found['rto_count'] = sprintf('%d deliveries have failed outright', $facts['rto']);
        }

        if ($facts['failed_attempts'] > $config['failed_attempts']['threshold']) {
            $found['failed_attempts'] = sprintf(
                'Riders have been unable to hand over %d times', $facts['failed_attempts'],
            );
        }

        if ($customer->order_count >= 3 && $facts['returned'] / max(1, $customer->order_count) >= $config['return_rate']['threshold']) {
            $found['return_rate'] = sprintf(
                '%d of %d orders were sent back', $facts['returned'], $customer->order_count,
            );
        }

        // No history, large, and nothing paid up front. Each of those is
        // unremarkable alone; together they are the shape almost every fake
        // order takes.
        $largest = $facts['orders']->max('total_minor') ?? 0;
        $codOrders = $facts['orders']->where('is_cod', true)->count();

        if ($facts['orders_total'] <= 1 && $codOrders > 0 && $largest >= 500000) {
            $found['new_and_large_cod'] = sprintf(
                'First order, %s, on delivery',
                (new Money((int) $largest, $customer->currency ?? 'USD'))->toDecimalString(),
            );
        }

        if (! $customer->identities()->where('is_verified', true)->exists()) {
            $found['unverified_contact'] = 'Nothing about their contact details has been confirmed';
        }

        if ($facts['recent_orders'] > $config['velocity']['threshold']) {
            $found['velocity'] = sprintf('%d orders in the last week', $facts['recent_orders']);
        }

        $shared = $this->sharedIdentityCount($customer);

        if ($shared >= $config['shared_identity']['threshold']) {
            $found['shared_identity'] = sprintf(
                'Their contact details also appear on %d other customer records', $shared,
            );
        }

        if ($facts['cod_exposure'] >= 1000000) {
            $found['high_cod_exposure'] = sprintf(
                '%s is already out with couriers for this customer',
                (new Money($facts['cod_exposure'], $customer->currency ?? 'USD'))->toDecimalString(),
            );
        }

        return $found;
    }

    private function sharedIdentityCount(Customer $customer): int
    {
        $normalised = $customer->identities()->pluck('normalised');

        if ($normalised->isEmpty()) {
            return 0;
        }

        return CustomerIdentity::query()
            ->whereIn('normalised', $normalised)
            ->where('customer_id', '!=', $customer->id)
            ->distinct()
            ->count('customer_id');
    }

    /**
     * @return array<string, array{weight: int, enabled: bool}>
     */
    private function weights(): array
    {
        $stored = DB::table('risk_signal_weights')
            ->where('business_id', $this->businessId())
            ->get()
            ->keyBy('signal');

        $out = [];

        foreach (self::SIGNALS as $key => $default) {
            $row = $stored[$key] ?? null;

            $out[$key] = [
                'weight' => (int) ($row->weight ?? $default['weight']),
                'enabled' => $row === null ? true : (bool) $row->is_enabled,
            ];
        }

        return $out;
    }

    private function activeFlags(Customer $customer)
    {
        return CustomerRiskFlag::query()
            ->where('customer_id', $customer->id)
            ->active()
            ->get();
    }

    private function band(int $score, $flags): string
    {
        if ($flags->contains('kind', CustomerRiskFlag::TRUSTED)) {
            return CustomerRiskProfile::TRUSTED;
        }

        return match (true) {
            $score >= self::HIGH_AT => CustomerRiskProfile::HIGH,
            $score >= self::WATCH_AT => CustomerRiskProfile::WATCH,
            default => CustomerRiskProfile::NORMAL,
        };
    }

    private function businessId(): int
    {
        $business = $this->tenant->business();

        if ($business === null) {
            throw new RuntimeException('No set of books is open.');
        }

        return $business->id;
    }
}
