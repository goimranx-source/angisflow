# Task 36: Credential Vault — Completion Report

**Status:** ✅ COMPLETE  
**Date:** 2026-08-13

---

## What was built

### 1. Migration (`2026_08_14_000030_create_credentials_table.php`)

`credentials` table with:
- `ref` — ULID pointer stored in `courier_connections.credential_ref` and `inbox_channels.credential_ref`
- `payload` — TEXT column, always ciphertext (AES-256-GCM via `APP_KEY`)
- `kind` — `api_key | oauth_token | basic_auth | generic`
- `last_rotated_at`, `last_used_at` — operational visibility
- `is_active` — revocation flag

### 2. `CredentialPayloadCast` (`app/Domain/Vault/Casts/`)

Eloquent cast that calls `Crypt::encrypt()` on write and `Crypt::decrypt()` on read. The column is always ciphertext. A developer cannot accidentally write plaintext by forgetting to encrypt — the model does it for them. A tampered or undecryptable payload returns `[]` rather than crashing.

### 3. `Credential` model (`app/Domain/Vault/Models/`)

`BelongsToAccount` + `HasPublicId`. `payload` is hidden from all serialisation — it never appears in `toPayload()`, `toArray()`, or JSON responses. The only way to read it is `Vault::retrieve()`.

### 4. `Vault` service (`app/Domain/Vault/Vault.php`)

Five methods:
- `store(label, kind, payload)` — creates a credential, returns the model with its ref
- `retrieve(ref)` — decrypts and returns the payload; stamps `last_used_at`; throws on revoked
- `rotate(ref, newPayload)` — replaces payload in place; ref unchanged; stamps `last_rotated_at`
- `revoke(ref)` — sets `is_active = false`, clears payload; ref row stays for detection
- `list()` — all credentials for the account, no payloads

### 5. `VaultEndpoint` (`app/Http/Api/V1/VaultEndpoint.php`)

Six routes:
| Route | Guard |
|---|---|
| `GET /credentials` | `settings.edit` |
| `POST /credentials` | `settings.edit` |
| `GET /credentials/{id}` | `settings.edit` |
| `PATCH /credentials/{id}/rotate` | `settings.edit` |
| `DELETE /credentials/{id}` | `settings.edit` |
| `GET /credentials/{id}/reveal` | `settings.edit` + `password.confirm` |

The reveal route requires a password typed in the last few minutes. A stolen session cookie is not enough to extract secrets.

### 6. Frontend page (`resources/js/pages/Credentials.tsx`)

- List with kind badge (API Key / OAuth Token / Basic Auth / Generic), last-rotated date, last-used date
- Add form with JSON payload editor
- Reveal button opens a modal that fetches the decrypted payload (will redirect to password confirmation if needed)
- Revoke button with confirmation dialog
- Revoked credentials shown greyed out with a "Revoked" badge

### 7. `Modules::BUILT` — `/credentials` added

### 8. Router — `credentials` page wired with lazy chunk and prefetch map entry

---

## Key decisions

**Why application-layer encryption rather than relying on disk encryption**  
Disk encryption protects against a stolen drive. It does not protect against SQL injection, a misconfigured backup in S3, or a compromised read replica. `Crypt::encrypt()` with `APP_KEY` means the ciphertext is useless without the key, which is not in the database. The trade is that the payload cannot be queried or indexed — which is fine, because the vault is a lookup by ref, not a search.

**Why ref and public_id are the same ULID**  
`courier_connections.credential_ref` and `inbox_channels.credential_ref` store the ref. Making it the same value as `public_id` means there is one identifier to remember, and the URL (`/credentials/{public_id}`) and the FK pointer (`credential_ref`) are the same string. They are named separately in the schema to make the intent clear at the call site.

**Why the boot-order bug happened and how it was fixed**  
`bootCredential()` tried to copy `public_id` into `ref` inside a `creating` listener. But `bootHasPublicId()` also runs in a `creating` listener, and PHP calls boot methods alphabetically — `bootCredential` runs before `bootHasPublicId`, so `public_id` was still null when `ref` was being set. Fixed by generating the ULID explicitly in `Vault::store()` and passing both `public_id` and `ref` in the `create()` call, so both arrive in the INSERT together with no listener dependency.

**Why reveal requires password.confirm**  
`settings.edit` proves the session is authenticated and authorised. `password.confirm` proves the person at the keyboard right now is the account owner — not just someone who found an unlocked screen. For a screen that decrypts stored API keys, that second proof is the whole point.

---

## Verified output

```
── Credential Vault ─────────────────────────────────────────────
  OK      Store api_key credential -> ref=01kzxc9h..., label=Test Pathao Key
  OK      Payload hidden from toPayload() -> payload not exposed
  OK      Payload is ciphertext on disk -> ciphertext confirmed, length=340
  OK      Retrieve decrypts payload -> api_key=pk_test_abc123
  OK      last_used_at stamped on retrieve -> stamped at 2026-08-13T10:57:07+00:00
  OK      Rotate replaces payload, ref unchanged -> ref unchanged, new key confirmed
  OK      last_rotated_at updated after rotate -> 2026-08-13T10:57:07+00:00
  REFUSED Invalid kind is refused -> Unknown credential kind "ssh_key". Allowed: ...
  OK      Revoke disables credential -> is_active=false
  REFUSED Retrieve on revoked credential is refused -> The credential "Test Pathao Key" has been revoked...
  OK      Modules::BUILT includes /credentials -> /dashboard, /settings, /reports, /accounts, /transactions, /journal, /credentials
```

11/11 checks pass. One bug found and fixed (boot order). Test data deleted.

---

## What is not built (honest accounting)

- **Rotation history** — `rotate()` replaces the payload in place. A full audit trail of every previous value would need a `credential_rotations` table. Not built; the current design is correct for the common case.
- **Connecting credentials to integrations** — `courier_connections.credential_ref` and `inbox_channels.credential_ref` still need UI to pick a credential from the vault when creating/editing a connection. The vault is ready; the connection forms are not yet built.
- **OAuth flow** — storing an OAuth token is supported (`kind = oauth_token`). The actual OAuth redirect/callback flow for platforms like Meta is not built here.

---

## Files created / modified

**New:**
- `database/migrations/2026_08_14_000030_create_credentials_table.php`
- `app/Domain/Vault/Casts/CredentialPayloadCast.php`
- `app/Domain/Vault/Models/Credential.php`
- `app/Domain/Vault/Vault.php`
- `app/Http/Api/V1/VaultEndpoint.php`
- `resources/js/pages/Credentials.tsx`
- `verification/verify_task36.php`

**Modified:**
- `routes/api.php` — VaultEndpoint import + 6 routes
- `app/Support/Modules.php` — `/credentials` in BUILT, sidebar entry added
- `resources/js/router.tsx` — credentials page + prefetch map
