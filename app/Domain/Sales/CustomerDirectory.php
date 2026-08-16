<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use App\Domain\Sales\Models\Customer;
use App\Domain\Sales\Models\CustomerIdentity;
use App\Domain\Sales\Models\CustomerSegment;
use App\Domain\Sales\Models\Invoice;
use App\Domain\Sales\Models\Order;
use App\Domain\Sales\Models\Payment;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * One customer record per person, however many ways they reached us.
 *
 * ── Matching finds; it does not decide ───────────────────────────────────────
 *
 * identify() returns candidates with a reason. It never merges on its own, and
 * that restraint is the whole design. An automatic merge on a shared phone
 * number joins a husband and wife, a shop and its owner, two colleagues who
 * gave the office line. Undoing that after three months of orders have piled
 * onto one record is a bad afternoon; not doing it is a click.
 *
 * A verified identity is the exception — somebody who clicked a link in an
 * email or answered a code on a number has proved it is theirs, and a match on
 * one of those is worth acting on.
 *
 * ── Merging keeps the loser ──────────────────────────────────────────────────
 *
 * Marked, pointed at the survivor, and left in place. Orders, invoices and
 * messages may still hold the old id, and a merge is a judgement that is
 * sometimes wrong — the record is what makes it reversible.
 */
final class CustomerDirectory
{
    public function __construct(
        private readonly TenantContext $tenant,
    ) {}

    /**
     * Attach a way of recognising somebody.
     *
     * Idempotent, and refuses to move an identity that already belongs to a
     * different customer — that is a merge, and it must be asked for
     * deliberately rather than happening as a side effect of saving a form.
     */
    public function addIdentity(
        Customer $customer,
        string $kind,
        string $value,
        array $options = [],
    ): CustomerIdentity {
        $normalised = CustomerIdentity::normalise($kind, $value);

        if ($normalised === '') {
            throw new RuntimeException('There is nothing there to recognise somebody by.');
        }

        $existing = CustomerIdentity::query()
            ->where('kind', $kind)
            ->where('normalised', $normalised)
            ->where('source', $options['source'] ?? null)
            ->first();

        if ($existing !== null && $existing->customer_id !== $customer->id) {
            throw new RuntimeException(sprintf(
                'That %s already belongs to another customer. Merge the two records if they are the same person.',
                $kind,
            ));
        }

        if ($existing !== null) {
            $existing->forceFill([
                'value' => $value,
                'is_verified' => $existing->is_verified || ($options['verified'] ?? false),
                'last_seen_at' => now(),
            ])->save();

            return $existing;
        }

        return CustomerIdentity::create([
            'customer_id' => $customer->id,
            'kind' => $kind,
            'value' => $value,
            'normalised' => $normalised,
            'source' => $options['source'] ?? null,
            'is_verified' => $options['verified'] ?? false,
            'is_primary' => $options['primary'] ?? ! $customer->identities()->where('kind', $kind)->exists(),
            'last_seen_at' => now(),
        ]);
    }

    /**
     * Who might this be?
     *
     * @param  array<string, string>  $traits  kind => value
     * @return list<array{customer: Customer, confidence: string, matched_on: list<string>}>
     */
    public function identify(array $traits): array
    {
        $hits = [];

        foreach ($traits as $kind => $value) {
            if ($value === null || trim((string) $value) === '') {
                continue;
            }

            $normalised = CustomerIdentity::normalise($kind, (string) $value);

            if ($normalised === '') {
                continue;
            }

            $found = CustomerIdentity::query()
                ->where('kind', $kind)
                ->where('normalised', $normalised)
                ->with('customer')
                ->get();

            foreach ($found as $identity) {
                if ($identity->customer === null || $identity->customer->isMerged()) {
                    continue;
                }

                $id = $identity->customer_id;
                $hits[$id] ??= ['customer' => $identity->customer, 'matched' => [], 'verified' => false];
                $hits[$id]['matched'][] = $kind;
                $hits[$id]['verified'] = $hits[$id]['verified'] || $identity->is_verified;
            }
        }

        $out = [];

        foreach ($hits as $hit) {
            $out[] = [
                'customer' => $hit['customer'],
                // Two independent identities agreeing, or one they have proved
                // is theirs, is worth acting on. A single unverified phone
                // number is worth showing to a person and no more.
                'confidence' => count($hit['matched']) > 1 || $hit['verified'] ? 'strong' : 'weak',
                'matched_on' => array_values(array_unique($hit['matched'])),
            ];
        }

        usort($out, fn ($a, $b) => ($b['confidence'] === 'strong' ? 1 : 0) <=> ($a['confidence'] === 'strong' ? 1 : 0));

        return $out;
    }

