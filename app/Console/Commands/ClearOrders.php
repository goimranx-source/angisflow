<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ClearOrders extends Command
{
    protected $signature = 'orders:clear {--force : Skip confirmation}';
    protected $description = 'Clear all orders from the database';

    public function handle(): int
    {
        if (!$this->option('force')) {
            if (!$this->confirm('This will delete ALL orders. Are you sure?')) {
                $this->info('Cancelled.');
                return self::SUCCESS;
            }
        }

        $this->info('Clearing orders...');

        $ordersCount = DB::table('orders')->count();
        $linesCount = DB::table('order_lines')->count();
        $linksCount = DB::table('integration_links')->where('entity', 'order')->count();

        DB::table('order_lines')->delete();
        DB::table('integration_links')->where('entity', 'order')->delete();
        DB::table('orders')->delete();

        $this->info("Deleted {$ordersCount} orders");
        $this->info("Deleted {$linesCount} order lines");
        $this->info("Deleted {$linksCount} integration links");
        $this->line('');
        $this->info('✅ All orders cleared successfully!');

        return self::SUCCESS;
    }
}
