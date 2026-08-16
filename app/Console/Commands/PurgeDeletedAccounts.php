<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Tenancy\Models\Account;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Permanently delete accounts that have been soft-deleted for 60+ days.
 *
 * This runs nightly and hard-deletes any account whose permanent_deletion_at
 * date has passed. All related data (users, workspaces, businesses, etc.) will
 * be cascade-deleted by the database foreign key constraints.
 *
 * ── Why 60 days ──────────────────────────────────────────────────────────────
 *
 * This gives subscribers two months to request restoration if they deleted in
 * error or changed their mind. After that, the data is truly gone and storage
 * is freed.
 *
 * ── Schedule this command ────────────────────────────────────────────────────
 *
 * Add to app/Console/Kernel.php schedule():
 *
 *     $schedule->command('accounts:purge-deleted')->daily();
 */
class PurgeDeletedAccounts extends Command
{
    protected $signature = 'accounts:purge-deleted
                           {--dry-run : Show what would be deleted without actually deleting}
                           {--force : Skip confirmation prompt}';

    protected $description = 'Permanently delete accounts that have been soft-deleted for 60+ days';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        // Find accounts ready for permanent deletion
        $accounts = Account::onlyTrashed()
            ->whereNotNull('permanent_deletion_at')
            ->where('permanent_deletion_at', '<=', now())
            ->get();

        if ($accounts->isEmpty()) {
            $this->info('No accounts ready for permanent deletion.');

            return self::SUCCESS;
        }

        $this->warn("Found {$accounts->count()} account(s) ready for permanent deletion:");
        $this->newLine();

        foreach ($accounts as $account) {
            $this->line("  - {$account->name} (ID: {$account->public_id})");
            $this->line("    Deleted: {$account->deleted_at->format('Y-m-d H:i:s')}");
            $this->line("    Scheduled for purge: {$account->permanent_deletion_at->format('Y-m-d H:i:s')}");
            $this->line("    Reason: {$account->deletion_reason}");
            $this->newLine();
        }

        if ($dryRun) {
            $this->info('Dry run mode - no data was deleted.');

            return self::SUCCESS;
        }

        if (!$force && !$this->confirm('Permanently delete these accounts?', false)) {
            $this->info('Operation cancelled.');

            return self::SUCCESS;
        }

        $deleted = 0;
        $failed = 0;

        foreach ($accounts as $account) {
            try {
                DB::transaction(function () use ($account) {
                    // Force delete (permanent deletion)
                    // Note: Related data will be cascade-deleted by database constraints
                    $account->forceDelete();
                });

                $this->info("✓ Permanently deleted: {$account->name} ({$account->public_id})");
                $deleted++;
            } catch (\Throwable $e) {
                $this->error("✗ Failed to delete {$account->name}: {$e->getMessage()}");
                $failed++;
            }
        }

        $this->newLine();
        $this->info("Deleted: {$deleted}");

        if ($failed > 0) {
            $this->error("Failed: {$failed}");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
