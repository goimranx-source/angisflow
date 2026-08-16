<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The credential vault.
 *
 * ── Why this exists ───────────────────────────────────────────────────────────
 *
 * courier_connections and inbox_channels both have a credential_ref column that
 * was always meant to point here. Without this table, any integration that needs
 * an API key or OAuth token has nowhere safe to put it — the only alternative is
 * a plain column, which is one SELECT away from a breach.
 *
 * ── Why the payload is encrypted at the application layer ────────────────────
 *
 * Disk encryption (at-rest) protects against a stolen drive. It does not protect
 * against a SQL injection that reads the credentials table, a misconfigured
 * backup that lands in an S3 bucket, or a compromised read replica. Application-
 * layer encryption with APP_KEY means the ciphertext is useless without the key,
 * which is not in the database.
 *
 * The trade is that the payload cannot be queried or indexed. That is fine: the
 * vault is a lookup by ref, not a search. The ref is a plain ULID stored in
 * clear text so the FK pointer in courier_connections and inbox_channels can be
 * resolved without decrypting anything.
 *
 * ── Why ref is separate from public_id ───────────────────────────────────────
 *
 * public_id is the URL identifier — what the API and the UI use. ref is the
 * pointer stored in other tables. They are the same ULID value, but naming them
 * separately makes the intent clear: a caller looking up a credential by ref is
 * doing a vault lookup, not a URL resolution.
 *
 * ── What is not here ─────────────────────────────────────────────────────────
 *
 * Rotation history. The current design keeps one live credential per ref. A
 * rotation replaces the payload and stamps last_rotated_at. If a full rotation
 * log is needed later, a credential_rotations table can be added without
 * changing this one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credentials', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();

            // The pointer stored in courier_connections.credential_ref and
            // inbox_channels.credential_ref. Same value as public_id, named
            // separately so the intent is unambiguous at the call site.
            $table->char('ref', 26)->unique();

            $table->foreignId('account_id')->constrained()->cascadeOnDelete();

            $table->string('label');

            // api_key | oauth_token | basic_auth | generic
            // Stored so the UI can show the right fields and the adapter can
            // know what shape to expect without decrypting first.
            $table->string('kind', 20)->default('api_key');

            // The actual secret. Encrypted by CredentialPayloadCast using
            // Crypt::encrypt() before it touches the database. The column is
            // TEXT because the ciphertext is longer than the plaintext.
            $table->text('payload');

            // Informational — when the payload was last replaced. Not used for
            // access control, but surfaced in the UI so an operator can see
            // that a credential has not been rotated in two years.
            $table->timestamp('last_rotated_at')->nullable();

            // Stamped by Vault::retrieve() so stale credentials can be found.
            // A credential that has never been used is either new or forgotten.
            $table->timestamp('last_used_at')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['account_id', 'kind']);
            $table->index(['account_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credentials');
    }
};
