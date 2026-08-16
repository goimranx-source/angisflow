<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operator admin panel foundations.
 *
 * ── is_operator on users ──────────────────────────────────────────────────────
 *
 * An operator is a member of the platform team — someone who can see and act
 * on any subscriber's account. The flag lives on the users table rather than
 * in a separate table because operators are also users (they sign in the same
 * way) and the check is a single boolean read on every operator request.
 *
 * Operators are not subscribers. They have no account_id of their own in the
 * normal sense — they are seeded directly and their account_id points at a
 * dedicated platform account that is never shown to subscribers.
 *
 * ── workspace_entitlement_overrides ──────────────────────────────────────────
 *
 * The operator grant surface that task 37 left as honest accounting. When a
 * subscriber negotiates a custom deal — "we need Payroll but we are on the
 * Starter plan" — an operator can grant specific module keys to a workspace
 * without changing the plan. The PlanEntitlement resolver checks this table
 * after the plan and merges the grants in.
 *
 * Revocations are also possible: an operator can block a module key that the
 * plan would otherwise permit. This is the "we are migrating you off this
 * feature" lever.
 *
 * ── Why not a separate operators table ───────────────────────────────────────
 *
 * A separate table would mean a separate auth flow, separate session handling,
 * and a separate set of middleware. The flag approach reuses everything that
 * already works and adds one check. The risk — an operator accidentally getting
 * a subscriber account — is mitigated by the seeder creating operators with
 * no account_id pointing at subscriber data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_operator')->default(false)->after('is_owner');
        });

        Schema::create('workspace_entitlement_overrides', function (Blueprint $table) {
            $table->id();

            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            // account_id is denormalised here for the same reason it is on
            // every other table: the first column of every index, so the
            // database never reads another subscriber's rows.
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();

            // The module key being overridden — e.g. 'people.payroll'.
            $table->string('module_key', 80);

            // grant  — permit this key regardless of plan
            // revoke — block this key regardless of plan
            $table->string('kind', 8)->default('grant');

            // Who did this and why. An override with no reason is a mystery
            // six months later; requiring one is the only way to ensure it
            // gets written down.
            $table->foreignId('granted_by')->constrained('users')->restrictOnDelete();
            $table->string('reason');

            $table->timestamp('expires_at')->nullable(); // null = permanent
            $table->timestamps();

            $table->unique(['workspace_id', 'module_key'], 'override_unique');
            $table->index(['account_id', 'workspace_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_entitlement_overrides');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_operator');
        });
    }
};
