<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One courier dashboard, however many couriers, from any country.
 *
 * ── The shape this has to survive ────────────────────────────────────────────
 *
 * A subscriber in Dhaka uses Pathao, Steadfast and RedX. One in Lagos uses
 * three others. One in Manchester uses DPD and a man with a van who sends a
 * WhatsApp message. None of those share a status vocabulary, an API shape, or a
 * settlement model, and we will never have heard of most of them.
 *
 * So nothing here knows about any particular courier. A shipment is a shipment;
 * a courier is a row; how to talk to one is an adapter; and what their words
 * mean is a mapping the subscriber owns. Adding a courier is data plus, at
 * most, one class — never a migration and never a change to this table.
 *
 * ── Why the mapping is per connection, not per courier ───────────────────────
 *
 * Two subscribers using the same courier can be on different contract types
 * with different status sets, and one of them will have asked for a custom
 * webhook. Mapping at the connection means the awkward case is ordinary rather
 * than an exception somebody has to special-case later.
 *
 * ── Why raw payloads are kept ────────────────────────────────────────────────
 *
 * Every integration argument ends the same way: they say they sent it, we say
 * we never got it. The raw body, stored as received, ends that conversation in
 * a minute rather than a week. It is also the only way to fix a mapping
 * retrospectively — with the payloads kept, a corrected mapping can be replayed
 * over history; without them, everything before the fix stays wrong for ever.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Couriers ────────────────────────────────────────────────────────
        //
        // Rows, not classes. Most are shared across all subscribers — Pathao is
        // Pathao — but a subscriber's own man with a van is a courier too, and
        // there is nowhere else to put him.
        Schema::create('couriers', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            // Null means one we ship for everybody. Set means this subscriber
            // added their own, which nobody else can see.
            $table->foreignId('account_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('slug', 60);
            // Which adapter class talks to it. 'generic' for anything driven by
            // plain webhooks and manual updates, which is most of the long tail.
            $table->string('adapter', 60)->default('generic');
            $table->char('country', 2)->nullable();
            $table->string('website')->nullable();
            $table->string('tracking_url_template')->nullable(); // …/track/{tracking_number}

            $table->boolean('supports_cod')->default(true);
            $table->boolean('supports_pickup_request')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['account_id', 'slug']);
            $table->index('country');
        });

        // ── A subscriber's connection to one ────────────────────────────────
        Schema::create('courier_connections', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('courier_id')->constrained()->cascadeOnDelete();

            $table->string('label')->nullable();   // "Pathao — Dhaka contract"

            // Credentials are deliberately not here. They belong in the vault,
            // encrypted, with rotation and an audit trail; a token in a plain
            // column is one query away from a breach. This is the pointer.
            $table->string('credential_ref', 120)->nullable();

            $table->string('base_url')->nullable();
            // What we tell the courier to call. The secret is how a delivered
            // notification is proved to have come from them rather than from
            // somebody who guessed the URL and wants free goods.
            $table->string('webhook_path', 120)->nullable();
            $table->string('webhook_secret', 120)->nullable();

            // Merchant id, pickup store id, service type — whatever this
            // courier needs that no other one does.
            $table->json('settings')->nullable();

            // How money comes back for cash on delivery: the day of the week
            // they pay, and what they keep.
            $table->unsignedSmallInteger('settlement_days')->nullable();
            $table->decimal('cod_fee_percent', 7, 4)->default(0);

            $table->string('status', 12)->default('active'); // active|paused|broken
            $table->timestamp('last_seen_at')->nullable();
            $table->string('last_error')->nullable();
            // Statuses they have sent that nobody has mapped. A count rather
            // than a flag, because "three unmapped" and "one unmapped" are
            // different amounts of trouble.
            $table->unsignedInteger('unmapped_count')->default(0);

            $table->timestamps();

            $table->unique(['business_id', 'courier_id', 'label'], 'connection_unique');
            $table->index(['business_id', 'status']);
        });

        // ── What their words mean ───────────────────────────────────────────
        Schema::create('courier_status_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('courier_connection_id')->constrained()->cascadeOnDelete();

            // Exactly as they send it, before any normalising, so a mapping can
            // be matched without guessing at their casing or spacing.
            $table->string('raw_status', 120);
            $table->string('canonical', 24);

            // Set when we proposed it rather than a person choosing it. Shown
            // differently in the UI, because a guess and a decision must not
            // look the same.
            $table->boolean('is_guess')->default(false);
            $table->timestamp('confirmed_at')->nullable();
            $table->unsignedInteger('seen_count')->default(0);
            $table->timestamps();

            $table->unique(['courier_connection_id', 'raw_status'], 'mapping_unique');
        });

        // ── Shipments ───────────────────────────────────────────────────────
        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('courier_connection_id')->nullable()->constrained()->nullOnDelete();

            $table->string('number', 40);
            // Theirs. Nullable because a shipment exists before it is booked.
            $table->string('tracking_number', 80)->nullable();
            $table->string('external_id', 120)->nullable();

            $table->string('status', 24)->default('draft');
            // Their word for it, kept beside ours. When the canonical status is
            // UNKNOWN this is the only thing that says anything at all.
            $table->string('raw_status', 120)->nullable();

            $table->boolean('is_cod')->default(false);
            $table->char('currency', 3);
            // What the rider must collect at the door.
            $table->unsignedBigInteger('cod_amount_minor')->default(0);
            // What has actually been settled to us by the courier.
            $table->unsignedBigInteger('cod_settled_minor')->default(0);
            // What they charged us to carry it.
            $table->unsignedBigInteger('delivery_fee_minor')->default(0);

            $table->string('recipient_name')->nullable();
            $table->string('recipient_phone', 40)->nullable();
            $table->string('address')->nullable();
            $table->string('city', 80)->nullable();
            $table->string('postcode', 20)->nullable();
            $table->char('country', 2)->nullable();

            $table->decimal('weight_grams', 12, 3)->nullable();
            $table->unsignedSmallInteger('parcel_count')->default(1);
            $table->string('notes')->nullable();

            $table->timestamp('booked_at')->nullable();
            $table->timestamp('picked_up_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->unsignedSmallInteger('attempt_count')->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'number']);
            // The idempotency key for an inbound webhook: the same courier's
            // same tracking number is the same parcel, however many times they
            // tell us about it.
            $table->unique(['courier_connection_id', 'tracking_number'], 'shipment_tracking_unique');
            $table->index(['business_id', 'status']);
            $table->index(['business_id', 'is_cod', 'status']);
            $table->index('order_id');
        });

        // ── Every event, with what actually arrived ─────────────────────────
        Schema::create('shipment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            $table->string('status', 24);
            $table->string('raw_status', 120)->nullable();
            $table->string('description')->nullable();
            $table->string('location')->nullable();

            // When the courier says it happened, which is not when we heard.
            // Both, because out-of-order webhooks are the norm and only the
            // first can order the story correctly.
            $table->timestamp('occurred_at');
            $table->timestamp('received_at');

            // api | webhook | manual | import
            $table->string('source', 12)->default('webhook');
            // Exactly as it arrived. See the note at the top of this file.
            $table->json('payload')->nullable();

            // Whether this event moved the shipment on, or was ignored as
            // stale or duplicate. Kept either way — "why did nothing happen
            // when they sent that" is a real question.
            $table->boolean('applied')->default(true);
            $table->string('ignored_reason')->nullable();

            $table->timestamps();

            $table->index(['shipment_id', 'occurred_at']);
            $table->index(['business_id', 'status', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_events');
        Schema::dropIfExists('shipments');
        Schema::dropIfExists('courier_status_mappings');
        Schema::dropIfExists('courier_connections');
        Schema::dropIfExists('couriers');
    }
};
