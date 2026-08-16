# Support Token System

## Purpose
When subscribers need help from support team or AI assistance, they need a secure way to identify their account without exposing sensitive information.

## How It Works

### Token Generation
Support token is generated using:
```
base64_encode(account.public_id + '|' + account.id)
```

### Example
For account with:
- `public_id`: `01renhl7pqpc7xbs5vm7wz9v17v`
- `id`: `1`

Token: `MDFrenhlN3BxcGM3eGJzNXZtN3d6OXYxN3Z8MQ==`

### Why This Approach?

1. **Non-sensitive**: Token contains public_id which is already exposed in URLs
2. **Decodable**: Support team can decode to find account quickly
3. **Verifiable**: Can validate by checking if account exists
4. **Secure**: Does not contain passwords, API keys, or payment info

## Where to Get Token

### For Users
1. Go to Settings → Account
2. Find "Support Token" section
3. Copy token for support requests

### For Developers
Run command:
```bash
php artisan dev:upgrade-account {email}
```

Output shows the support token.

## When to Use

### Subscribers Should Provide Token When:
- Requesting technical support via email/chat
- Reporting bugs specific to their account
- Asking AI assistant about their data/settings
- Requesting data exports or account changes
- Billing inquiries

### What Token Enables:
- Support team can quickly locate account
- AI can provide account-specific assistance
- Faster resolution without asking security questions
- Audit trail of support interactions

## Security Notes

- ✅ Token contains non-sensitive public identifier
- ✅ Safe to share with official support channels
- ✅ Does not grant access to account (authentication still required)
- ❌ Do NOT share on public forums or social media
- ❌ Do NOT use as authentication mechanism
- ❌ Token alone cannot modify account data

## Implementation

### Backend (Laravel)
```php
// Decode token
$decoded = base64_decode($token);
[$publicId, $id] = explode('|', $decoded);

// Find account
$account = Account::where('public_id', $publicId)
    ->where('id', $id)
    ->first();
```

### Frontend (Settings Page)
Show support token in Account Settings:
```tsx
<div className="card p-6">
  <h3>Support Token</h3>
  <p>Provide this token when contacting support</p>
  <code>{supportToken}</code>
  <button onClick={copyToken}>Copy</button>
</div>
```

## Alternative: Support Links

For automated support, encode token in URL:
```
https://angisflow.com/support?token=MDFrenhlN3BxcGM3eGJzNXZtN3d6OXYxN3Z8MQ==
```

Support page auto-loads account context.

---

**Created**: 2026-08-13
**Status**: Implemented in upgrade command, needs frontend UI
