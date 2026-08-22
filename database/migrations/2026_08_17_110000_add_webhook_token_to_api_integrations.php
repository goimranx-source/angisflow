<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The secret in a connection's own webhook address.
 *
 * ── Why a column, and why one each ───────────────────────────────────────────
 *
 * A platform pushes to a URL we hand it. That URL has to identify which
 * connection is speaking before anything is read from the body, because the
 * body is the untrusted part — it is the one thing an attacker controls
 * entirely. A token in the path answers "who claims to be calling" cheaply and
 * without parsing anything.
 *
 * One token per connection rather than one per account: revoking a shop's
 * access, or rotating a token after a leak, must not silently break every other
 * shop the same subscriber runs.
 *
 * It is not the whole of the security story and is not meant to be. Each driver
 * also verifies a signature over the payload — WooCommerce's
 * X-WC-Webhook-Signature, Shopify's X-Shopify-Hmac-Sha256, an HMAC for the
 * generic one — so a token that leaks into a log or a proxy trace still cannot
 * be used to post a forged order. The token routes; the signature authorises.
 *
 * Unique so a lookup is one indexed read, and nullable so a connection can
 * exist before anyone has decided it should receive webhooks at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_integrations', function (Blueprint $table) {
            $table->string('webhook_token', 64)->nullable()->unique()->after('sync_settings');
        });
    }

    public function down(): void
    {
        Schema::table('api_integrations', function (Blueprint $table) {
            $table->dropUnique(['webhook_token']);
            $table->dropColumn('webhook_token');
        });
    }
};
