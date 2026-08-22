<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The shop's own id for a line on an order.
 *
 * ── Why a line needs its own identity ────────────────────────────────────────
 *
 * Because editing an order is not the same as replacing it. Every one of these
 * APIs distinguishes a line item sent *with* an id — update this row — from one
 * sent without — add a row. Without the id there is only the second option, so
 * changing a quantity here would leave the shop holding the original line and a
 * new one beside it, and doing it twice would leave three.
 *
 * The alternative, clearing the order's lines and writing them again, throws
 * away what the shop attached to those rows: its tax calculations, its stock
 * movements, and any reference a fulfilment or refund already made to them.
 *
 * Nullable, because a line has no external id until the shop has told us one:
 * an order raised at the counter has none at all, and a line added here has
 * none until it has been pushed once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_lines', function (Blueprint $table): void {
            $table->string('external_id', 64)->nullable()->after('line_no');

            // Looked up per order when building a push, never across the table.
            $table->index(['order_id', 'external_id'], 'order_lines_order_external_index');
        });
    }

    public function down(): void
    {
        Schema::table('order_lines', function (Blueprint $table): void {
            $table->dropIndex('order_lines_order_external_index');
            $table->dropColumn('external_id');
        });
    }
};
