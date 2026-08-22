<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Ledger\Models\JournalEntry;
use App\Domain\Tenancy\Models\Business;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A demo business that has actually traded.
 *
 * ── Why this is a command and not a seeder ───────────────────────────────────
 *
 * A DatabaseSeeder runs on `migrate:fresh --seed` and therefore on every
 * developer's machine and every CI run. This is demo *trading* — orders,
 * takings, expenses — and inventing that by default is the exact fault the
 * dashboard endpoints are written to avoid: a seeded ৳18,500 of revenue reads
 * as a real month to whoever opens the screen. So it is opt-in, named for what
 * it does, and says so on the way out.
 *
 * ── What it writes ───────────────────────────────────────────────────────────
 *
 * Enough of a real shop for every dashboard panel to have something to show:
 * a catalogue, customers, orders spread across the trading day, and a ledger
 * that balances — takings against sales income, cost of goods against
 * inventory, and the monthly bills a business actually pays.
 *
 * Deterministic: the same seed produces the same shop, so a screenshot taken
 * today can be compared with one taken next week.
 */
class SeedDemoTrading extends Command
{
    protected $signature = 'demo:trading
        {--business= : Public id of the business to fill. Defaults to the first active one.}
        {--days=120 : How far back to trade.}
        {--fresh : Clear this business\'s existing trading rows first.}';

    protected $description = 'Fill a business with demo orders, customers and ledger entries.';

    /** Products the shop sells: name, unit price (major), unit cost (major). */
    private const CATALOGUE = [
        ['Aurora Desk Lamp', 2400, 1150],
        ['Meridian Notebook', 450, 160],
        ['Solstice Water Bottle', 890, 380],
        ['Vertex Backpack', 5600, 2700],
        ['Halcyon Desk Mat', 1750, 720],
        ['Cadence Wireless Mouse', 3200, 1600],
    ];

    private const CUSTOMERS = [
        'Farida Rahman', 'Imran Chowdhury', 'Nadia Islam', 'Tanvir Ahmed',
        'Sabrina Karim', 'Rakib Hasan', 'Mehjabin Noor', 'Arif Mahmud',
        'Shirin Akter', 'Zahid Hossain', 'Lubna Siddiqui', 'Omar Faruk',
    ];

    /** Recurring bills: account code, description, monthly amount (major), day of month. */
    private const MONTHLY_BILLS = [
        ['6100', 'Shop rent', 18000, 1],
        ['6450', 'Staff salaries', 42000, 28],
        ['6200', 'Electricity and water', 4200, 5],
        ['6400', 'Software subscriptions', 2600, 12],
    ];

    public function handle(): int
    {
        $business = $this->resolveBusiness();

        if ($business === null) {
            $this->error('No business found. Pass --business=<public_id>.');

            return self::FAILURE;
        }

        $accounts = DB::table('ledger_accounts')
            ->where('business_id', $business->id)
            ->pluck('id', 'code');

        if ($accounts->isEmpty()) {
            $this->error("Business {$business->name} has no chart of accounts to post against.");

            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            $this->clearTrading($business->id);
        }

        // Deterministic, so the same command twice describes the same shop.
        mt_srand(20260816);

        $days = max(1, (int) $this->option('days'));
        $currency = strtoupper((string) $business->base_currency);
        $today = CarbonImmutable::today();
        $start = $today->subDays($days - 1);

        $this->info("Filling {$business->name} ({$currency}) with {$days} days of trading…");

        $variants = $this->seedCatalogue($business, $currency);
        $customers = $this->seedCustomers($business);

        [$orderCount, $revenueMinor] = $this->seedOrders(
            $business,
            $currency,
            $accounts,
            $variants,
            $customers,
            $start,
            $today,
        );

        $billCount = $this->seedBills($business, $currency, $accounts, $start, $today);

        $this->newLine();
        $this->info(sprintf(
            'Done: %d orders (%s %s), %d recurring bills, %d customers, %d products.',
            $orderCount,
            number_format($revenueMinor / 100, 2),
            $currency,
            $billCount,
            count($customers),
            count($variants),
        ));
        $this->comment('These are invented figures for demonstration. Do not ship them to a real account.');

        return self::SUCCESS;
    }

    private function resolveBusiness(): ?Business
    {
        $query = Business::query()->withoutGlobalScopes();

        if ($this->option('business')) {
            return $query->where('public_id', $this->option('business'))->first();
        }

        return $query->where('is_active', true)->orderBy('id')->first();
    }

    /** Trading rows only — the chart of accounts and the business itself stay. */
    private function clearTrading(int $businessId): void
    {
        $this->warn('Clearing existing trading rows…');

        $orderIds = DB::table('orders')->where('business_id', $businessId)->pluck('id');
        DB::table('order_lines')->whereIn('order_id', $orderIds)->delete();
        DB::table('orders')->where('business_id', $businessId)->delete();

        DB::table('invoices')->where('business_id', $businessId)->delete();

        DB::table('journal_lines')->where('business_id', $businessId)->delete();
        DB::table('journal_entries')->where('business_id', $businessId)->delete();

        $productIds = DB::table('products')->where('business_id', $businessId)->pluck('id');
        DB::table('product_variants')->whereIn('product_id', $productIds)->delete();
        DB::table('products')->where('business_id', $businessId)->delete();

        DB::table('customers')->where('business_id', $businessId)->delete();
    }

    /**
     * @return list<array{id: int, sku: string, name: string, price: int, cost: int}>
     */
    private function seedCatalogue(Business $business, string $currency): array
    {
        $now = now();
        $variants = [];

        foreach (self::CATALOGUE as $index => [$name, $price, $cost]) {
            $slug = Str::slug($name);

            $productId = DB::table('products')->insertGetId([
                'public_id' => (string) Str::ulid(),
                'account_id' => $business->account_id,
                'business_id' => $business->id,
                'name' => $name,
                'slug' => $slug,
                'kind' => 'goods',
                'is_stocked' => 1,
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $sku = 'SKU-'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT);

            $variantId = DB::table('product_variants')->insertGetId([
                'public_id' => (string) Str::ulid(),
                'account_id' => $business->account_id,
                'business_id' => $business->id,
                'product_id' => $productId,
                'sku' => $sku,
                'price_minor' => $price * 100,
                'cost_minor' => $cost * 100,
                'currency' => $currency,
                'unit' => 'pcs',
                'is_default' => 1,
                'is_active' => 1,
                'position' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $variants[] = [
                'id' => $variantId,
                'sku' => $sku,
                'name' => $name,
                'price' => $price * 100,
                'cost' => $cost * 100,
            ];
        }

        return $variants;
    }

    /** @return list<int> customer ids */
    private function seedCustomers(Business $business): array
    {
        $now = now();
        $ids = [];

        foreach (self::CUSTOMERS as $name) {
            $ids[] = DB::table('customers')->insertGetId([
                'public_id' => (string) Str::ulid(),
                'account_id' => $business->account_id,
                'business_id' => $business->id,
                'name' => $name,
                'email' => Str::slug($name, '.').'@example.com',
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return $ids;
    }

    /**
     * @param  list<array{id: int, sku: string, name: string, price: int, cost: int}>  $variants
     * @param  list<int>  $customers
     * @return array{0: int, 1: int} order count, revenue in minor units
     */
    private function seedOrders(
        Business $business,
        string $currency,
        \Illuminate\Support\Collection $accounts,
        array $variants,
        array $customers,
        CarbonImmutable $start,
        CarbonImmutable $today,
    ): array {
        $orderNo = 1;
        $invoiceNo = 1;
        $totalRevenue = 0;
        $customerTotals = [];

        for ($day = $start; $day->lte($today); $day = $day->addDay()) {
            // A shop is busier at the weekend and busier as it grows, so the
            // chart has a shape rather than a flat band of noise. Friday and
            // Saturday are the weekend in this market.
            $isWeekend = in_array($day->dayOfWeek, [CarbonImmutable::FRIDAY, CarbonImmutable::SATURDAY], true);
            $growth = 1 + ($start->diffInDays($day) / max(1, $start->diffInDays($today))); // 1 → 2
            $base = $isWeekend ? 5 : 3;
            $count = (int) round(mt_rand(0, $base * 100) / 100 * $growth);

            for ($i = 0; $i < $count; $i++) {
                // Spread across a trading day, so a single-day view has an
                // hourly shape instead of one column at midnight.
                $hour = mt_rand(9, 20);
                $placedAt = $day->setTime($hour, mt_rand(0, 59), mt_rand(0, 59));

                $lineCount = mt_rand(1, 3);
                $picked = (array) array_rand($variants, min($lineCount, count($variants)));

                $subtotal = 0;
                $cost = 0;
                $lines = [];

                foreach ($picked as $lineNo => $variantIndex) {
                    $variant = $variants[$variantIndex];
                    $quantity = mt_rand(1, 3);
                    $lineTotal = $variant['price'] * $quantity;

                    $subtotal += $lineTotal;
                    $cost += $variant['cost'] * $quantity;

                    $lines[] = [
                        'line_no' => $lineNo + 1,
                        'product_variant_id' => $variant['id'],
                        'sku' => $variant['sku'],
                        'description' => $variant['name'],
                        'quantity' => $quantity,
                        'unit_price_minor' => $variant['price'],
                        'total_minor' => $lineTotal,
                        'cost_minor' => $variant['cost'] * $quantity,
                    ];
                }

                // A tenth of orders are called off. They stay in the table —
                // that is what really happens — and every figure that should
                // exclude them does so by `cancelled_at`, which is the thing
                // being demonstrated.
                $isCancelled = mt_rand(1, 10) === 1;
                $isUnpaid = ! $isCancelled && mt_rand(1, 6) === 1;

                $customerId = $customers[array_rand($customers)];

                $orderId = DB::table('orders')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'account_id' => $business->account_id,
                    'business_id' => $business->id,
                    'customer_id' => $customerId,
                    'number' => 'SO-'.str_pad((string) $orderNo++, 5, '0', STR_PAD_LEFT),
                    'ordered_on' => $day->toDateString(),
                    'status' => $isCancelled ? 'cancelled' : ($isUnpaid ? 'confirmed' : 'completed'),
                    'fulfilment_status' => $isCancelled ? 'unfulfilled' : 'fulfilled',
                    'payment_status' => $isCancelled ? 'unpaid' : ($isUnpaid ? 'unpaid' : 'paid'),
                    'channel' => 'manual',
                    'is_cod' => 0,
                    'currency' => $currency,
                    'subtotal_minor' => $subtotal,
                    'total_minor' => $subtotal,
                    'paid_minor' => $isCancelled || $isUnpaid ? 0 : $subtotal,
                    'cost_minor' => $cost,
                    'cancelled_at' => $isCancelled ? $placedAt : null,
                    'fulfilled_at' => $isCancelled ? null : $placedAt,
                    'confirmed_at' => $placedAt,
                    'created_at' => $placedAt,
                    'updated_at' => $placedAt,
                ]);

                foreach ($lines as $line) {
                    DB::table('order_lines')->insert($line + [
                        'order_id' => $orderId,
                        'quantity_fulfilled' => $isCancelled ? 0 : $line['quantity'],
                        'discount_minor' => 0,
                        'tax_rate' => 0,
                        'tax_minor' => 0,
                        'currency' => $currency,
                        'created_at' => $placedAt,
                        'updated_at' => $placedAt,
                    ]);
                }

                if ($isCancelled) {
                    continue;
                }

                $totalRevenue += $subtotal;
                $customerTotals[$customerId] = ($customerTotals[$customerId] ?? ['n' => 0, 'v' => 0]);
                $customerTotals[$customerId]['n']++;
                $customerTotals[$customerId]['v'] += $subtotal;

                // The takings. Paid orders land in the bank; unpaid ones sit
                // in receivables.
                $this->postEntry($business, $currency, $day, $placedAt, 'Sale '.$orderNo, [
                    [$accounts[$isUnpaid ? '1200' : '1100'], $subtotal, 0],
                    [$accounts['4100'], 0, $subtotal],
                ]);

                // An unpaid order gets a real invoice behind it. The
                // receivables KPI ages *invoices*, not orders and not the
                // ledger — so without this the third card reads a confident
                // zero while a sixth of the order list is plainly unpaid.
                if ($isUnpaid) {
                    DB::table('invoices')->insert([
                        'public_id' => (string) Str::ulid(),
                        'account_id' => $business->account_id,
                        'business_id' => $business->id,
                        'customer_id' => $customerId,
                        'number' => 'INV-'.str_pad((string) $invoiceNo++, 5, '0', STR_PAD_LEFT),
                        'issue_date' => $day->toDateString(),
                        // Thirty days to pay, so some of these are current
                        // and the older ones have genuinely fallen overdue —
                        // which is what makes an ageing report worth reading.
                        'due_date' => $day->addDays(30)->toDateString(),
                        'status' => 'issued',
                        'currency' => $currency,
                        'subtotal_minor' => $subtotal,
                        'total_minor' => $subtotal,
                        'paid_minor' => 0,
                        'reference' => 'SO-'.str_pad((string) ($orderNo - 1), 5, '0', STR_PAD_LEFT),
                        'issued_at' => $placedAt,
                        'created_at' => $placedAt,
                        'updated_at' => $placedAt,
                    ]);
                }

                // What those goods cost us.
                $this->postEntry($business, $currency, $day, $placedAt, 'Cost of sale '.$orderNo, [
                    [$accounts['5000'], $cost, 0],
                    [$accounts['1400'], 0, $cost],
                ]);
            }
        }

        foreach ($customerTotals as $customerId => $totals) {
            DB::table('customers')->where('id', $customerId)->update([
                'order_count' => $totals['n'],
                'lifetime_value_minor' => $totals['v'],
                'average_order_minor' => (int) round($totals['v'] / max(1, $totals['n'])),
            ]);
        }

        return [$orderNo - 1, $totalRevenue];
    }

    /** The bills a shop pays whether or not it sold anything. */
    private function seedBills(
        Business $business,
        string $currency,
        \Illuminate\Support\Collection $accounts,
        CarbonImmutable $start,
        CarbonImmutable $today,
    ): int {
        $posted = 0;

        foreach (self::MONTHLY_BILLS as [$code, $description, $amount, $dayOfMonth]) {
            $month = $start->startOfMonth();

            while ($month->lte($today)) {
                $due = $month->setDay(min($dayOfMonth, $month->daysInMonth));

                if ($due->betweenIncluded($start, $today)) {
                    // A little variance, so the expense ring is not six
                    // identical slices month after month.
                    $minor = (int) round($amount * 100 * (mt_rand(92, 108) / 100));

                    $this->postEntry(
                        $business,
                        $currency,
                        $due,
                        $due->setTime(10, 0),
                        $description,
                        [
                            [$accounts[$code], $minor, 0],
                            [$accounts['1100'], 0, $minor],
                        ],
                    );

                    $posted++;
                }

                $month = $month->addMonthNoOverflow();
            }
        }

        // Marketing runs weekly rather than monthly — it is the spend an
        // owner actually varies, so it should look varied.
        for ($week = $start; $week->lte($today); $week = $week->addWeek()) {
            $minor = mt_rand(1200, 4800) * 100;

            $this->postEntry($business, $currency, $week, $week->setTime(11, 0), 'Ad campaign', [
                [$accounts['6300'], $minor, 0],
                [$accounts['1100'], 0, $minor],
            ]);

            $posted++;
        }

        return $posted;
    }

    /**
     * One balanced, posted entry.
     *
     * @param  list<array{0: int, 1: int, 2: int}>  $lines  [accountId, debitMinor, creditMinor]
     */
    private function postEntry(
        Business $business,
        string $currency,
        CarbonImmutable $date,
        CarbonImmutable $at,
        string $description,
        array $lines,
    ): void {
        $debits = array_sum(array_column($lines, 1));
        $credits = array_sum(array_column($lines, 2));

        if ($debits !== $credits) {
            throw new \RuntimeException("Refusing to post an unbalanced entry: {$description}.");
        }

        $entryId = DB::table('journal_entries')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'account_id' => $business->account_id,
            'business_id' => $business->id,
            'entry_date' => $date->toDateString(),
            'description' => $description,
            'status' => JournalEntry::POSTED,
            'source' => 'manual',
            'currency' => $currency,
            'base_currency' => $currency,
            'posted_at' => $at,
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        foreach ($lines as $lineNo => [$accountId, $debit, $credit]) {
            DB::table('journal_lines')->insert([
                'journal_entry_id' => $entryId,
                'ledger_account_id' => $accountId,
                'business_id' => $business->id,
                'entry_date' => $date->toDateString(),
                'line_no' => $lineNo + 1,
                'description' => $description,
                'debit_minor' => $debit,
                'credit_minor' => $credit,
                'currency' => $currency,
                // The ledger's own currency is the business's, so these are
                // the same figures and the rate is 1. Conversion into the
                // workspace's reporting currency happens at read time, from
                // the rate in force — never baked in here.
                'base_debit_minor' => $debit,
                'base_credit_minor' => $credit,
                'base_currency' => $currency,
                'exchange_rate' => 1,
                'created_at' => $at,
                'updated_at' => $at,
            ]);
        }
    }
}