    /**
     * Find them, or make them.
     *
     * The call every channel makes — the till, the storefront, an inbound
     * WhatsApp message. Only a strong match is reused; a weak one creates a
     * separate record and leaves the duplicate for somebody to confirm, because
     * a wrongly joined customer is much harder to undo than a duplicate one.
     *
     * @param  array<string, string>  $traits
     */
    public function resolve(array $traits, array $attributes = []): Customer
    {
        foreach ($this->identify($traits) as $candidate) {
            if ($candidate['confidence'] === 'strong') {
                return $candidate['customer'];
            }
        }

        return DB::transaction(function () use ($traits, $attributes) {
            $customer = Customer::create([
                'name' => $attributes['name'] ?? $traits['email'] ?? $traits['phone'] ?? 'Unknown customer',
                'email' => $traits['email'] ?? null,
                'phone' => $traits['phone'] ?? null,
                ...$attributes,
            ]);

            foreach ($traits as $kind => $value) {
                if ($value === null || trim((string) $value) === '') {
                    continue;
                }

                try {
                    $this->addIdentity($customer, $kind, (string) $value);
                } catch (RuntimeException) {
                    // Taken by somebody else — a weak match we deliberately did
                    // not act on. The trait cannot become an identity, because
                    // identities are unique and this one is already spoken for.
                    //
                    // It is recorded on the customer's own columns instead, so
                    // duplicates() can still find the pair. Swallowing it
                    // entirely left the duplicate queue empty in precisely the
                    // case it exists for: two records the machine could see
                    // were related and deliberately declined to join.
                    if (in_array($kind, [CustomerIdentity::EMAIL, CustomerIdentity::PHONE], true)
                        && ($customer->{$kind} === null || $customer->{$kind} === '')) {
                        $customer->forceFill([$kind => (string) $value])->save();
                    }
                }
            }

            return $customer->refresh();
        });
    }

    /**
     * Fold one customer into another.
     *
     * Everything moves to the survivor and the loser stays as a tombstone.
     *
     * @return Customer the survivor
     */
    public function merge(Customer $keep, Customer $lose): Customer
    {
        if ($keep->id === $lose->id) {
            throw new RuntimeException('That is the same customer.');
        }

        if ($lose->isMerged()) {
            throw new RuntimeException("{$lose->name} has already been merged into another record.");
        }

        if ($keep->isMerged()) {
            throw new RuntimeException("{$keep->name} is itself a merged record. Merge into the surviving one instead.");
        }

        return DB::transaction(function () use ($keep, $lose) {
            // Identities move where they can. One the survivor already has is
            // dropped rather than failing the whole merge on a unique index —
            // two records sharing an email is the commonest reason to merge.
            foreach ($lose->identities()->get() as $identity) {
                $clash = CustomerIdentity::query()
                    ->where('customer_id', $keep->id)
                    ->where('kind', $identity->kind)
                    ->where('normalised', $identity->normalised)
                    ->exists();

                if ($clash) {
                    $identity->delete();

                    continue;
                }

                $identity->forceFill(['customer_id' => $keep->id, 'is_primary' => false])->save();
            }

            Order::where('customer_id', $lose->id)->update(['customer_id' => $keep->id]);
            Invoice::where('customer_id', $lose->id)->update(['customer_id' => $keep->id]);
            Payment::where('customer_id', $lose->id)->update(['customer_id' => $keep->id]);

            // Anything the survivor is missing, taken from the loser. A record
            // made at a till has a phone and no address; the one from the
            // storefront has an address and no phone. Merging should end with
            // both.
            $fill = [];

            foreach (['email', 'phone', 'company', 'tax_number', 'billing_address',
                'billing_city', 'billing_postcode', 'billing_country'] as $field) {
                if (($keep->{$field} === null || $keep->{$field} === '') && $lose->{$field} !== null) {
                    $fill[$field] = $lose->{$field};
                }
            }

            if ($fill !== []) {
                $keep->forceFill($fill)->save();
            }

            $lose->forceFill([
                'merged_into_id' => $keep->id,
                'merged_at' => now(),
                'is_active' => false,
            ])->save();

            $this->refreshStats($keep->refresh());

            return $keep->refresh();
        });
    }

    /**
     * Rebuild a customer's rolled-up figures from their orders.
     *
     * The check that keeps the stored numbers honest — the same bargain as
     * stock levels. Cancelled orders are excluded; an order somebody placed and
     * withdrew is not lifetime value.
     */
    public function refreshStats(Customer $customer): Customer
    {
        $orders = Order::query()
            ->where('customer_id', $customer->id)
            ->whereNotIn('status', [Order::CANCELLED, Order::DRAFT])
            ->get(['total_minor', 'ordered_on']);

        $count = $orders->count();
        $total = (int) $orders->sum('total_minor');

        $customer->forceFill([
            'order_count' => $count,
            'lifetime_value_minor' => $total,
            'average_order_minor' => $count === 0 ? 0 : (int) round($total / $count),
            'first_order_on' => $orders->min('ordered_on'),
            'last_order_on' => $orders->max('ordered_on'),
            'return_count' => DB::table('goods_returns')
                ->where('customer_id', $customer->id)
                ->whereNot('status', 'cancelled')
                ->count(),
        ])->save();

        return $customer->refresh();
    }

