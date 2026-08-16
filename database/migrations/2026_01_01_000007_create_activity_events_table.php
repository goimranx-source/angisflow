<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who changed what, and when — the firehose.
 *
 * ── The table that has to survive the numbers ────────────────────────────────
 *
 * This is the one designed for billions of rows, and it is designed differently
 * from everything else because of it.
 *
 *   Append only.        No updates, no deletes, no soft deletes. Rows are
 *                       written once and read by range. That means no page
 *                       churn, no fragmentation, and a table that stays as fast
 *                       at ten billion rows as at ten.
 *
 *   Written off-request. Nothing here is inserted inline. Events go through a
 *                       buffer and are flushed as one multi-row INSERT per
 *                       request, or handed to the queue. A per-event INSERT on
 *                       the request path would make the audit trail the slowest
 *                       part of every write in the system — which is exactly
 *                       what activity-log packages do, and why they are the
 *                       first thing removed when a Laravel app gets busy.
 *
 *   No polymorphic index. subject_type is a short string, not a class name, and
 *                       it is the second column of a composite index rather
 *                       than half of a two-column morph index. Laravel's usual
 *                       (subject_type, subject_id) morph index is enormous —
 *                       the type column holds "App\Domain\Sales\Models\Order"
 *                       on every one of a billion rows — and it cannot be used
 *                       for a tenant-scoped read at all.
 *
 *   Partition ready.    occurred_on is a plain DATE beside the timestamp, held
 *                       only so MySQL can RANGE partition on it. Dropping a
 *                       month of history then becomes ALTER TABLE … DROP
 *                       PARTITION, which is instant, rather than a DELETE that
 *                       runs for a day and leaves the table half air. See the
 *                       MySQL-only migration that follows this one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_events', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('business_id')->nullable();

            // Null when the platform did it rather than a person — a webhook,
            // a scheduled job, a provider callback.
            $table->unsignedBigInteger('actor_user_id')->nullable();

            // A short stable name — 'order', 'transaction', 'payslip' — not a
            // class name. Classes get renamed and moved between namespaces;
            // a billion rows of history should not care.
            $table->string('subject_type', 32);
            $table->unsignedBigInteger('subject_id')->nullable();

            // 'created', 'status.changed', 'payment.reversed'.
            $table->string('verb', 48);

            // What actually changed, and anything needed to read the line back
            // in a year. Not indexed: JSON here is for display, never a filter.
            $table->json('context')->nullable();

            $table->timestamp('occurred_at', 3);

            // Redundant with occurred_at by design — a partition key must be a
            // column, and MySQL will not partition on an expression over a
            // TIMESTAMP.
            $table->date('occurred_on');

            // No timestamps(): created_at would duplicate occurred_at and
            // updated_at would describe something that never happens.

            // The audit screen: this account's history, newest first, optionally
            // narrowed to one business.
            $table->index(['account_id', 'occurred_at'], 'ae_account_time');

            // "Everything that ever happened to this order."
            $table->index(['account_id', 'subject_type', 'subject_id', 'id'], 'ae_account_subject');

            // "Everything this person did" — the question asked when something
            // has gone wrong.
            $table->index(['account_id', 'actor_user_id', 'occurred_at'], 'ae_account_actor');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_events');
    }
};
