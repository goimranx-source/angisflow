<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Give every workspace that already exists its module rows.
 *
 * ── Why these get everything, not a preset ───────────────────────────────────
 *
 * Until this release the sidebar was the same for everybody: the whole
 * catalogue, with unbuilt entries pointing at their coming-soon pages. Nobody
 * chose that, so nobody can be said to have wanted less than it.
 *
 * Applying a category preset here would therefore not be "setting a sensible
 * default" — it would be silently removing screens from live accounts on the
 * strength of a guess about what kind of business they are, made by us, after
 * the fact. A subscriber who opened Prism to find Production gone would be
 * entirely right to call that a bug.
 *
 * So the migration preserves exactly what is on screen today and leaves the
 * choosing to them. Presets apply to workspaces created from here on, where
 * somebody actually answered the question.
 *
 * Runs in chunks and skips rows that already exist, so it is safe to re-run
 * and safe on an installation with a lot of workspaces.
 */
return new class extends Migration
{
    public function up(): void
    {
        $moduleIds = DB::table('modules')->pluck('id');

        if ($moduleIds->isEmpty()) {
            // The catalogue seeder has not run yet. Nothing to backfill
            // against, and provisioning will handle these workspaces once it
            // has — so this is a no-op rather than a failure.
            return;
        }

        $now = now();

        DB::table('workspaces')->orderBy('id')->chunkById(200, function ($workspaces) use ($moduleIds, $now): void {
            foreach ($workspaces as $workspace) {
                $existing = DB::table('workspace_modules')
                    ->where('workspace_id', $workspace->id)
                    ->pluck('module_id')
                    ->all();

                $rows = [];

                foreach ($moduleIds as $moduleId) {
                    if (in_array($moduleId, $existing, true)) {
                        continue;
                    }

                    $rows[] = [
                        'workspace_id' => $workspace->id,
                        'module_id' => $moduleId,
                        'is_enabled' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($rows !== []) {
                    // Chunked on insert as well: one workspace is ~90 rows, and
                    // a placeholder limit is a real ceiling on some drivers.
                    foreach (array_chunk($rows, 500) as $batch) {
                        DB::table('workspace_modules')->insert($batch);
                    }
                }
            }
        });
    }

    public function down(): void
    {
        // Deliberately not reversible. Rolling this back would strip every
        // workspace's module rows, and the "before" state is not recoverable
        // from what is left — the enabled flags a subscriber has since changed
        // are indistinguishable from the ones this migration wrote.
    }
};
