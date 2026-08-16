<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One inbox, however many places people message from.
 *
 * ── The same shape as the couriers, for the same reason ──────────────────────
 *
 * WhatsApp, Messenger, Instagram, TikTok, SMS, email and a chat widget on the
 * subscriber's own site all deliver "somebody said something". Each does it
 * with a different payload, a different id scheme, and a different idea of what
 * a conversation is. Write the inbox against those and it is six inboxes
 * wearing one skin, and the seventh channel is a release.
 *
 * So: canonical conversation, canonical message, and adapters that translate.
 * Nothing below knows what WhatsApp is.
 *
 * ── A conversation belongs to a channel; a customer does not ─────────────────
 *
 * The same person messages from WhatsApp on Tuesday and Instagram on Thursday.
 * Those are two conversations — they genuinely are, with different histories
 * and different reply windows — but one customer, and the customer record has
 * to show both. That is what the identity table from the customer work is for:
 * a channel handle is just another identity, and resolving it finds the person
 * already known from an order.
 *
 * ── Echo detection is not optional ───────────────────────────────────────────
 *
 * Every one of these platforms sends your own outbound message back to you as
 * an inbound webhook. Without a way to recognise it, every reply an agent sends
 * appears in the thread twice, unread counts never clear, and any auto-reply
 * rule answers itself in a loop that ends when the platform rate-limits the
 * account. The first Prism learned this the hard way and needed a whole class
 * for it; here the external id is unique per channel, so the echo simply finds
 * the message already stored and stops.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Channels ────────────────────────────────────────────────────────
        Schema::create('inbox_channels', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            // whatsapp | messenger | instagram | tiktok | sms | email | webchat
            $table->string('kind', 20);
            $table->string('name');
            // The account on the other side: a phone number, a page id, a
            // handle. What a webhook's payload has to be matched back to.
            $table->string('external_id', 190)->nullable();

            $table->string('credential_ref', 120)->nullable();
            $table->string('webhook_secret', 120)->nullable();
            $table->json('settings')->nullable();

            $table->string('status', 12)->default('active'); // active|paused|broken
            $table->timestamp('last_seen_at')->nullable();
            $table->string('last_error')->nullable();

            // Most platforms only allow a free-form reply within a window of
            // the customer's last message. Stored per channel because the rule
            // differs — 24 hours on WhatsApp, 7 days on Messenger, none on
            // email — and an agent needs to know before they type.
            $table->unsignedSmallInteger('reply_window_hours')->nullable();

            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['business_id', 'kind', 'external_id'], 'channel_unique');
            $table->index(['business_id', 'status']);
        });

        // ── Conversations ───────────────────────────────────────────────────
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inbox_channel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();

            // Their handle on this channel — the phone number, the page-scoped
            // user id. The pair with the channel is what a webhook is matched
            // on, and what makes an inbound message find its thread.
            $table->string('contact_handle', 190);
            $table->string('contact_name')->nullable();
            // The platform's own thread id, where it has one.
            $table->string('external_id', 190)->nullable();

            $table->string('subject')->nullable();
            // open | pending | snoozed | closed
            $table->string('status', 12)->default('open');
            // low | normal | high | urgent
            $table->string('priority', 8)->default('normal');

            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('snoozed_until')->nullable();

            // Denormalised so an inbox list is one query. A list of two hundred
            // conversations that joins messages to find the latest is the query
            // that makes an inbox feel slow.
            $table->timestamp('last_message_at')->nullable();
            $table->string('last_message_preview', 200)->nullable();
            $table->string('last_message_direction', 3)->nullable();  // in | out
            $table->unsignedSmallInteger('unread_count')->default(0);
            $table->unsignedSmallInteger('message_count')->default(0);

            // When the customer last spoke. The clock the reply window runs on,
            // kept apart from last_message_at because our own reply must not
            // extend our own permission to reply.
            $table->timestamp('customer_last_at')->nullable();
            // How long the customer waited for the first human answer. The
            // number every support report is built on, and impossible to
            // reconstruct later without storing it.
            $table->unsignedInteger('first_response_seconds')->nullable();

            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['inbox_channel_id', 'contact_handle'], 'conversation_handle_unique');
            $table->index(['business_id', 'status', 'last_message_at']);
            $table->index(['business_id', 'assigned_to', 'status']);
            $table->index(['customer_id', 'last_message_at']);
        });

        // ── Messages ────────────────────────────────────────────────────────
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();

            $table->string('direction', 3);   // in | out
            // text | image | file | audio | video | location | template | system
            $table->string('kind', 12)->default('text');
            $table->text('body')->nullable();

            // The platform's id for this message. Unique per channel, which is
            // what makes an echo or a retried webhook a no-op rather than a
            // duplicate.
            $table->string('external_id', 190)->nullable();
            // What it is replying to, where the platform says so.
            $table->string('external_reply_to', 190)->nullable();

            // Who said it. A user for an agent, null for the customer, and a
            // separate flag for anything the system generated — because an
            // automated reply is not an agent's response and must not count as
            // one in a response-time report.
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_automated')->default(false);
            // Visible to staff only. A note in the thread rather than a
            // separate place nobody looks.
            $table->boolean('is_internal')->default(false);

            // queued | sent | delivered | read | failed
            $table->string('delivery_status', 10)->default('sent');
            $table->string('failure_reason')->nullable();

            $table->timestamp('occurred_at');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();

            // Exactly what arrived, for the same reason the courier webhooks
            // keep theirs: it is the only way to fix a parsing mistake after
            // the fact.
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->unique(['conversation_id', 'external_id'], 'message_external_unique');
            $table->index(['conversation_id', 'occurred_at']);
            $table->index(['business_id', 'direction', 'occurred_at']);
        });

        Schema::create('message_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_item_id')->nullable()->constrained('media_items')->nullOnDelete();

            // Where it is on the platform, before we have fetched it. Most
            // expire within days, so the url is a lead rather than a home.
            $table->string('remote_url', 500)->nullable();
            $table->string('filename')->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('bytes')->nullable();
            $table->boolean('is_fetched')->default(false);
            $table->timestamps();

            $table->index('message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_attachments');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
        Schema::dropIfExists('inbox_channels');
    }
};
