<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every inbound webhook, stored before anybody tries to understand it.
 *
 * ── Store first, parse second ────────────────────────────────────────────────
 *
 * The instinct is to parse the payload and save the result. Then a courier
 * changes a field name, parsing throws, the request 500s, their retry queue
 * gives up after three attempts, and a week of deliveries never happened as far
 * as this system is concerned. Nothing survives to replay, because the only
 * copy was in a request that has been over for days.
 *
 * So the body is written down first, exactly as it arrived, and answered 200
 * immediately. Parsing happens afterwards and is allowed to fail. A payload
 * nobody could read is a row somebody can look at, fix a mapping for, and
 * replay — which is the difference between an integration bug costing an hour
 * and costing a week of figures.
 *
 * ── Why the signature check does not gate storage ────────────────────────────
 *
 * A rejected request is stored too, marked rejected. These URLs are found by
 * scanners within days, so most rejections are noise — but the one that matters
 * is the courier who rotated their secret without telling anybody, and that is
 * indistinguishable from an attacker unless the attempts are visible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();

            // Nullable: a request that matched no connection is exactly the
            // kind worth keeping, and it has no tenant to belong to.
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('business_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('courier_connection_id')->nullable()->constrained()->nullOnDelete();

            // Which endpoint it arrived on, so an unmatched request can still
            // be traced back to whatever was configured.
            $table->string('endpoint', 160);
            $table->string('source_ip', 45)->nullable();
            $table->json('headers')->nullable();
            // Text, not json: a courier sending malformed JSON, XML or a form
            // post must still be stored, and a json column would refuse it.
            $table->longText('body')->nullable();

            // received | verified | rejected | parsed | failed | ignored
            $table->string('status', 12)->default('received');
            $table->string('failure_reason')->nullable();
            $table->unsignedSmallInteger('event_count')->default(0);
            $table->unsignedSmallInteger('applied_count')->default(0);

            // Their own id for the delivery, where they send one. The second
            // line of defence against duplicates, after the shipment's own
            // status ordering.
            $table->string('external_delivery_id', 120)->nullable();

            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['courier_connection_id', 'received_at']);
            $table->index(['status', 'received_at']);
            $table->unique(['courier_connection_id', 'external_delivery_id'], 'webhook_external_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
    }
};
