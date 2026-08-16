<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * A workspace: the thing a subscription actually buys.
 *
 * ── Why a layer between the account and the business ─────────────────────────
 *
 * The account is who pays. A business is one set of books. Between them sits
 * the unit the plan is sold in: a subscriber's plan says how many workspaces
 * they may create, and each workspace holds as many businesses as the plan
 * allows. Without this layer "how many can I have" has only one answer, and
 * every group that separates its arms — a holding company, an agency running
 * clients, a franchise — has to buy a whole extra account to do it.
 *
 * ── Why the column is added rather than the table rebuilt ────────────────────
 *
 * Businesses already exist and already carry data. So this adds the column
 * nullable, gives every existing account a workspace holding what it already
 * had, and only then makes the column required — which is the same shape a
 * production migration would take, and works whether the installation is a day
 * old or a year.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspaces', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();

            $table->foreignId('account_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('slug', 64);

            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            // Two workspaces in one account cannot share a slug — it addresses
            // them in URLs.
            $table->unique(['account_id', 'slug']);

            // The picker in the header: this account's live workspaces.
            $table->index(['account_id', 'is_active']);
        });

        Schema::table('businesses', function (Blueprint $table) {
            $table->foreignId('workspace_id')->nullable()->after('account_id')
                ->constrained()->cascadeOnDelete();

            // Leads with account_id for the same reason every other index here
            // does — it is the first thing every query filters on. The
            // workspace narrows it to one set of books within that subscriber.
            $table->index(['account_id', 'workspace_id', 'is_active'], 'businesses_account_workspace_active');
        });

        $this->backfill();

        // Only now that every row has one. A business with no workspace has no
        // plan governing it, which is a hole in billing rather than a null.
        Schema::table('businesses', function (Blueprint $table) {
            $table->foreignId('workspace_id')->nullable(false)->change();
        });
    }

    /**
     * Give every existing account one workspace, and move its businesses in.
     */
    private function backfill(): void
    {
        DB::table('accounts')->orderBy('id')->chunkById(200, function ($accounts) {
            foreach ($accounts as $account) {
                $id = DB::table('workspaces')->insertGetId([
                    'public_id' => strtoupper((string) Str::ulid()),
                    'account_id' => $account->id,
                    'name' => $account->name ?: 'Workspace',
                    'slug' => 'default',
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('businesses')
                    ->where('account_id', $account->id)
                    ->update(['workspace_id' => $id]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropIndex('businesses_account_workspace_active');
            $table->dropConstrainedForeignId('workspace_id');
        });

        Schema::dropIfExists('workspaces');
    }
};