    /**
     * Everybody in a segment.
     *
     * Rules are structured and translated here, never interpolated. The set of
     * fields that can be filtered on is fixed by this method — which is what
     * makes a subscriber-authored segment safe to run.
     *
     * @return Builder<Customer>
     */
    public function segmentQuery(CustomerSegment $segment): Builder
    {
        $query = Customer::query()->real()->where('is_active', true);

        foreach ($segment->rules['all'] ?? [] as $rule) {
            $this->applyRule($query, $rule);
        }

        if (($segment->rules['any'] ?? []) !== []) {
            $query->where(function (Builder $inner) use ($segment) {
                foreach ($segment->rules['any'] as $rule) {
                    $inner->orWhere(fn (Builder $w) => $this->applyRule($w, $rule));
                }
            });
        }

        return $query;
    }

    public function countSegment(CustomerSegment $segment): int
    {
        $count = $this->segmentQuery($segment)->count();

        $segment->forceFill(['member_count' => $count, 'counted_at' => now()])->save();

        return $count;
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    private function applyRule(Builder $query, array $rule): Builder
    {
        $field = $rule['field'] ?? null;
        $op = $rule['op'] ?? '=';
        $value = $rule['value'] ?? null;

        return match ($field) {
            'lifetime_value' => $query->where('lifetime_value_minor', $this->operator($op), (int) $value),
            'order_count' => $query->where('order_count', $this->operator($op), (int) $value),
            'average_order' => $query->where('average_order_minor', $this->operator($op), (int) $value),
            'return_count' => $query->where('return_count', $this->operator($op), (int) $value),
            // Days since, expressed as a date so the index is used. "Not
            // ordered in 90 days" also has to catch people who never ordered,
            // which a plain date comparison silently excludes.
            'days_since_order' => $op === '>'
                ? $query->where(fn (Builder $w) => $w
                    ->whereNull('last_order_on')
                    ->orWhereDate('last_order_on', '<', now()->subDays((int) $value)->toDateString()))
                : $query->whereDate('last_order_on', '>=', now()->subDays((int) $value)->toDateString()),
            'country' => $query->where('billing_country', $value),
            'has_email' => $query->whereNotNull('email'),
            'has_phone' => $query->whereNotNull('phone'),
            'never_ordered' => $query->where('order_count', 0),
            // An unknown field matches nothing rather than everything. A typo
            // in a rule should produce an empty campaign, not one sent to the
            // entire customer base.
            default => $query->whereRaw('1 = 0'),
        };
    }

    private function operator(string $op): string
    {
        return match ($op) {
            '>', 'gt' => '>',
            '>=', 'gte' => '>=',
            '<', 'lt' => '<',
            '<=', 'lte' => '<=',
            '!=', 'not' => '!=',
            default => '=',
        };
    }

    /**
     * Records that look like the same person and have not been merged.
     *
     * @return list<array{a: Customer, b: Customer, matched_on: string}>
     */
    public function duplicates(int $limit = 50): array
    {
        $rows = DB::table('customer_identities as a')
            ->join('customer_identities as b', function ($join) {
                $join->on('a.kind', '=', 'b.kind')
                    ->on('a.normalised', '=', 'b.normalised')
                    ->on('a.customer_id', '<', 'b.customer_id');
            })
            ->where('a.business_id', $this->businessId())
            ->select('a.customer_id as left_id', 'b.customer_id as right_id', 'a.kind')
            ->distinct()
            ->limit($limit)
            ->get();

        $out = [];
        $seen = [];

        foreach ($rows as $row) {
            $a = Customer::find($row->left_id);
            $b = Customer::find($row->right_id);

            if ($a === null || $b === null || $a->isMerged() || $b->isMerged()) {
                continue;
            }

            $seen["{$row->left_id}-{$row->right_id}"] = true;
            $out[] = ['a' => $a, 'b' => $b, 'matched_on' => $row->kind];
        }

        // Also the pairs where one side holds the trait as an identity and the
        // other only on its own column — which is exactly what resolve() leaves
        // behind when it declines a weak match. Without this pass the queue
        // misses the duplicates the system itself created.
        $candidates = Customer::query()->real()
            ->where(fn ($w) => $w->whereNotNull('email')->orWhereNotNull('phone'))
            ->get(['id', 'name', 'email', 'phone']);

        foreach ($candidates as $customer) {
            foreach ([CustomerIdentity::EMAIL => $customer->email, CustomerIdentity::PHONE => $customer->phone] as $kind => $value) {
                if ($value === null || $value === '') {
                    continue;
                }

                $normalised = CustomerIdentity::normalise($kind, $value);

                $others = CustomerIdentity::query()
                    ->where('kind', $kind)
                    ->where('normalised', $normalised)
                    ->where('customer_id', '!=', $customer->id)
                    ->pluck('customer_id');

                foreach ($others as $otherId) {
                    [$left, $right] = $customer->id < $otherId ? [$customer->id, $otherId] : [$otherId, $customer->id];
                    $key = "{$left}-{$right}";

                    if (isset($seen[$key]) || count($out) >= $limit) {
                        continue;
                    }

                    $a = Customer::find($left);
                    $b = Customer::find($right);

                    if ($a === null || $b === null || $a->isMerged() || $b->isMerged()) {
                        continue;
                    }

                    $seen[$key] = true;
                    $out[] = ['a' => $a, 'b' => $b, 'matched_on' => $kind];
                }
            }
        }

        return $out;
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
