<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The order fields every commerce platform has and this one did not.
 *
 * ── Where the list came from ─────────────────────────────────────────────────
 *
 * Reading the order schemas of eight platforms — WooCommerce, Shopify,
 * BigCommerce, Magento, Amazon, Lazada/Daraz, Etsy and Medusa — and keeping
 * what five or more of them carry. Each column below is a field an adapter can
 * expect to find, that this application had nowhere to put.
 *
 * ── Why they are stored here rather than left in custom fields ───────────────
 *
 * Because a custom field is a shop's private business. `_orddd_timestamp` means
 * a delivery date to one WooCommerce shop and nothing to anybody else, so
 * nothing can be built on it: no "late deliveries" list, no filter, no report.
 * A column with a name this application knows is the thing every shop's own
 * version can be mapped *onto*, and it is the mapping that turns eight private
 * conventions into one field a screen can use.
 *
 * ── What is deliberately absent ──────────────────────────────────────────────
 *
 * `shipped_on` and `delivered_on`. Both are already answered by the shipment
 * attached to the order, and a second copy on the order is a second thing to
 * keep in step — with the courier as the only authority for either, and the
 * order's copy the one that goes stale.
 *
 * `channel` is also untouched, despite looking like a duplicate of `source`.
 * They are different questions: `channel` is which part of *this* application
 * made the order (`pos`, `api`, `manual`, `quote`), and `source` is where the
 * shop says the customer came from (`facebook`). Merging them would lose the
 * first, which several code paths set on every order they create.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            /*
             * How it was paid, as the shop names it.
             *
             * On all eight platforms. `is_cod` is a boolean where the real
             * answer is bkash, nagad, card, bank transfer or cash — and for a
             * business that settles with a courier, the difference decides
             * whether anybody owes it money.
             */
            $table->string('payment_method', 60)->nullable()->after('payment_status');

            // The gateway's own reference: the thing you quote when a customer
            // says they paid and the money cannot be found.
            $table->string('transaction_ref', 120)->nullable()->after('payment_method');

            // When, as opposed to how much. The order stored a paid amount and
            // never the moment it arrived.
            $table->timestamp('paid_at')->nullable()->after('transaction_ref');

            /*
             * The date promised to the customer.
             *
             * Six of the eight carry one, under six names — Amazon has four of
             * them. This is the promise, not the fact: what actually happened
             * is the shipment's delivered_at, and a late order is only visible
             * when both exist to be compared.
             */
            $table->date('promised_delivery_on')->nullable()->after('ordered_on');

            // What the customer chose and paid for — "Free shipping", "Inside
            // Dhaka". Not the courier eventually used, which is the shipment's.
            $table->string('shipping_method', 120)->nullable()->after('shipping_country');

            // Where the shop says the sale came from. See the note above on why
            // this is not `channel`.
            $table->string('source', 60)->nullable()->after('channel');

            /*
             * The note the customer must not read.
             *
             * Six platforms hold two kinds and this one held a single `notes`
             * field that mixed them — which is fine until the day one is
             * printed on an invoice.
             */
            $table->text('staff_notes')->nullable()->after('notes');

            // Seven of eight track it. There was a Refunded status here with no
            // figure behind it, so the books could not tell a full refund from
            // a partial one.
            $table->bigInteger('refunded_minor')->default(0)->after('paid_minor');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn([
                'payment_method',
                'transaction_ref',
                'paid_at',
                'promised_delivery_on',
                'shipping_method',
                'source',
                'staff_notes',
                'refunded_minor',
            ]);
        });
    }
};
