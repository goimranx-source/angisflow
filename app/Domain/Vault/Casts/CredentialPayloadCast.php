<?php

declare(strict_types=1);

namespace App\Domain\Vault\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * Encrypts the credential payload before it touches the database and decrypts
 * it on the way back out.
 *
 * The cast is the only place in the codebase that calls Crypt directly for
 * credentials. Everything else works with the plain array and never knows the
 * column is ciphertext. That means a developer cannot accidentally write a
 * plaintext credential by forgetting to encrypt — the model does it for them.
 *
 * Crypt::encrypt() uses AES-256-GCM with the APP_KEY. The output is a base64-
 * encoded JSON envelope that includes the IV and a MAC. Tampering with the
 * ciphertext is detected on decrypt and throws a DecryptException.
 */
class CredentialPayloadCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>|null  $value
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        if ($value === null) {
            return Crypt::encrypt([]);
        }

        return Crypt::encrypt(is_array($value) ? $value : (array) $value);
    }

    /**
     * @return array<string, mixed>
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [];
        }

        try {
            $decrypted = Crypt::decrypt($value);
            return is_array($decrypted) ? $decrypted : [];
        } catch (\Throwable) {
            // A DecryptException here means the APP_KEY changed or the row was
            // tampered with. Return empty rather than crashing — the caller
            // will see an unusable credential and can surface an error.
            return [];
        }
    }
}
