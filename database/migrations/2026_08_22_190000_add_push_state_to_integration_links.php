<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a record's local changes have actually reached the shop.
 *
 * ── Why last_pushed_at was not enough ────────────────────────────────────────
 *
 * Because it records the last success and says nothing about what has happened
 * since. An order edited here, whose push then failed — or was never scheduled
 * at all — still carries the timestamp of the push before it, and looks
 * identical to one that is perfectly in step. That is precisely the state this
 * application has already been in twice: once when a queue with no worker
 * swallowed every push, and once when a bulk change committed locally and then
 * threw before its pushes were dispatched. Both times both screens reported
 * success, and the shop quietly disagreed for days.
 *
 * push_fingerprint cannot answer it either. It exists to recognise our own echo
 * coming back through a webhook, so clearing it to mean "unsent" would make the
 * application re-apply its own changes as though the shop had made them.
 *
 * ── What these two hold ──────────────────────────────────────────────────────
 *
 * push_pending_at  when a local change was made that the shop has not yet
 *                  accepted. Set as the change is written, cleared only by a
 *                  push that actually succeeded — so a debt outlives a dropped
 *                  job, a dead worker, and a process that died mid-request.
 *
 * push_error       why the last attempt failed, kept so somebody can be told
 *                  something better than "not synced". Cleared on success.
 *
 * A pending stamp with no error is work still in flight. A pending stamp with
 * an error is work that needs a person. Neither is silence, which is the point.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_links', function (Blueprint $table): void {
            $table->timestamp('push_pending_at')->nullable()->after('last_pushed_at');
            $table->text('push_error')->nullable()->after('push_pending_at');

            /*
             * Indexed because the question "what has not reached the shop" is
             * asked for a whole business at once, and the answer is almost
             * always a handful of rows out of thousands.
             */
            $table->index(['business_id', 'push_pending_at']);
        });
    }

    public function down(): void
    {
        Schema::table('integration_links', function (Blueprint $table): void {
            $table->dropIndex(['business_id', 'push_pending_at']);
            $table->dropColumn(['push_pending_at', 'push_error']);
        });
    }
};
