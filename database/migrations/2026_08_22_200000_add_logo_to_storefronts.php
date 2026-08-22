<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The mark that goes at the top of a shop's paperwork.
 *
 * ── Why it belongs to the storefront, not the business ───────────────────────
 *
 * Because a business here can run several shops, and an invoice is issued by
 * the shop the order was placed in. One logo on the business would put the
 * wrong brand on every order from the second shop — which is worse than no
 * logo, because it is confidently wrong in front of a customer.
 *
 * A counter sale has no storefront, so it has no logo, and the invoice falls
 * back to the shop's name set in type. That is deliberate: a blank space where
 * a mark should be reads as a fault, a name does not.
 *
 * ── Why a reference and not a path ───────────────────────────────────────────
 *
 * The media library already stores files, records their dimensions, makes
 * thumbnails and knows how to build a URL for whichever disk is configured.
 * Storing a path here would be a second, worse copy of all of that, and would
 * quietly break the day the disk changes.
 *
 * nullOnDelete rather than cascade: removing a picture from the library should
 * cost a shop its logo, not its existence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('storefronts', function (Blueprint $table): void {
            $table->foreignId('logo_media_id')
                ->nullable()
                ->after('code')
                ->constrained('media_items')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('storefronts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('logo_media_id');
        });
    }
};
