# Key Rotation Runbook

Rotate keys quarterly or immediately after a suspected compromise.

## Keys to Rotate

| Key | Env Var | Rotation Script | Impact |
|-----|---------|-----------------|--------|
| Encryption key(s) | `TOKEN_ENCRYPTION_KEYS` | `bin/rotate_secrets.php` | Integration tokens re-encrypted |
| Audit HMAC key | `AUDIT_HMAC_KEY` | Manual SQL (see below) | New rows use new key; old chain remains valid under old key |
| App key (recovery code HMAC) | `APP_KEY` | Manual (see below) | MFA recovery codes must be regenerated for all users |

## Rotate TOKEN_ENCRYPTION_KEYS

### Step 1 — Generate a new key
```bash
# Generate 32 bytes of random key material, base64-encoded:
php -r "echo base64_encode(random_bytes(32));"
```

### Step 2 — Add the new key id to the environment
In Railway dashboard (or your prod platform secrets manager):
```
# Format: kid1:base64key1,kid2:base64key2
# Add the new key as the LAST entry (encrypt always uses the last):
TOKEN_ENCRYPTION_KEYS=v1:OLD_BASE64_KEY,v2:NEW_BASE64_KEY
```

Redeploy the app. The app now decrypts with either key and encrypts with `v2`.

### Step 3 — Re-encrypt old rows
```bash
docker compose exec php php bin/rotate_secrets.php --dry-run
# Review output, then run for real:
docker compose exec php php bin/rotate_secrets.php
```

### Step 4 — Remove the old key
Once you've confirmed all rows are on `v2`:
```
TOKEN_ENCRYPTION_KEYS=v2:NEW_BASE64_KEY
```
Redeploy. The old key is now gone.

## Rotate AUDIT_HMAC_KEY

The audit hash chain is keyed. Rotating this key means the verification job will
start a new chain from the next event. Old events are still tamper-evident under
the old key but cannot be verified by the new key.

**Best practice**: keep the old key in a separate `AUDIT_HMAC_KEY_OLD` env var and
update the verification job to try both keys on rows before the rotation date.

```bash
# Generate new key:
php -r "echo bin2hex(random_bytes(32));"
# Update AUDIT_HMAC_KEY in environment and redeploy.
```

## Rotate APP_KEY (Recovery Code HMAC)

All stored MFA recovery codes are hashed with `APP_KEY`. Rotating it invalidates
all existing hashed codes — affected users will need to re-enrol MFA.

Steps:
1. Generate a new key: `php -r "echo bin2hex(random_bytes(32));"`
2. Update `APP_KEY` in environment.
3. Run a migration: `UPDATE users SET mfa_enabled = 0, mfa_secret = NULL, mfa_recovery_codes = NULL;`
4. Notify users they must re-enable MFA.
5. Redeploy.

This is a disruptive operation — only do it when truly necessary (confirmed `APP_KEY` compromise).
