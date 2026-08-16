<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Track when and why an account was deleted, and who requested it.
 *
 * ── Why tracking is separate from the soft delete ────────────────────────────
 *
 * deleted_at alone says "this account is gone", but nothing about who asked for
 * it or why. Support needs that: an account that vanished because somebody
 * canceled is not the same as one that vanished because we suspended it, and a
 * restoration request from the subscriber makes sense for the first and not the
 * second.
 *
 * ── 60-day retention ─────────────────────────────────────────────────────────
 *
 * Soft-deleted accounts are kept for 60 days before permanent deletion. This
 * allows subscribers to request restoration if deleted in error. After 60 days,
 * a scheduled job will permanently delete accounts (actually delete the row and
 * all related data).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            // Who requested the deletion (the owner or a staff member)
            $table->unsignedBigInteger('deleted_by_user_id')->nullable()->after('suspended_reason');
            
            // Why it was deleted: 'user_requested', 'admin_suspended', 'payment_failure', etc.
            $table->string('deletion_reason', 100)->nullable()->after('deleted_by_user_id');
            
            // Any additional notes about the deletion
            $table->text('deletion_notes')->nullable()->after('deletion_reason');
            
            // When it will be permanently deleted (60 days after soft delete)
            $table->timestamp('permanent_deletion_at')->nullable()->after('deletion_notes');
            
            $table->index('permanent_deletion_at');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn([
                'deleted_by_user_id',
                'deletion_reason',
                'deletion_notes',
                'permanent_deletion_at',
            ]);
        });
    }
};
