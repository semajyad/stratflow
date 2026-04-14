<?php

/**
 * PasswordToken Model
 *
 * Static data-access methods for the `password_tokens` table.
 * Manages secure, time-limited tokens for password set and reset flows.
 *
 * Columns: id, user_id, token, type, expires_at, used_at, created_at
 */

declare(strict_types=1);

namespace StratFlow\Models;

use StratFlow\Core\Database;

class PasswordToken
{
    private static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Create a new password token for a user.
     *
     * Invalidates any existing tokens for the user first, then generates
     * a cryptographically secure 64-character hex token with a 24-hour expiry.
     *
     * @param Database $db     Database instance
     * @param int      $userId User primary key
     * @param string   $type   Token type: 'set_password' or 'reset_password'
     * @return string          The generated token string
     */
    public static function create(Database $db, int $userId, string $type): string
    {
        // Invalidate any existing tokens for this user
        self::invalidateForUser($db, $userId);
        $token     = bin2hex(random_bytes(32));
        $tokenHash = self::hashToken($token);
        $expiresAt = date('Y-m-d H:i:s', time() + 86400);
// 24 hours

        $db->query("INSERT INTO password_tokens (user_id, token, type, expires_at)
             VALUES (:user_id, :token, :type, :expires_at)", [
                ':user_id'    => $userId,
                ':token'      => $tokenHash,
                ':type'       => $type,
                ':expires_at' => $expiresAt,
            ]);
        return $token;
    }

    /**
     * Find a valid (unexpired, unused) token by its string value.
     *
     * @param Database $db    Database instance
     * @param string   $token The token string to look up
     * @return array|null     Row as associative array, or null if not found/expired/used
     */
    public static function findByToken(Database $db, string $token): ?array
    {
        $hashedToken = self::hashToken($token);
        $stmt = $db->query("SELECT * FROM password_tokens
             WHERE (token = :token OR token = :legacy_token)
               AND expires_at > NOW()
               AND used_at IS NULL
             LIMIT 1", [
                ':token'        => $hashedToken,
                ':legacy_token' => $token,
            ]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * Mark a token as used by setting used_at to the current time.
     *
     * @param Database $db Database instance
     * @param int      $id Token primary key
     */
    public static function markUsed(Database $db, int $id): void
    {
        $db->query("UPDATE password_tokens SET used_at = NOW() WHERE id = :id", [':id' => $id]);
    }

    /**
     * Delete expired tokens from the database (cleanup).
     *
     * @param Database $db Database instance
     */
    public static function deleteExpired(Database $db): void
    {
        $db->query("DELETE FROM password_tokens WHERE expires_at < NOW()");
    }

    /**
     * Invalidate all existing tokens for a user by marking them as used.
     *
     * Called before creating a new token to ensure only one active token exists.
     *
     * @param Database $db     Database instance
     * @param int      $userId User primary key
     */
    public static function invalidateForUser(Database $db, int $userId): void
    {
        $db->query("UPDATE password_tokens SET used_at = NOW() WHERE user_id = :user_id AND used_at IS NULL", [':user_id' => $userId]);
    }
}
