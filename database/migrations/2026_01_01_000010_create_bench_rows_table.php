<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A scratch table for `php artisan prism:benchmark-tenancy`.
 *
 * Shaped exactly like the transactional tables that are coming — account_id
 * first in every index, a ULID for the outside world, money as an integer — so
 * the benchmark measures the real design rather than a convenient version of
 * it. It holds nothing anybody cares about and is truncated on both sides of a
 * run.
 *
 * It exists because "this scales" is a claim, and a claim about performance
 * that nobody can run is indistinguishable from a claim that is untrue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bench_rows', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('business_id')->nullable();
            $table->char('public_id', 26);

            $table->string('status', 20);
            $table->bigInteger('total_minor');
            $table->date('occurred_on');

            // The index the whole tenancy argument rests on. Leading with
            // account_id is what lets the database seek straight to one
            // subscriber's rows instead of filtering everybody else's out.
            $table->index(['account_id', 'status', 'id'], 'bench_account_status');
            $table->index(['account_id', 'occurred_on'], 'bench_account_day');

            // Deliberately absent: an index on status alone. A query that
            // forgets the tenant has nothing useful to use, which is exactly
            // what the benchmark is there to show.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bench_rows');
    }
};
