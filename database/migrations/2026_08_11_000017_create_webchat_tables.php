<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The chat widget that goes on the subscriber's own website.
 *
 * ── The one channel where we are the platform ────────────────────────────────
 *
 * Every other channel in the inbox belongs to somebody else, and their rules
 * apply. This one is ours, which means the constraints are gone — no reply
 * window, no template approval — and so is the safety net. There is no Meta
 * deciding who may talk to whom. That is entirely our problem, and it is the
 * whole design of these tables.
 *
 * ── Two secrets, and why they are different ──────────────────────────────────
 *
 * The widget key is public. It sits in a script tag on a page anybody can view
 * source on, and no amount of obfuscation changes that. So it may only do
 * public things: identify which business the widget belongs to, and start a
 * conversation.
 *
 * The visitor token is not public. It is minted per session, unguessable, and
 * scopes every subsequent request to one conversation. Without that split, a
 * key lifted from a page's source reads every conversation the business has
 * ever had — which is the failure mode of a surprising number of shipped chat
 * widgets.
 *
 * ── The domain allowlist ─────────────────────────────────────────────────────
 *
 * A public key that works from anywhere is a public key somebody else can put
 * on their site: your agents answering their customers, your quota, your name
 * on it. Checking the Origin header is not perfect — a script can forge one —
 * but it stops the casual case entirely, and the casual case is what actually
 * happens.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webchat_widgets', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            // The conversations it produces land in the ordinary inbox, on a
            // channel of kind `webchat`. The widget is a front door, not a
            // separate messaging system.
            $table->foreignId('inbox_channel_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            // Public, and in the page source. See above.
            $table->string('widget_key', 40)->unique();

            // Empty means any origin, which is the right default only while
            // somebody is testing. The settings screen says so.
            $table->json('allowed_origins')->nullable();

            $table->string('title')->default('Chat with us');
            $table->string('greeting')->nullable();
            $table->string('away_message')->nullable();
            $table->string('colour', 20)->default('#0891b2');
            $table->string('position', 12)->default('right');
            $table->string('avatar_url', 500)->nullable();

            // When somebody is actually there. A widget that promises a live
            // answer at 2am and delivers one at 9 is worse than one that says
            // when it will reply.
            $table->json('office_hours')->nullable();
            $table->string('timezone', 64)->nullable();

            // What to ask before the conversation starts. Nothing is required
            // by default: a form in front of a question is how a live chat
            // becomes a contact form nobody fills in.
            $table->boolean('require_name')->default(false);
            $table->boolean('require_email')->default(false);
            $table->boolean('require_phone')->default(false);

            $table->boolean('is_enabled')->default(true);
            $table->timestamps();

            $table->index(['business_id', 'is_enabled']);
        });

        // ── Visitors ────────────────────────────────────────────────────────
        //
        // Somebody on the website who has not said who they are. Kept apart
        // from customers on purpose: most never become one, and filling the
        // customer table with anonymous browsers makes every customer figure
        // meaningless.
        Schema::create('webchat_sessions', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('webchat_widget_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();

            // Hashed, never stored raw — the same reasoning as a password. A
            // leaked backup of this table must not hand somebody every live
            // conversation.
            $table->string('token_hash', 64)->unique();

            // Kept in the browser so a visitor who returns tomorrow continues
            // the same conversation rather than starting again.
            $table->string('visitor_key', 64)->nullable();

            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();

            // Context worth having before an agent replies: what page they are
            // on, where they came from, what they are using.
            $table->string('page_url', 500)->nullable();
            $table->string('page_title')->nullable();
            $table->string('referrer', 500)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->char('country', 2)->nullable();

            $table->timestamp('started_at');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'last_seen_at']);
            $table->index(['webchat_widget_id', 'visitor_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webchat_sessions');
        Schema::dropIfExists('webchat_widgets');
    }
};
