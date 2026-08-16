<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch and Serial Number Tracking.
 *
 * For businesses that need to track specific units of stock, either by batch
 * (manufacturing lots, expiry dates) or by individual serial number.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Batch Numbers
        Schema::create('batches', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants');

            $table->string('batch_number')->unique();
            $table->string('supplier_batch')->nullable();
            $table->date('manufactured_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->date('best_before_date')->nullable();
            
            $table->decimal('quantity_available', 12, 3)->default(0);
            $table->string('status', 20)->default('active'); // active, quarantine, expired, recalled
            
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['business_id', 'variant_id']);
            $table->index(['status', 'expiry_date']);
            $table->index('batch_number');
        });

        // Serial Numbers
        Schema::create('serial_numbers', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants');
            $table->foreignId('batch_id')->nullable()->constrained();

            $table->string('serial_number')->unique();
            $table->string('status', 20)->default('in_stock'); // in_stock, sold, warranty, returned, defective
            $table->foreignId('location_id')->nullable()->constrained('stock_locations');
            
            $table->foreignId('sold_in_order_id')->nullable()->constrained('orders');
            $table->timestamp('sold_at')->nullable();
            $table->foreignId('sold_to_customer_id')->nullable()->constrained('customers');
            
            $table->date('warranty_until')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['business_id', 'variant_id', 'status']);
            $table->index('serial_number');
            $table->index('sold_in_order_id');
        });

        // Stock Movement Lines now track batch/serial
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('batch_id')->nullable()->after('variant_id')->constrained()->nullOnDelete();
            $table->foreignId('serial_number_id')->nullable()->after('batch_id')->constrained('serial_numbers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropForeign(['batch_id']);
            $table->dropForeign(['serial_number_id']);
            $table->dropColumn(['batch_id', 'serial_number_id']);
        });
        
        Schema::dropIfExists('serial_numbers');
        Schema::dropIfExists('batches');
    }
};
