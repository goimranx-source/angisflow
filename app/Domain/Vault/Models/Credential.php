<?php

declare(strict_types=1);

namespace App\Domain\Vault\Models;

use App\Domain\Shared\Concerns\HasPublicId;
use App\Domain\Tenancy\Concerns\BelongsToAccount;
use App\Domain\Vault\Casts\CredentialPayloadCast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One stored credential.
 *
 * The payload column is always ciphertext on disk. The cast decrypts it
 * transparently when the attribute is read, so callers work with a plain array
 * and never touch Crypt directly.
 *
 * The ref column is the same ULID as public_id. It is the value stored in
 * courier_connections.credential_ref and inbox_channels.credential_ref — a
 * pointer that can be resolved without decrypting anything.
 */
class Credential extends Model
{
    use BelongsToAccount, HasPublicId;

    public const KIND_API_KEY     = 'api_key';
    public const KIND_OAUTH_TOKEN = 'oauth_token';
    public const KIND_BASIC_AUTH  = 'basic_auth';
    public const KIND_GENERIC     = 'generic';

    protected $fillable = [
        'public_id', 'ref', 'account_id', 'label', 'kind',
        'payload', 'last_rotated_at', 'last_used_at', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'payload'         => CredentialPayloadCast::class,
            'last_rotated_at' => 'datetime',
            'last_used_at'    => 'datetime',
            'is_active'       => 'boolean',
        ];
    }

    // The payload is never sent in list responses — only via Vault::reveal().
    protected $hidden = ['payload'];

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    /** Safe summary — no payload. */
    public function toPayload(): array
    {
        return [
            'id'              => $this->public_id,
            'ref'             => $this->ref,
            'label'           => $this->label,
            'kind'            => $this->kind,
            'is_active'       => $this->is_active,
            'last_rotated_at' => $this->last_rotated_at?->toIso8601String(),
            'last_used_at'    => $this->last_used_at?->toIso8601String(),
            'created_at'      => $this->created_at?->toIso8601String(),
        ];
    }
}
