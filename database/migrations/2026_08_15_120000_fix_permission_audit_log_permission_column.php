<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task 31 fix pass: permission_audit_log.permission_id pointed nowhere.
 *
 * ── What was wrong ────────────────────────────────────────────────────────────
 *
 * 2026_08_12_000026_create_roles_and_permissions_tables.php declared
 * `$table->foreignId('permission_id')->nullable()->constrained()->nullOnDelete()`.
 * `constrained()` with no argument guesses the referenced table from the column
 * name — "permissions" — but no such table was ever created, and no
 * `App\Models\Permission` exists either. SQLite does not validate a foreign
 * key's target table at CREATE TABLE time (only when foreign_keys enforcement
 * is on and a row is actually inserted), so the migration silently succeeded
 * and left a foreign key definition pointing at nothing. `PermissionAuditLog`'s
 * `permission()` relation called `belongsTo(\App\Models\Permission::class)`,
 * a class that does not exist — the first read of that relation would fatal.
 *
 * ── Why a string column and not a `permissions` table ────────────────────────
 *
 * This app already has a code-based capability vocabulary —
 * `App\Support\Capabilities` — used by the real, working
 * `AuthorizationServiceProvider` / `Gate::define()` system that every route
 * actually checks. A `permissions` table would be a second, parallel
 * definition of "what a person can be allowed to do" that could drift from
 * the one Capabilities already enumerates. The audit log only ever needs to
 * *record which capability a change was about* — a string column holding the
 * capability key (e.g. `orders.edit`) does that without inventing a table or
 * a model that would duplicate Capabilities.
 *
 * ── Why recreate rather than a plain DROP COLUMN ──────────────────────────────
 *
 * SQLite refuses a native `ALTER TABLE ... DROP COLUMN` when the column is
 * still named in a foreign key definition, even a dangling one — the driver
 * reports "unknown column ... in foreign key definition" once the column is
 * gone but the constraint clause referencing it remains. `dropForeign()` has
 * to run first so the constraint is gone before the column is, and on SQLite
 * both compile through the same table-recreation path in the same
 * `Schema::table()` call, verified against a copy of the dev database before
 * being applied here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('permission_audit_log', function (Blueprint $table) {
            $table->dropForeign(['permission_id']);
            $table->dropColumn('permission_id');

            // The capability key this event was about (e.g. "orders.edit"),
            // matching App\Support\Capabilities — not a foreign key, because
            // capabilities are code, not rows.
            $table->string('permission_key')->nullable()->after('role_id');
        });
    }

    public function down(): void
    {
        Schema::table('permission_audit_log', function (Blueprint $table) {
            $table->dropColumn('permission_key');

            $table->foreignId('permission_id')->nullable()->after('role_id');
        });
    }
};
