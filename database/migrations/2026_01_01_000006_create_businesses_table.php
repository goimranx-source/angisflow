<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A set of books inside a subscriber's account.
 *
 * Most accounts have one. A group that keeps its arms apart has several, and
 * every figure in the tool is read against whichever is currently selected —
 * which is why business_id will sit beside account_id on every ledger, order
 * and stock table, and why those indexes lead (account_id, business_id, …).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('businesses', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();

            $table->foreignId('account_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('short_code', 12)->nullable();

            $table->char('base_currency', 3)->default('BDT');
            $table->char('country', 2)->nullable();
            $table->string('timezone', 64)->nullable();

            $table->string('address')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            // The short code composes every SKU and order reference, so two
            // businesses in one account sharing one would make those ambiguous.
            $table->unique(['account_id', 'short_code']);

            // The switcher in the header: this account's live businesses.
            $table->index(['account_id', 'is_active']);
        });

        // Deferred to here because businesses is created after users. The
        // column is on users; the constraint could only be added once both
        // tables existed.
        Schema::table('users', function (Blueprint $table) {
            $table->foreign('current_business_id')->references('id')->on('businesses')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['current_business_id']);
        });

        Schema::dropIfExists('businesses');
    }
};
