<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Turning conversations into work that can be measured.
 *
 * ── A ticket wraps a conversation; it does not replace one ───────────────────
 *
 * The obvious build gives tickets their own messages table, and it is the
 * classic mistake. A customer messages on WhatsApp, an agent escalates it, and
 * now the thread exists twice — the customer replying to one copy while the
 * agent works the other. Every helpdesk that has done this ends up with a
 * synchronisation job that is permanently slightly wrong.
 *
 * So a ticket points at the conversation. It adds what a conversation does not
 * have — a category, a promise about when it will be answered, a resolution —
 * and the messages stay in exactly one place.
 *
 * ── SLAs run on working hours, not wall clock ────────────────────────────────
 *
 * "Four hour response" measured against the clock means a message at 5pm on
 * Friday is breached before anybody could have read it. Measured against the
 * hours a business actually works, it means what everybody assumed it meant.
 * Storing both the clock time and the working-hours time is the only way to
 * show an honest figure and still answer "how long did the customer actually
 * wait", which are different questions and both get asked.
 *
 * ── Why the bot's rules are rows ─────────────────────────────────────────────
 *
 * Same reasoning as the pipeline stages and the courier statuses. A subscriber
 * knows their five most common questions; we do not, and cannot. What we
 * provide is the machinery — match, answer, hand off — and the machinery has to
 * be configurable by somebody who does not write code.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Categories ──────────────────────────────────────────────────────
        Schema::create('helpdesk_categories', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('helpdesk_categories')->nullOnDelete();

            $table->string('name');
            $table->string('slug', 120);
            $table->string('description')->nullable();
            // Different questions deserve different promises: "where is my
            // order" is not "my payment failed".
            $table->unsignedSmallInteger('response_minutes')->nullable();
            $table->unsignedSmallInteger('resolution_minutes')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['business_id', 'slug']);
        });

        // ── Tickets ─────────────────────────────────────────────────────────
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            // The thread it is about. Nullable because a ticket can be raised
            // by an agent from a phone call that had no conversation.
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('helpdesk_category_id')->nullable()->constrained()->nullOnDelete();
            // What it is about, where it is about something: an order, a
            // shipment, an invoice. The link that makes "show me this
            // customer's problems with this order" answerable.
            $table->string('subject_type', 60)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            $table->string('number', 40);
            $table->string('title');
            $table->string('status', 12)->default('open');   // open|pending|solved|closed
            $table->string('priority', 8)->default('normal'); // low|normal|high|urgent

            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();

            // Both clocks. See the note above about why one is not enough.
            $table->timestamp('response_due_at')->nullable();
            $table->timestamp('resolution_due_at')->nullable();
            $table->timestamp('first_responded_at')->nullable();
            $table->unsignedInteger('response_working_seconds')->nullable();
            $table->unsignedInteger('resolution_working_seconds')->nullable();
            $table->boolean('response_breached')->default(false);
            $table->boolean('resolution_breached')->default(false);

            // Paused while waiting on the customer. Without this every ticket
            // where somebody was asked for a photo breaches, and the SLA report
            // becomes a list of the team's most patient members.
            $table->timestamp('paused_at')->nullable();
            $table->unsignedInteger('paused_seconds')->default(0);

            $table->string('resolution', 40)->nullable();
            $table->text('resolution_note')->nullable();
            $table->unsignedTinyInteger('satisfaction')->nullable();  // 1–5
            $table->string('satisfaction_comment')->nullable();

            $table->timestamp('solved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'number']);
            $table->index(['business_id', 'status', 'response_due_at']);
            $table->index(['business_id', 'assigned_to', 'status']);
            $table->index(['subject_type', 'subject_id']);
        });

        // ── Knowledge base ──────────────────────────────────────────────────
        Schema::create('kb_articles', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('helpdesk_category_id')->nullable()->constrained()->nullOnDelete();

            $table->string('title');
            $table->string('slug', 160);
            $table->text('summary')->nullable();
            $table->longText('body');

            // The same article serves three jobs, and a flag each rather than
            // three copies: the public help centre, the agent's canned reply,
            // and the bot's answer. Three copies drift, and the customer gets
            // the one nobody updated.
            $table->boolean('is_public')->default(true);
            $table->boolean('is_canned_reply')->default(false);
            $table->boolean('is_bot_answer')->default(false);

            // What somebody might type to mean this. Kept as text rather than a
            // join table because it is written whole, read whole, and searched
            // with LIKE — a table of one-word rows would be three joins to
            // answer one question.
            $table->text('keywords')->nullable();

            $table->string('status', 12)->default('draft'); // draft|published|archived
            $table->unsignedInteger('view_count')->default(0);
            $table->unsignedInteger('helpful_count')->default(0);
            $table->unsignedInteger('unhelpful_count')->default(0);

            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'slug']);
            $table->index(['business_id', 'status', 'is_public']);
        });

        // ── The bot ─────────────────────────────────────────────────────────
        Schema::create('bot_rules', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            // Null means every channel. A rule that answers "where is my order"
            // is as useful on WhatsApp as on the website.
            $table->foreignId('inbox_channel_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name');
            // keyword | greeting | out_of_hours | no_agent | fallback
            $table->string('trigger', 20)->default('keyword');
            $table->text('keywords')->nullable();

            // reply | article | handoff | tag | none
            $table->string('action', 12)->default('reply');
            $table->text('reply_body')->nullable();
            $table->foreignId('kb_article_id')->nullable()->constrained('kb_articles')->nullOnDelete();

            // The cap that makes a bot tolerable. After this many unhelpful
            // turns it stops and fetches a person, because a customer trapped
            // in a loop with a machine is a customer who leaves and says why in
            // public.
            $table->unsignedTinyInteger('max_attempts')->default(2);

            $table->unsignedSmallInteger('priority')->default(100);
            $table->boolean('is_enabled')->default(true);
            $table->unsignedInteger('fired_count')->default(0);
            $table->unsignedInteger('handoff_count')->default(0);
            $table->timestamps();

            $table->index(['business_id', 'is_enabled', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_rules');
        Schema::dropIfExists('kb_articles');
        Schema::dropIfExists('tickets');
        Schema::dropIfExists('helpdesk_categories');
    }
};
