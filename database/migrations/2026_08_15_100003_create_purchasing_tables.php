<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchasing and procurement.
 *
 * Purchase orders to suppliers, receiving goods, and managing supplier relationships.
 *
 * ── Why there is no `suppliers` table here ───────────────────────────────────
 *
 * One already exists (`2026_08_11_000003_create_payables_tables.php`), wired
 * into Bills and the Purchasing service. `purchase_orders.supplier_id` points
 * at that table. A purchase order precedes a bill — it is the commitment, the
 * bill is the obligation — so they share the same supplier record rather than
 * each domain keeping its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Purchase Orders
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained();
            $table->foreignId('warehouse_id')->nullable()->constrained('stock_locations');

            $table->string('number')->unique();
            $table->date('order_date');
            $table->date('expected_date')->nullable();
            $table->string('status', 20)->default('draft'); // draft, sent, confirmed, received, cancelled
            
            $table->string('currency', 3)->default('USD');
            $table->unsignedBigInteger('subtotal_minor')->default(0);
            $table->unsignedBigInteger('tax_minor')->default(0);
            $table->unsignedBigInteger('shipping_minor')->default(0);
            $table->unsignedBigInteger('total_minor')->default(0);
            
            $table->text('notes')->nullable();
            $table->string('reference')->nullable(); // Supplier's reference
            
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['business_id', 'status']);
            $table->index('supplier_id');
            $table->index('order_date');
        });

        // Purchase Order Lines
        Schema::create('purchase_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants');

            $table->unsignedSmallInteger('line_no');
            $table->string('description');
            $table->decimal('quantity', 12, 3);
            $table->decimal('received_quantity', 12, 3)->default(0);
            $table->string('unit', 20)->default('pcs');
            
            $table->string('currency', 3)->default('USD');
            $table->unsignedBigInteger('unit_price_minor');
            $table->unsignedBigInteger('total_minor');
            
            $table->decimal('tax_rate', 5, 2)->default(0);

            $table->timestamps();

            $table->index(['purchase_order_id', 'line_no']);
        });

        // Goods Receipts (receiving shipments)
        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('purchase_order_id')->constrained();
            $table->foreignId('warehouse_id')->constrained('stock_locations');

            $table->string('receipt_number')->unique();
            $table->date('receipt_date');
            $table->text('notes')->nullable();
            
            $table->foreignId('received_by')->constrained('users');
            $table->timestamps();

            $table->index('purchase_order_id');
            $table->index('receipt_date');
        });

        // Goods Receipt Lines
        Schema::create('goods_receipt_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goods_receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_order_line_id')->constrained();
            $table->foreignId('variant_id')->constrained('product_variants');

            $table->decimal('quantity', 12, 3);
            $table->string('condition', 20)->default('good'); // good, damaged, rejected
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index('goods_receipt_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_receipt_lines');
        Schema::dropIfExists('goods_receipts');
        Schema::dropIfExists('purchase_order_lines');
        Schema::dropIfExists('purchase_orders');
    }
};
