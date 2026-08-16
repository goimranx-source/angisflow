<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One person, however many ways they have reached us.
 *
 * ── Identity is the join key, not the customer row ───────────────────────────
 *
 * The same human being arrives as a converted lead with a work email, a
 * walk-in at the till with a phone number, a guest checkout on the storefront
 * with a personal email, and a WhatsApp conversation with a third number. Four
 * customer rows, one person, and every figure about them — lifetime value,
 * order count, whether they are a good customer or a risky one — is wrong in
 * four places.
 *
 * The instinct is to match on customers.email. That can only ever find exact
 * duplicates of the primary address, which is the one case that barely happens:
 * people do not use the same address twice, they use a different one. So
 * identities are their own table, a customer has many, and matching happens
 * there. A person with two emails and three phone numbers is one customer with
 * five identities.
 *
 * ── Merging is recorded, not destructive ─────────────────────────────────────
 *
 * The losing customer is kept, marked merged, and points at the survivor. Two
 * reasons, and the second is the one people forget: a merge is a judgement, and
 * judgements are sometimes wrong — an audit trail is what makes one reversible.
 * The first is simpler: an order, an invoice or a message may still hold the
 * old id, and a row that vanished takes those references with it.
 *
 * ── Why stats are stored rather than computed ────────────────────────────────
 *
 * Lifetime value on a customer list of ten thousand is ten thousand sums over
 * orders. It is read constantly — every list, every profile, every segment —
 * and changes only when an order changes. Recomputed on write, from the orders
 * themselves, with a rebuild that can be run against the source at any time.
 * Same bargain as stock levels: a cache is honest as long as it is checkable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            // email | phone | whatsapp | messenger | instagram | tiktok
            // | external | device | tax_number
            $table->string('kind', 20);

            // As given, for display — "+880 1712-345678".
            $table->string('value', 190);
            // Reduced to something comparable: lowercased email, digits-only
            // phone. Matching uses this and only this, because two people
            // typing the same number differently must still be one person.
            $table->string('normalised', 190);

            // Which platform an external id belongs to. Two storefronts both
            // numbering their customers from 1 is normal.
            $table->string('source', 40)->nullable();

            // Whether we have any evidence this really is them — a clicked
            // link, a code entered, an inbound message from that number.
            $table->boolean('is_verified')->default(false);
            $table->boolean('is_primary')->default(false);

            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            // The same identity cannot belong to two customers in one book.
            // This is the constraint that makes the whole design work: a
            // duplicate is caught at the moment of writing rather than found
            // by a report months later.
            $table->unique(['business_id', 'kind', 'normalised', 'source'], 'identity_unique');
            $table->index(['customer_id', 'kind']);
            $table->index(['business_id', 'normalised']);
        });

        Schema::table('customers', function (Blueprint $table) {
            // Set when this record lost a merge. Kept, not deleted — see above.
            $table->foreignId('merged_into_id')->nullable()->after('is_active')
                ->constrained('customers')->nullOnDelete();
            $table->timestamp('merged_at')->nullable()->after('merged_into_id');

            // Rolled up from orders. See the note about why these are stored.
            $table->unsignedInteger('order_count')->default(0)->after('merged_at');
            $table->unsignedBigInteger('lifetime_value_minor')->default(0)->after('order_count');
            $table->unsignedBigInteger('average_order_minor')->default(0)->after('lifetime_value_minor');
            $table->date('first_order_on')->nullable()->after('average_order_minor');
            $table->date('last_order_on')->nullable()->after('first_order_on');
            // Orders that came back. The number that separates a good customer
            // from a busy one.
            $table->unsignedInteger('return_count')->default(0)->after('last_order_on');

            $table->index(['business_id', 'lifetime_value_minor']);
            $table->index(['business_id', 'last_order_on']);
        });

        // ── Segments ────────────────────────────────────────────────────────
        //
        // A saved question, not a saved list. "Customers who spent over 50,000
        // last year" has to mean the same thing next month, and a stored list
        // of ids means it silently stops.
        Schema::create('customer_segments', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('description')->nullable();
            // The question, as structured filters rather than SQL. A segment
            // holding SQL is a segment a subscriber can use to read another
            // subscriber's data.
            $table->json('rules');

            $table->boolean('is_dynamic')->default(true);
            // Cached so a list can show "1,204 customers" without running every
            // segment's query to draw a menu.
            $table->unsignedInteger('member_count')->default(0);
            $table->timestamp('counted_at')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'name']);
        });

        // Only for segments somebody pinned deliberately — a campaign list that
        // must not change under the campaign.
        Schema::create('customer_segment_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_segment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->timestamp('added_at');

            $table->unique(['customer_segment_id', 'customer_id'], 'segment_member_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_segment_members');
        Schema::dropIfExists('customer_segments');

        Schema::table('customers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('merged_into_id');
            $table->dropColumn([
                'merged_at', 'order_count', 'lifetime_value_minor', 'average_order_minor',
                'first_order_on', 'last_order_on', 'return_count',
            ]);
        });

        Schema::dropIfExists('customer_identities');
    }
};
