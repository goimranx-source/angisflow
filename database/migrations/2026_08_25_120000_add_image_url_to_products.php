<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a product's picture lives when this application did not upload it.
 *
 * ── Why not the media library that already exists ────────────────────────────
 *
 * There is one: `product_media` joins a product to a `media_item`, with a
 * stored file, a thumbnail and a checksum. It is the right home for a picture
 * somebody uploads here, and it is empty, because every product in this
 * business arrived from a shop that already hosts its own images.
 *
 * Filling it from a sync would mean downloading every image, storing a second
 * copy, and keeping that copy in step with a shop that can change the picture
 * whenever it likes. That is a real feature — it is what you want before
 * printing a catalogue, or if the shop might go away — and it is not what is
 * needed to show a thumbnail next to an order line.
 *
 * So a column holding the address the shop already serves. Nothing is
 * downloaded, nothing goes stale, and a product that later gets a real uploaded
 * image simply outranks it — see LinePresentation, which prefers the media
 * library and falls back to here.
 *
 * ── Why the variant has one too ──────────────────────────────────────────────
 *
 * Because a product with four colours has four pictures, and the one that
 * belongs beside a line saying "blue" is the blue one. WooCommerce sends it on
 * the variation rather than the parent, so there is somewhere for it to land.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            // Long, because these are query strings more often than not:
            // a CDN signature and a resize spec run well past a URL's polite
            // length, and a truncated address is a broken picture.
            $table->string('image_url', 1024)->nullable()->after('summary');
        });

        Schema::table('product_variants', function (Blueprint $table): void {
            $table->string('image_url', 1024)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('image_url');
        });

        Schema::table('product_variants', function (Blueprint $table): void {
            $table->dropColumn('image_url');
        });
    }
};
