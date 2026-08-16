<?php

/**
 * Task 36 — Credential Vault verification
 *
 * Run:
 *   php artisan tinker --execute="require 'D:\\Povaly Group\\Applications\\angisflow\\angisflow\\verification\\verify_task36.php'"
 */

use App\Domain\Tenancy\Models\Account;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Vault\Models\Credential;
use App\Domain\Vault\Vault;

$account = Account::withoutGlobalScopes()->find(1);
$t = app(TenantContext::class);
$t->setAccount($account);

$try = function (string $label, callable $fn) {
    try {
        $out = $fn();
        echo "  OK      {$label}" . ($out ? " -> {$out}" : '') . PHP_EOL;
    } catch (Throwable $e) {
        echo "  REFUSED {$label} -> " . $e->getMessage() . PHP_EOL;
    }
};

$vault = app(Vault::class);

echo PHP_EOL . '── Credential Vault ─────────────────────────────────────────────' . PHP_EOL;

// 1. Store an API key credential
$credential = null;
$try('Store api_key credential', function () use ($vault, &$credential) {
    $credential = $vault->store('Test Pathao Key', 'api_key', [
        'api_key'    => 'pk_test_abc123',
        'api_secret' => 'sk_test_xyz789',
    ]);
    return "ref={$credential->ref}, label={$credential->label}";
});

// 2. Payload is not in the model's visible attributes
$try('Payload hidden from toPayload()', function () use (&$credential) {
    $payload = $credential->toPayload();
    if (array_key_exists('payload', $payload)) {
        throw new RuntimeException('payload key is present in toPayload() — it must not be');
    }
    return 'payload not exposed';
});

// 3. Payload is ciphertext in the database
$try('Payload is ciphertext on disk', function () use (&$credential) {
    $raw = \Illuminate\Support\Facades\DB::table('credentials')
        ->where('id', $credential->id)
        ->value('payload');
    if (str_contains($raw, 'pk_test_abc123')) {
        throw new RuntimeException('Plaintext key found in database column — encryption failed');
    }
    return 'ciphertext confirmed, length=' . strlen($raw);
});

// 4. Retrieve decrypts correctly
$try('Retrieve decrypts payload', function () use ($vault, &$credential) {
    $payload = $vault->retrieve($credential->ref);
    if (($payload['api_key'] ?? null) !== 'pk_test_abc123') {
        throw new RuntimeException('Decrypted payload does not match stored value');
    }
    return 'api_key=' . $payload['api_key'];
});

// 5. last_used_at is stamped on retrieve
$try('last_used_at stamped on retrieve', function () use (&$credential) {
    $fresh = Credential::withoutGlobalScopes()->find($credential->id);
    if ($fresh->last_used_at === null) {
        throw new RuntimeException('last_used_at was not stamped');
    }
    return 'stamped at ' . $fresh->last_used_at->toIso8601String();
});

// 6. Rotate replaces payload, ref unchanged
$try('Rotate replaces payload, ref unchanged', function () use ($vault, &$credential) {
    $oldRef = $credential->ref;
    $updated = $vault->rotate($credential->ref, [
        'api_key'    => 'pk_test_NEW',
        'api_secret' => 'sk_test_NEW',
    ]);
    if ($updated->ref !== $oldRef) {
        throw new RuntimeException('ref changed after rotation — integrations would break');
    }
    $payload = $vault->retrieve($updated->ref);
    if (($payload['api_key'] ?? null) !== 'pk_test_NEW') {
        throw new RuntimeException('New payload not retrievable after rotation');
    }
    return "ref unchanged={$oldRef}, new key confirmed";
});

// 7. last_rotated_at is updated
$try('last_rotated_at updated after rotate', function () use (&$credential) {
    $fresh = Credential::withoutGlobalScopes()->find($credential->id);
    if ($fresh->last_rotated_at === null) {
        throw new RuntimeException('last_rotated_at not set');
    }
    return $fresh->last_rotated_at->toIso8601String();
});

// 8. Invalid kind is refused
$try('Invalid kind is refused', function () use ($vault) {
    $vault->store('Bad kind', 'ssh_key', ['key' => 'abc']);
    throw new RuntimeException('Should have thrown');
});

// 9. Revoke disables and clears payload
$try('Revoke disables credential', function () use ($vault, &$credential) {
    $vault->revoke($credential->ref);
    $fresh = Credential::withoutGlobalScopes()->find($credential->id);
    if ($fresh->is_active) {
        throw new RuntimeException('Credential still active after revoke');
    }
    return 'is_active=false';
});

// 10. Retrieve on revoked credential throws
$try('Retrieve on revoked credential is refused', function () use ($vault, &$credential) {
    $vault->retrieve($credential->ref);
    throw new RuntimeException('Should have thrown');
});

// 11. Modules::BUILT includes /credentials
$try('Modules::BUILT includes /credentials', function () {
    $built = \App\Support\Modules::BUILT;
    if (!in_array('/credentials', $built, true)) {
        throw new RuntimeException('/credentials not in BUILT');
    }
    return implode(', ', $built);
});

// ── Cleanup ───────────────────────────────────────────────────────────────────
Credential::withoutGlobalScopes()->where('label', 'Test Pathao Key')->forceDelete();
echo PHP_EOL . '  (test credential deleted)' . PHP_EOL . PHP_EOL;
