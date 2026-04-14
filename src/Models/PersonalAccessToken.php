<?php

/**
 * PersonalAccessToken Model
 *
 * Manages personal access tokens (PATs) used by external tooling (e.g. the
 * stratflow-mcp MCP server) to authenticate against the JSON API.
 *
 * Tokens are NEVER stored in plaintext — only a sha256 hex digest is kept.
 * The raw token is returned once at creation and never retrievable again.
 *
 * Token format: sf_pat_ + 43 base64url chars (32 random bytes)
 * Token prefix: sf_pat_ + first 8 raw chars (for UI display only)
 *
 * All read methods filter by org_id per the multi-tenant invariant.
 */

declare(strict_types=1);

namespace StratFlow\Models;

use StratFlow\Core\Database;

class PersonalAccessToken
{
    public const DEFAULT_SCOPES = [
        'profile:read',
        'profile:write',
        'projects:read',
        'stories:read',
        'stories:write-status',
        'stories:assign',
    ];
// ===========================
    // TOKEN GENERATION
    // ===========================

    /**
     * Generate a fresh, unpersisted token.
     *
     * @return array{raw: string, prefix: string}
     *   - raw:    The full token string (sf_pat_...) — show once, never store
     *   - prefix: First 15 chars (sf_pat_ + first 8 raw random chars) — safe to store
     */
    public static function generate(): array
    {
        $random = random_bytes(32);
        $encoded = rtrim(strtr(base64_encode($random), '+/', '-_'), '=');
        $raw = 'sf_pat_' . $encoded;
        return [
            'raw'    => $raw,
            'prefix' => substr($raw, 0, 15),
        ];
    }

    /**
     * Hash a raw token for storage or lookup.
     *
     * @param string $raw Raw token string
     * @return string     sha256 hex digest
     */
    public static function hash(string $raw): string
    {
        return hash('sha256', $raw);
    }

    // ===========================
    // CREATE
    // ===========================

    /**
     * Persist a new personal access token.
     *
     * Caller must use generate() first to obtain raw + prefix.
     * Returns the persisted row (without raw token) plus the raw token.
     *
     * @param Database               $db
     * @param int                    $userId
     * @param int                    $orgId
     * @param string                 $name        Human-readable label
     * @param string                 $raw         Raw token from generate()
     * @param string                 $prefix      Prefix from generate()
     * @param array|null             $scopes      Allowed API scopes
     * @param \DateTimeImmutable|null $expiresAt   Null = no expiry
     * @return array  Row data including 'raw' key (not in DB)
     */
    public static function create(Database $db, int $userId, int $orgId, string $name, string $raw, string $prefix, ?array $scopes = null, ?\DateTimeImmutable $expiresAt = null): array
    {
        $tokenHash = self::hash($raw);
        $expiresAtStr = $expiresAt?->format('Y-m-d H:i:s');
        $scopesJson = json_encode($scopes ?? self::DEFAULT_SCOPES, JSON_UNESCAPED_SLASHES);
        $db->query("INSERT INTO personal_access_tokens
                (user_id, org_id, name, token_hash, token_prefix, scopes, expires_at)
             VALUES
                (:user_id, :org_id, :name, :token_hash, :token_prefix, :scopes, :expires_at)", [
                ':user_id'      => $userId,
                ':org_id'       => $orgId,
                ':name'         => $name,
                ':token_hash'   => $tokenHash,
                ':token_prefix' => $prefix,
                ':scopes'       => $scopesJson,
                ':expires_at'   => $expiresAtStr,
            ]);
        $id = $db->lastInsertId();
        return [
            'id'           => $id,
            'user_id'      => $userId,
            'org_id'       => $orgId,
            'name'         => $name,
            'token_prefix' => $prefix,
            'scopes'       => json_decode($scopesJson, true),
            'expires_at'   => $expiresAtStr,
            'created_at'   => date('Y-m-d H:i:s'),
            'raw'          => $raw, // Caller shows this once — not persisted
        ];
    }

    // ===========================
    // READ
    // ===========================

    /**
     * Find a valid (non-revoked, non-expired) token by its hash.
     *
     * Used exclusively by ApiAuthMiddleware on every API request.
     *
     * @param Database $db
     * @param string   $hash  sha256 hex of the raw token
     * @return array|null     Token row or null if invalid
     */
    public static function findByHash(Database $db, string $hash): ?array
    {
        $row = $db->query("SELECT id, user_id, org_id, name, token_prefix, scopes, last_used_at, expires_at, created_at
             FROM personal_access_tokens
             WHERE token_hash = :hash
               AND revoked_at IS NULL
               AND (expires_at IS NULL OR expires_at > NOW())", [':hash' => $hash])->fetch();
        return $row ?: null;
    }

    /**
     * List all active (non-revoked) tokens for a user in an org.
     *
     * @param Database $db
     * @param int      $userId
     * @param int      $orgId
     * @return array
     */
    public static function listForUser(Database $db, int $userId, int $orgId): array
    {
        return $db->query("SELECT id, name, token_prefix, scopes, last_used_at, expires_at, created_at
              FROM personal_access_tokens
              WHERE user_id = :user_id
                AND org_id  = :org_id
               AND revoked_at IS NULL
             ORDER BY created_at DESC", [':user_id' => $userId, ':org_id' => $orgId])->fetchAll() ?: [];
    }

    // ===========================
    // UPDATE
    // ===========================

    /**
     * Revoke a token owned by a specific user in a specific org.
     *
     * The org_id guard prevents cross-user/cross-org revocation.
     *
     * @param Database $db
     * @param int      $id
     * @param int      $userId
     * @param int      $orgId
     * @return bool    True if a row was actually updated
     */
    public static function revoke(Database $db, int $id, int $userId, int $orgId): bool
    {
        $stmt = $db->query("UPDATE personal_access_tokens
             SET revoked_at = NOW()
             WHERE id      = :id
               AND user_id = :user_id
               AND org_id  = :org_id
               AND revoked_at IS NULL", [':id' => $id, ':user_id' => $userId, ':org_id' => $orgId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Update last_used_at and last_used_ip — called after every authenticated
     * API request.  Fire-and-forget; errors are intentionally swallowed.
     *
     * @param Database $db
     * @param int      $id
     * @param string   $ip  Client IP address
     */
    public static function touchLastUsed(Database $db, int $id, string $ip): void
    {
        try {
            $db->query("UPDATE personal_access_tokens
                 SET last_used_at = NOW(), last_used_ip = :ip
                 WHERE id = :id", [':ip' => $ip, ':id' => $id]);
        } catch (\Throwable) {
        // Non-critical — do not surface errors for audit touches
        }
    }
}
