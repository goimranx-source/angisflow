<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Orders: the thing every other part of the tool eventually points at.
 *
 * ── One status is not enough, and this is the mistake to avoid ───────────────
 *
 * The first Prism had a single column: pending | paid | refunded | cancelled.
 * It cannot express the two states a shop is in most of the time. Prepaid and
 * not yet shipped is "paid" — which tells the warehouse nothing. Shipped on
 * credit or cash on delivery is "pending" — which tells the person chasing
 * money nothing, and looks identical to an order nobody has touched.
 *
 * Every system that starts with one status ends up with values like
 * paid_pending_shipment and partially_refunded_shipped, which is a two-axis
 * model spelled badly. So there are two axes here from the start:
 *
 *   status             where the order is in its own life
 *   fulfilment_status  how much of it has left the building
 *   payment_status     how much of it has been paid for
 *
 * The last two are derived and stored, because "unpaid orders" and "unshipped
 * orders" are the two most-asked questions of this table and neither should be
 * a scan over lines.
 *
 * ── Orders do not have their own money model ─────────────────────────────────
 *
 * The old schema had order_payments beside the ledger's own transactions: two
 * records of the same money, kept in step by hand, which is a reconciliation
 * problem invented rather than solved. Here an order raises an invoice, and the
 * invoice is paid through Payments — the same path a manually raised invoice
 * takes. There is one answer to "what does this customer owe", and it does not
 * depend on which screen asked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();

            $table->string('number', 40);
            $table->date('ordered_on');

            // draft | confirmed | fulfilled | completed | cancelled
            $table->string('status', 12)->default('draft');
            // unfulfilled | partial | fulfilled
            $table->string('fulfilment_status', 12)->default('unfulfilled');
            // unpaid | partial | paid | refunded
            $table->string('payment_status', 12)->default('unpaid');

            // Where it came from, and its id there. The pair is what makes a
            // webhook idempotent — the same Shopify order arriving twice must
            // not become two orders.
            $table->string('channel', 30)->default('manual');
            $table->string('external_ref', 120)->nullable();

            // Cash on delivery changes who holds the money between despatch and
            // settlement, which is a different receivable from an ordinary one.
            $table->boolean('is_cod')->default(false);

            $table->char('currency', 3);
            $table->unsignedBigInteger('subtotal_minor')->default(0);
            $table->unsignedBigInteger('discount_minor')->default(0);
            $table->unsignedBigInteger('shipping_minor')->default(0);
            $table->unsignedBigInteger('tax_minor')->default(0);
            $table->unsignedBigInteger('total_minor')->default(0);
            $table->unsignedBigInteger('paid_minor')->default(0);

            // What the goods cost us, filled in at fulfilment from the batches
            // actually shipped. Stored because margin per order is asked for
            // constantly and recomputing it means re-reading stock movements.
            $table->unsignedBigInteger('cost_minor')->default(0);

            $table->foreignId('stock_location_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();

            $table->string('shipping_name')->nullable();
            $table->string('shipping_phone', 40)->nullable();
            $table->string('shipping_address')->nullable();
            $table->string('shipping_city', 80)->nullable();
            $table->string('shipping_postcode', 20)->nullable();
            $table->char('shipping_country', 2)->nullable();

            $table->text('notes')->nullable();
            $table->string('cancelled_reason')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'number']);
            // The idempotency key for anything arriving from a storefront.
            $table->unique(['business_id', 'channel', 'external_ref'], 'order_channel_ref_unique');
            $table->index(['business_id', 'status', 'ordered_on']);
            $table->index(['business_id', 'fulfilment_status']);
            $table->index(['business_id', 'payment_status']);
            $table->index(['business_id', 'customer_id']);
        });

        Schema::create('order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedSmallInteger('line_no')->default(1);

            // Copied at the moment of ordering, not read from the catalogue
            // later. A customer bought "Oxford Shirt, Blue, Large" at that
            // price — renaming the product or repricing it next month must not
            // rewrite what they were sold.
            $table->string('sku', 60)->nullable();
            $table->string('description');

            $table->decimal('quantity', 18, 4);
            $table->decimal('quantity_fulfilled', 18, 4)->default(0);

            $table->unsignedBigInteger('unit_price_minor')->default(0);
            $table->unsignedBigInteger('discount_minor')->default(0);
            $table->decimal('tax_rate', 7, 4)->default(0);
            $table->unsignedBigInteger('tax_minor')->default(0);
            $table->unsignedBigInteger('total_minor')->default(0);
            // What these particular units cost, from the batches shipped.
            $table->unsignedBigInteger('cost_minor')->default(0);
            $table->char('currency', 3);

            $table->foreignId('stock_reservation_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['order_id', 'line_no']);
            $table->index('product_variant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_lines');
        Schema::dropIfExists('orders');
    }
};
