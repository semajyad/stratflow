<?php

/**
 * Generic Rate Limiter
 *
 * Provides configurable rate limiting using the rate_limits database table.
 * Extends beyond login-only limiting to cover password resets, API calls,
 * file uploads, and admin actions as required by PCI-DSS and SOC 2.
 *
 * Usage:
 *   if (!RateLimiter::check($db, 'password_reset', $ip, 3, 3600)) {
 *       // Rate limited — reject request
 *   }
 *   RateLimiter::record($db, 'password_reset', $ip);
 */

declare(strict_types=1);

namespace StratFlow\Core;

class RateLimiter
{
    // ===========================
    // RATE LIMIT KEYS
    // ===========================

    public const PASSWORD_RESET = 'password_reset';
    public const API_GEMINI     = 'api_gemini';
    public const FILE_UPLOAD    = 'file_upload';
    public const ADMIN_ACTION   = 'admin_action';
// ===========================
    // CORE METHODS
    // ===========================

    /**
     * Check whether the identifier is within the allowed rate for the given key.
     *
     * @param Database $db          Database connection
     * @param string   $key         Rate limit category (e.g. 'password_reset')
     * @param string   $identifier  Unique identifier (IP address, user ID, etc.)
     * @param int      $maxAttempts Maximum allowed attempts within the window
     * @param int      $windowSecs  Time window in seconds
     * @return bool                 True if under the limit (request allowed)
     */
    public static function check(Database $db, string $key, string $identifier, int $maxAttempts, int $windowSecs): bool
    {
        try {
            if (!$db->tableExists('rate_limits')) {
                return true;
            }

            $since = date('Y-m-d H:i:s', time() - $windowSecs);
            $stmt = $db->query("SELECT COUNT(*) as cnt FROM rate_limits
                 WHERE rate_key = :key AND identifier = :id AND created_at > :since", ['key' => $key, 'id' => $identifier, 'since' => $since]);
            $row = $stmt->fetch();
            return ((int) ($row['cnt'] ?? 0)) < $maxAttempts;
        } catch (\Throwable $e) {
            \StratFlow\Services\Logger::warn('[RateLimiter] Check failed: ' . $e->getMessage());
            return true;
        // Fail open rather than locking out users
        }
    }

    /**
     * Record a rate-limited action.
     *
     * @param Database $db         Database connection
     * @param string   $key        Rate limit category
     * @param string   $identifier Unique identifier
     */
    public static function record(Database $db, string $key, string $identifier): void
    {
        try {
            if (!$db->tableExists('rate_limits')) {
                return;
            }

            $db->query("INSERT INTO rate_limits (rate_key, identifier) VALUES (:key, :id)", ['key' => $key, 'id' => $identifier]);
        } catch (\Throwable $e) {
            \StratFlow\Services\Logger::warn('[RateLimiter] Record failed: ' . $e->getMessage());
        }
    }

    /**
     * Remove expired rate limit entries older than 24 hours.
     *
     * Should be called periodically (e.g. via cron or after request handling).
     *
     * @param Database $db Database connection
     */
    public static function cleanup(Database $db): void
    {
        try {
            if (!$db->tableExists('rate_limits')) {
                return;
            }

            $db->query("DELETE FROM rate_limits WHERE created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
        } catch (\Throwable $e) {
            \StratFlow\Services\Logger::warn('[RateLimiter] Cleanup failed: ' . $e->getMessage());
        }
    }
}
