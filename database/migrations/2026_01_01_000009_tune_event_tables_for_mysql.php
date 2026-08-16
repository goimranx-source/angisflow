<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MySQL-only tuning for the two event tables.
 *
 * Everything up to here is portable, so the whole schema runs on SQLite in
 * development exactly as it will in production. This migration is the one place
 * that is not, and it is guarded rather than assumed: on SQLite it does nothing
 * and says so.
 *
 * ── What it does, and why it has to be raw SQL ───────────────────────────────
 *
 * RANGE partitioning by month turns two operations from impossible into
 * trivial:
 *
 *   Dropping old history   ALTER TABLE … DROP PARTITION is a file unlink. The
 *                          alternative — DELETE WHERE occurred_on < … — on a
 *                          billion-row table runs for hours, writes as much
 *                          binlog as the rows it removes, blocks replication,
 *                          and leaves the table full of holes that are never
 *                          reclaimed without a full rebuild.
 *
 *   Reading a date range   The optimiser skips every partition outside the
 *                          range before it touches an index at all. "Last
 *                          month's activity" reads one partition instead of
 *                          probing an index spanning three years.
 *
 * MySQL requires every unique key to contain the partition column, so the
 * primary key becomes (id, occurred_on). id is still first, which is what keeps
 * AUTO_INCREMENT legal and keeps inserts appending.
 *
 * Partitions are created a year ahead. `php artisan events:roll-partitions`
 * adds the next month and drops anything past the retention window; it is
 * scheduled monthly. If it ever stops running, rows land in the MAXVALUE
 * catch-all rather than failing — an overflowing partition is a performance
 * problem, and a rejected INSERT is a lost audit trail.
 */
return new class extends Migration
{
    /** How many months of partitions to pre-create. */
    private const MONTHS_AHEAD = 12;

    public function up(): void
    {
        if (! $this->onMysql()) {
            return;
        }

        // Partitioned tables cannot carry foreign keys in InnoDB. Neither table
        // declares one — the event tables reference accounts and users by id
        // without a constraint on purpose, because history has to outlive the
        // rows it describes. Deleting an account should not silently delete the
        // record of what was done in it.

        DB::statement('ALTER TABLE `activity_events` DROP PRIMARY KEY, ADD PRIMARY KEY (`id`, `occurred_on`)');
        DB::statement('ALTER TABLE `activity_events` '.$this->rangeClause('occurred_on'));

        DB::statement('ALTER TABLE `auth_events` DROP PRIMARY KEY, ADD PRIMARY KEY (`id`, `occurred_on`)');
        DB::statement('ALTER TABLE `auth_events` '.$this->rangeClause('occurred_on'));

        // DYNAMIC row format keeps long JSON off the main page, so a row with a
        // large context blob does not make every scan of the table read it.
        foreach (['activity_events', 'auth_events'] as $table) {
            DB::statement("ALTER TABLE `{$table}` ROW_FORMAT=DYNAMIC");
        }
    }

    public function down(): void
    {
        if (! $this->onMysql()) {
            return;
        }

        foreach (['activity_events', 'auth_events'] as $table) {
            DB::statement("ALTER TABLE `{$table}` REMOVE PARTITIONING");
            DB::statement("ALTER TABLE `{$table}` DROP PRIMARY KEY, ADD PRIMARY KEY (`id`)");
        }
    }

    /**
     * One partition per month, plus a catch-all so an INSERT can never be
     * rejected for having nowhere to go.
     */
    private function rangeClause(string $column): string
    {
        $parts = [];
        $cursor = now()->startOfMonth();

        for ($i = 0; $i <= self::MONTHS_AHEAD; $i++) {
            // The boundary is the first day of the *next* month: RANGE is
            // "less than", so this partition holds the month named by $cursor.
            $edge = $cursor->copy()->addMonth();

            $parts[] = sprintf(
                "PARTITION p%s VALUES LESS THAN (TO_DAYS('%s'))",
                $cursor->format('Ym'),
                $edge->format('Y-m-d'),
            );

            $cursor = $edge;
        }

        $parts[] = 'PARTITION pmax VALUES LESS THAN MAXVALUE';

        return sprintf(
            'PARTITION BY RANGE (TO_DAYS(`%s`)) (%s)',
            $column,
            implode(', ', $parts),
        );
    }

    private function onMysql(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
