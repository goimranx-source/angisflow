<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Proves — or disproves — the central claim of the tenancy design.
 *
 * The claim is not "these queries are fast". Anything is fast on a small table.
 * The claim is that a subscriber's query costs **the same** whether the table
 * holds ten thousand rows or ten billion, because every index leads with
 * account_id and the database therefore seeks straight to that subscriber's
 * slice instead of reading past everybody else's.
 *
 * That is a claim about a *curve*, so a single measurement cannot settle it.
 * This grows the table step by step and reports the same queries at each size.
 * A flat column is the design working. A rising one is the design failing, and
 * would be worth knowing before a customer finds out.
 *
 *   php artisan prism:benchmark-tenancy
 *   php artisan prism:benchmark-tenancy --sizes=50000,200000,800000
 */
class BenchmarkTenancy extends Command
{
    protected $signature = 'prism:benchmark-tenancy
        {--sizes=25000,100000,400000 : table sizes to measure at}
        {--per-account=40 : rows per subscriber}
        {--keep : leave the fabricated rows behind}';

    protected $description = 'Show how a tenant-scoped read behaves as the table grows.';

    public function handle(): int
    {
        $sizes = array_map('intval', explode(',', (string) $this->option('sizes')));
        $perAccount = (int) $this->option('per-account');

        sort($sizes);

        $this->newLine();
        $this->warn('Driver: '.DB::connection()->getDriverName().'. SQLite has a different planner and no');
        $this->warn('buffer pool, so the absolute milliseconds mean little. The *shape* of');
        $this->warn('each column is the finding, and that carries over to MySQL.');
        $this->newLine();

        DB::table('bench_rows')->truncate();

        $rows = [];
        $seeded = 0;

        foreach ($sizes as $size) {
            $this->info("Growing the table to ".number_format($size)." rows…");
            $seeded = $this->growTo($seeded, $size, $perAccount);

            $accounts = intdiv($seeded, $perAccount);
            // A subscriber in the middle of the range, so neither the first nor
            // the last page of the index — the honest middle case.
            $subject = max(1, intdiv($accounts, 2));

            $rows[] = [
                number_format($seeded),
                number_format($accounts),
                number_format($this->time(fn () => DB::table('bench_rows')
                    ->where('account_id', $subject)
                    ->where('status', 'delivered')
                    ->orderByDesc('id')
                    ->limit(25)
                    ->get()), 3),
                number_format($this->time(fn () => DB::table('bench_rows')
                    ->where('account_id', $subject)
                    ->sum('total_minor')), 3),
                number_format($this->time(fn () => DB::table('bench_rows')
                    ->sum('total_minor')), 3),
            ];
        }

        $this->newLine();
        $this->table(
            ['Rows', 'Accounts', 'Scoped list (ms)', 'Scoped SUM (ms)', 'Unscoped SUM (ms)'],
            $rows,
        );

        $this->newLine();
        $this->line('<options=bold>Scoped</> columns are what every screen in Prism runs.');
        $this->line('<options=bold>Unscoped</> is the same aggregate without a tenant — the shape of any');
        $this->line('report that reads the whole table, which is what a rollup exists to avoid.');

        if (! $this->option('keep')) {
            DB::table('bench_rows')->truncate();
            $this->newLine();
            $this->comment('Fabricated rows removed. Pass --keep to leave them.');
        }

        return self::SUCCESS;
    }

    /** Add rows until the table holds $target of them. */
    private function growTo(int $current, int $target, int $perAccount): int
    {
        $nextAccount = intdiv($current, $perAccount) + 1;
        $bar = $this->output->createProgressBar(intdiv($target - $current, $perAccount));

        while ($current < $target) {
            $batch = [];

            for ($i = 0; $i < $perAccount; $i++) {
                $batch[] = [
                    'account_id' => $nextAccount,
                    'business_id' => 1 + ($i % 3),
                    'public_id' => strtolower((string) Str::ulid()),
                    'status' => ['pending', 'shipped', 'delivered', 'cancelled'][$i % 4],
                    'total_minor' => random_int(50_000, 900_000),
                    'occurred_on' => now()->subDays($i % 365)->toDateString(),
                ];
            }

            DB::table('bench_rows')->insert($batch);

            $current += $perAccount;
            $nextAccount++;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        return $current;
    }

    /** Best of five, so one unlucky moment does not decide the answer. */
    private function time(callable $query): float
    {
        $best = INF;

        for ($i = 0; $i < 5; $i++) {
            $started = hrtime(true);
            $query();
            $best = min($best, (hrtime(true) - $started) / 1_000_000);
        }

        return $best;
    }
}
