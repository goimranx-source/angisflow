<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The module catalogue, as data.
 *
 * ── Why this stops being a PHP array ─────────────────────────────────────────
 *
 * App\Support\Modules held the whole catalogue as a literal. That is fine while
 * the catalogue is one product's worth of screens and every subscriber sees the
 * same list. It stops being fine the moment three things become true at once:
 * a subscriber may switch modules on and off, a plan may withhold some, and an
 * operator may grant an exception to one account. None of those can be
 * expressed in a constant, and all three have to be queryable — "which accounts
 * have Payroll on" is a support question, not a deploy.
 *
 * ── The three concerns this schema keeps apart ───────────────────────────────
 *
 * A module appears for somebody only if all three agree, and each is owned by
 * a different part of the business:
 *
 *   ENTITLEMENT   what the plan allows — billing's answer. Not modelled here;
 *                 it resolves from plan + add-ons + operator grants, and this
 *                 schema deliberately holds no copy of it.
 *   ENABLEMENT    what the subscriber switched on — their preference, stored
 *                 in workspace_modules.
 *   AUTHORISATION what this particular user may do — the role's answer, held
 *                 against modules.capability and checked per request.
 *
 * Collapsing any two of these is the classic failure. Fold entitlement into
 * enablement and a customer who pays for a module cannot turn it on. Fold
 * authorisation into enablement and switching a module off for the account
 * becomes the only way to deny one person access to it.
 *
 * ── Why presets are copied, never referenced ─────────────────────────────────
 *
 * category_module_presets is the answer to "what should a new retail workspace
 * start with". workspace_modules is the answer to "what does THIS workspace
 * have". The first seeds the second once, at creation, and is never consulted
 * again — because the subscriber is allowed to disagree with us, and a preset
 * that kept being re-derived would silently overrule them every time we edited
 * the defaults.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── The twelve groups the sidebar draws ──────────────────────────
        // Their own table rather than a string repeated on every module,
        // because renaming a pillar is one edit here and ninety there.
        Schema::create('module_pillars', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->string('label');
            $table->string('icon', 40);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('sort_order');
        });

        // ── What Prism can offer ─────────────────────────────────────────
        Schema::create('modules', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();

            $table->foreignId('pillar_id')->constrained('module_pillars')->cascadeOnDelete();

            // Addressed by key everywhere — seeds, presets and permission
            // checks all name modules this way, so it outlives any id.
            $table->string('key', 60)->unique();

            $table->string('label');
            $table->string('icon', 40);
            $table->string('summary')->nullable();

            // AUTHORISATION. Which capability a role needs before this is
            // shown at all. Null means anyone in the workspace may see it.
            $table->string('capability', 60)->nullable();

            /*
             * Core modules cannot be switched off by anyone — not the
             * subscriber, not an operator. Two kinds qualify: the ones the
             * platform is structurally built on (the ledger everything posts
             * into, the customer record everything hangs off), and the ones
             * we have decided every subscriber gets regardless of what they
             * sell — conversations and live chat among them. A core module
             * is never metered or withheld, so it never appears in a plan.
             */
            $table->boolean('is_core')->default(false);

            // False for a module that is mapped out but not yet written, so
            // the catalogue can describe the whole product while the nav
            // only offers the parts that exist.
            $table->boolean('is_built')->default(false);

            /*
             * Other modules this one cannot work without, as an array of
             * module keys. Orders requires the ledger because it posts into
             * it; switching the ledger off underneath it would not fail
             * loudly, it would quietly stop recording money. Kept as JSON
             * rather than a join table because the graph is small, shallow,
             * and read in full every time it is read at all.
             */
            $table->json('requires')->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            // Drawing the sidebar: every module of a pillar, in order.
            $table->index(['pillar_id', 'sort_order']);
        });

        // ── What kind of business a workspace is ─────────────────────────
        // One self-referencing table rather than two: a subcategory is a
        // category with a parent, and the alternative is two near-identical
        // tables that have to be kept in step by hand.
        Schema::create('business_categories', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();

            $table->foreignId('parent_id')->nullable()
                ->constrained('business_categories')->cascadeOnDelete();

            $table->string('key', 60)->unique();
            $table->string('name');

            // Shown under the name in the picker — this is what makes an
            // unfamiliar category legible without a manual.
            $table->string('summary')->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            // The picker: live top-level categories, then their children.
            $table->index(['parent_id', 'is_active', 'sort_order']);
        });

        // ── What a new workspace of that kind starts with ────────────────
        Schema::create('category_module_presets', function (Blueprint $table) {
            $table->id();

            $table->foreignId('business_category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('module_id')->constrained()->cascadeOnDelete();

            /*
             * False still matters. A module absent from this table is one we
             * never considered for this category; a module present and false
             * is one we considered and decided against. Only the second
             * should be offered prominently when a subscriber goes looking
             * for more, so the distinction is worth a row.
             */
            $table->boolean('enabled_by_default')->default(false);

            $table->timestamps();

            $table->unique(['business_category_id', 'module_id'], 'preset_unique');
        });

        // ── What this workspace actually has ─────────────────────────────
        Schema::create('workspace_modules', function (Blueprint $table) {
            $table->id();

            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('module_id')->constrained()->cascadeOnDelete();

            $table->boolean('is_enabled')->default(true);

            // Who last changed it and when. A subscriber asking "why did this
            // disappear" is a support call that this answers on its own —
            // and an operator toggling a module for somebody has to be
            // attributable.
            $table->timestamp('changed_at')->nullable();
            $table->foreignId('changed_by')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['workspace_id', 'module_id'], 'workspace_module_unique');

            // The hot read: every enabled module for the workspace being
            // drawn right now.
            $table->index(['workspace_id', 'is_enabled']);
        });

        // ── The workspace's own category ─────────────────────────────────
        Schema::table('workspaces', function (Blueprint $table) {
            /*
             * Nullable, and stays nullable. Workspaces created before this
             * migration have no category and are not wrong — the category is
             * a hint used once at creation, not a property the workspace
             * needs in order to function. Making it required later would
             * mean inventing an answer for every existing row.
             *
             * nullOnDelete rather than cascade: retiring a category must
             * never take workspaces with it.
             */
            $table->foreignId('business_category_id')->nullable()->after('slug')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropConstrainedForeignId('business_category_id');
        });

        Schema::dropIfExists('workspace_modules');
        Schema::dropIfExists('category_module_presets');
        Schema::dropIfExists('business_categories');
        Schema::dropIfExists('modules');
        Schema::dropIfExists('module_pillars');
    }
};
