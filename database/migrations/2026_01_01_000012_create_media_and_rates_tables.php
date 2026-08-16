<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /**
         * The media library.
         *
         * One file, used anywhere. The alternative — a file input on every form
         * that wants a picture — leaves the same logo uploaded six times under
         * six names, with nothing saying which of them anything is using.
         */
        Schema::create('media_items', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();

            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('uploaded_by_user_id')->nullable();

            // The path on whichever disk is configured — local now, S3 later.
            // Storing the path rather than a URL is what lets the disk change
            // without rewriting every row.
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size');

            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();

            $table->string('title')->nullable();
            $table->string('alt_text')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // The library, newest first — the only listing this table has.
            $table->index(['account_id', 'id']);

            // "Every image", for the picker, which never wants a PDF.
            $table->index(['account_id', 'mime_type']);
        });

        /**
         * What one unit of a currency is worth in the books' currency.
         *
         * Per account, because a rate is a statement about *this* business's
         * books: one subscriber types the rate their bank gave them, another
         * takes the mid-market feed, and neither should move the other's
         * figures.
         */
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();

            $table->foreignId('account_id')->constrained()->cascadeOnDelete();

            // What the rate is quoted against, kept on the row rather than
            // assumed from the account's current setting. Change the base and
            // yesterday's rates are still readable as what they were.
            $table->char('base', 3);
            $table->char('code', 3);

            // Stored as "one unit of code is worth this many base" — the
            // direction anyone actually quotes: 1 USD = 122 BDT, not
            // 1 BDT = 0.0082 USD. Converting a store's takings into the books
            // is then a multiplication, which is also the commonest thing
            // asked of it.
            //
            // decimal(20,10) rather than a float: a rate is multiplied by every
            // figure that passes through it, so an error in the eighth place
            // does not stay in the eighth place.
            $table->decimal('rate', 20, 10);

            // 'manual' is never overwritten by a fetch. Somebody who typed the
            // rate their bank gave them wants that figure in the books, not the
            // mid-market one.
            $table->string('source', 10)->default('manual');
            $table->timestamp('fetched_at')->nullable();

            $table->timestamps();

            $table->unique(['account_id', 'base', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
        Schema::dropIfExists('media_items');
    }
};
