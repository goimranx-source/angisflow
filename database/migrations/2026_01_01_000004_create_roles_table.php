<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Named sets of permissions, one set per subscriber.
 *
 * ── Why the capabilities are a JSON column and not a pivot table ─────────────
 *
 * The textbook shape is roles → role_permissions → permissions, and it is the
 * wrong shape here. It turns "may this person see orders" into a two-table join
 * that runs on every guarded element of every page, for every user. At the
 * numbers this is built for that join is executed billions of times a day to
 * answer a question whose answer changes when an administrator edits a role —
 * a few times a year.
 *
 * A JSON array on the role is read once, cached by role id, and answered from
 * memory thereafter. The capability catalogue itself lives in code
 * (App\Support\Capabilities), where it belongs: adding a capability means
 * writing the feature it guards, so it ships with a deploy either way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();

            $table->foreignId('account_id')->constrained()->cascadeOnDelete();

            // A role family — "Sales" — that ordinary roles hang under, so a
            // new role inside a family needs no screen of its own.
            $table->unsignedBigInteger('parent_id')->nullable();

            $table->string('name', 80);
            $table->string('slug', 80);
            $table->string('description')->nullable();

            $table->json('capabilities')->nullable();

            $table->string('landing', 64)->nullable();
            $table->string('workspace', 32)->nullable();
            $table->string('scope', 16)->default('own');

            // A system role is one the platform created and the subscriber may
            // rename but not delete — deleting "Owner" is not a thing anybody
            // should be able to do to themselves by accident.
            $table->boolean('is_system')->default(false);

            $table->timestamps();

            $table->unique(['account_id', 'slug']);
            $table->index(['account_id', 'parent_id']);

            $table->foreign('parent_id')->references('id')->on('roles')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
