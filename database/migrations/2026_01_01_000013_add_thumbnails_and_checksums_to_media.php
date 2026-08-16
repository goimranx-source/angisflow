<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two columns that make a media library survive real use.
 *
 * ── thumb_path ───────────────────────────────────────────────────────────────
 *
 * A grid of forty tiles rendering forty originals is forty full-size downloads.
 * Somebody with a library of phone photographs — four megabytes each — opens the
 * picker and pulls a hundred and sixty megabytes to look at forty thumbnails.
 * The browser scales them to 160px and throws the rest away.
 *
 * `loading="lazy"` helps and does not fix it: it delays the download, it does
 * not shrink it. The fix is a derivative, generated once on upload and served
 * everywhere a preview is wanted.
 *
 * ── checksum ─────────────────────────────────────────────────────────────────
 *
 * People upload the same logo repeatedly — from the desktop, then from the
 * downloads folder, then again next month because they could not find it. Each
 * one is a new row pointing at a byte-identical file, and the library slowly
 * fills with copies nobody can tell apart.
 *
 * A hash of the contents makes that answerable: upload something already held
 * and the existing item comes back instead of a second copy. Indexed with the
 * account because the question is only ever asked inside one subscriber's
 * library — and because two subscribers holding the same stock photo is not a
 * duplicate, it is two customers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_items', function (Blueprint $table) {
            // Null for anything with no sensible preview — a PDF, an SVG (which
            // is already small and scales for free). Readers fall back to the
            // original, so a null is a valid answer rather than a missing one.
            $table->string('thumb_path')->nullable()->after('path');

            $table->unsignedInteger('thumb_width')->nullable()->after('height');
            $table->unsignedInteger('thumb_height')->nullable()->after('thumb_width');

            // SHA-256 of the file's bytes. CHAR(64) because it is always exactly
            // that, and a fixed-width index is smaller and faster to probe.
            $table->char('checksum', 64)->nullable()->after('size');

            $table->index(['account_id', 'checksum'], 'media_account_checksum');
        });
    }

    public function down(): void
    {
        Schema::table('media_items', function (Blueprint $table) {
            $table->dropIndex('media_account_checksum');
            $table->dropColumn(['thumb_path', 'thumb_width', 'thumb_height', 'checksum']);
        });
    }
};
