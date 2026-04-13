-- Migration 039: TOTP MFA columns on users table
-- mfa_secret: Base32-encoded TOTP secret (stored via SecretManager if TOKEN_ENCRYPTION_KEYS set)
-- mfa_enabled: whether TOTP is active for this user
-- mfa_recovery_codes: JSON array of HMAC-SHA256'd recovery code hashes

ALTER TABLE users
    ADD COLUMN mfa_secret         TEXT         NULL AFTER password_hash,
    ADD COLUMN mfa_enabled        TINYINT(1)   NOT NULL DEFAULT 0 AFTER mfa_secret,
    ADD COLUMN mfa_recovery_codes JSON         NULL AFTER mfa_enabled;
