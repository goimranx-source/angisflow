<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Three small tables behind one row of controls.
 *
 * ── Why tags are shared and favourites are not ───────────────────────────────
 *
 * A tag is a fact about the business — "seasonal", "client work", "winding
 * down" — and everybody on the account should see the same ones, so it hangs
 * off account_id. A favourite is a fact about the person: the two owners of a
 * group do not have the same five businesses at the top of their list. So it
 * hangs off user_id, and one person starring something does not move it for
 * anybody else.
 *
 * ── Why the target is a short key, not a class name ──────────────────────────
 *
 * Laravel's default polymorphic column stores the model's fully qualified class
 * name, which puts the application's internal namespace into the database and
 * into every API response, and breaks every row the day a class is moved. A
 * short key — 'workspace', 'business' — is stable across refactors and means
 * nothing outside this application.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();

            $table->foreignId('account_id')->constrained()->cascadeOnDelete();

            $table->string('name', 40);
            $table->string('colour', 7)->nullable();

            $table->timestamps();

            // Two tags called "seasonal" in one account is a list nobody can
            // use. Scoped to the account, so one subscriber's names never
            // constrain another's.
            $table->unique(['account_id', 'name']);
            $table->index(['account_id', 'name']);
        });

        Schema::create('taggables', function (Blueprint $table) {
            $table->id();

            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();

            $table->string('taggable_type', 24);
            $table->unsignedBigInteger('taggable_id');

            $table->timestamps();

            $table->unique(['tag_id', 'taggable_type', 'taggable_id'], 'taggables_unique');
            // "Which tags does this thing have" — the question every row of
            // every list asks.
            $table->index(['account_id', 'taggable_type', 'taggable_id'], 'taggables_lookup');
        });

        Schema::create('favourites', function (Blueprint $table) {
            $table->id();

            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('favouritable_type', 24);
            $table->unsignedBigInteger('favouritable_id');

            $table->timestamps();

            $table->unique(['user_id', 'favouritable_type', 'favouritable_id'], 'favourites_unique');
            $table->index(['account_id', 'user_id'], 'favourites_lookup');
        });

        Schema::create('todo_marks', function (Blueprint $table) {
            $table->id();

            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // The to-do's own key — 'verify', 'plan', 'two-factor'. Derived from
            // account state rather than stored as rows, so this table records
            // only what somebody has done about them.
            $table->string('key', 40);
            $table->string('state', 12);

            $table->timestamps();

            $table->unique(['user_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('todo_marks');
        Schema::dropIfExists('favourites');
        Schema::dropIfExists('taggables');
        Schema::dropIfExists('tags');
    }
};
