<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which record here is which record there.
 *
 * ── Why a table and not a column ─────────────────────────────────────────────
 *
 * The obvious design is an `external_id` column on each record, and it works
 * right up to the point somebody does the thing this feature exists for: sells
 * the same product through two shops. One product, two external ids, one
 * column — and the second connection overwrites the first, or refuses.
 *
 * Orders are genuinely one-to-one with a shop, so a column would have served
 * them. Products and customers are not: a catalogue listed on a WooCommerce site
 * and a Shopify shop is one catalogue, and a customer who has bought from both
 * is one customer. Splitting the storage by entity would mean two mechanisms,
 * two sync paths and two places for the reconciliation to be subtly different.
 *
 * ── What else it carries ─────────────────────────────────────────────────────
 *
 * The link is also the only sensible home for everything true of *this record on
 * that shop* rather than of the record itself: the custom fields that shop keeps
 * about it, the fingerprint of what was last pushed, and when each direction
 * last ran. A product sold in two shops can carry a different delivery slot in
 * each, and on the link that is simply two rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_links', function (Blueprint $table) {
            $table->id();

            // Denormalised from the integration so every tenant-scoped query on
            // this table stands on its own. Without it, listing a business's
            // links means joining api_integrations to find out who they belong
            // to — on the one table that grows with order volume.
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            // The connection is what gives an external id its meaning: id 1042
            // means nothing without knowing which shop counted to it.
            $table->foreignId('integration_id')->constrained('api_integrations')->cascadeOnDelete();

            $table->string('entity', 20);            // order | product | customer
            $table->unsignedBigInteger('linkable_id');

            $table->string('external_id', 191);
            // The shop's human reference — '#1001', 'SKU-22'. Kept separately
            // because the id is usually a number nobody recognises, and a
            // support conversation happens in the words on the customer's email.
            $table->string('external_reference', 191)->nullable();

            /**
             * What that shop keeps about this record beyond our own columns.
             *
             * On the link rather than on the record because it is genuinely
             * per-shop: the same product can carry a different delivery slot in
             * two storefronts, and there is no single true value to put on the
             * product itself.
             */
            $table->json('custom_fields')->nullable();

            /**
             * A fingerprint of what was last pushed.
             *
             * Every push provokes a webhook straight back, and without a way to
             * recognise our own echo the sync either loops or has to go deaf for
             * a fixed period — which also deafens it to a genuine edit made in
             * that window. Comparing content means a real change is never missed,
             * however soon after a push it arrives.
             */
            $table->string('push_fingerprint', 64)->nullable();

            $table->timestamp('last_pulled_at')->nullable();
            $table->timestamp('last_pushed_at')->nullable();

            $table->timestamps();

            /*
             * One external record maps to exactly one of ours, per connection.
             * Enforced in the database and not only in code, because the thing
             * that breaks it is two webhooks arriving at once — which is a race
             * no amount of checking-then-inserting can close.
             */
            $table->unique(['integration_id', 'entity', 'external_id'], 'integration_links_external_unique');

            // And the same in reverse: one of ours has one identity per shop.
            $table->unique(['integration_id', 'entity', 'linkable_id'], 'integration_links_local_unique');

            // "Everywhere this product is listed" — the question the product
            // page asks, and the one a push has to answer before it can fan out.
            $table->index(['business_id', 'entity', 'linkable_id'], 'integration_links_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_links');
    }
};
