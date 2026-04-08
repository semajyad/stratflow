<?php

declare(strict_types=1);

namespace StratFlow\Core;

/**
 * Secure Session Manager
 *
 * Wraps PHP sessions with hardened security parameters:
 * - Inactivity timeout (30 minutes, configurable) for HIPAA/PCI-DSS
 * - Session fingerprinting (User-Agent + partial IP) to prevent hijacking
 * - Periodic session ID regeneration (every 15 minutes)
 * - Strict cookie parameters (httponly, secure, samesite)
 * - Flash message support
 */
class Session
{
    /** @var int Session inactivity timeout in seconds (default: 30 minutes) */
    private int $timeout;

    /** @var int Session ID regeneration interval in seconds (default: 15 minutes) */
    private const REGEN_INTERVAL = 900;

    public function __construct(int $timeout = 1800)
    {
        $this->timeout = $timeout;

        if (session_status() === PHP_SESSION_NONE) {
            $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

            // Hardened session configuration (SOC 2 / PCI-DSS)
            ini_set('session.use_strict_mode', '1');
            ini_set('session.use_only_cookies', '1');
            // sid_length and sid_bits_per_character deprecated in PHP 8.4
            // Defaults (48 length, 6 bits/char) are already secure

            session_set_cookie_params([
                'lifetime' => 0,           // Browser session only
                'path'     => '/',
                'domain'   => '',
                'secure'   => $isSecure,
                'httponly'  => true,
                'samesite'  => 'Strict',
            ]);

            session_start();
        }

        // Check inactivity timeout
        $this->checkTimeout();

        // Validate session fingerprint
        $this->validateFingerprint();

        // Periodically regenerate session ID
        $this->periodicRegenerate();
    }

    // ===========================
    // SESSION SECURITY CHECKS
    // ===========================

    /**
     * Check for session inactivity timeout and destroy if expired.
     *
     * PCI-DSS requires 15-minute timeout; we use 30 minutes (configurable)
     * which covers both HIPAA and general enterprise requirements.
     */
    private function checkTimeout(): void
    {
        if (isset($_SESSION['_last_activity'])) {
            $elapsed = time() - $_SESSION['_last_activity'];
            if ($elapsed > $this->timeout) {
                $this->destroy();
                return;
            }
        }

        $_SESSION['_last_activity'] = time();
    }

    /**
     * Validate session fingerprint to prevent session hijacking.
     *
     * Creates a hash of the User-Agent and first two octets of the IP address.
     * If the fingerprint changes mid-session, the session is destroyed.
     */
    private function validateFingerprint(): void
    {
        $fingerprint = $this->generateFingerprint();

        if (isset($_SESSION['_fingerprint'])) {
            if ($_SESSION['_fingerprint'] !== $fingerprint) {
                $this->destroy();
                return;
            }
        } else {
            $_SESSION['_fingerprint'] = $fingerprint;
        }
    }

    /**
     * Generate a session fingerprint from User-Agent and partial IP.
     *
     * Uses only the first two octets of IPv4 (or prefix for IPv6) to allow
     * for legitimate IP changes within the same network.
     *
     * @return string SHA-256 fingerprint hash
     */
    private function generateFingerprint(): string
    {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        // Extract first two octets for IPv4, first 4 groups for IPv6
        if (str_contains($ip, '.')) {
            $parts = explode('.', $ip);
            $partialIp = ($parts[0] ?? '0') . '.' . ($parts[1] ?? '0');
        } else {
            $parts = explode(':', $ip);
            $partialIp = implode(':', array_slice($parts, 0, 4));
        }

        return hash('sha256', $ua . '|' . $partialIp);
    }

    /**
     * Regenerate the session ID periodically to limit the window of
     * a compromised session ID.
     */
    private function periodicRegenerate(): void
    {
        $now = time();

        if (!isset($_SESSION['_created_at'])) {
            $_SESSION['_created_at'] = $now;
        }

        if (($now - $_SESSION['_created_at']) > self::REGEN_INTERVAL) {
            session_regenerate_id(true);
            $_SESSION['_created_at'] = $now;
        }
    }

    // ===========================
    // PUBLIC API
    // ===========================

    /**
     * Get a session value by key.
     *
     * @param string $key     Session key
     * @param mixed  $default Fallback if key is absent
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    /**
     * Set a session value.
     */
    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    /**
     * Check whether a session key exists.
     */
    public function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    /**
     * Remove a session key.
     */
    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /**
     * Destroy the entire session (logout).
     */
    public function destroy(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_destroy();
    }

    /**
     * Store a flash message (available for one retrieval only).
     */
    public function flash(string $key, string $message): void
    {
        $_SESSION['_flash'][$key] = $message;
    }

    /**
     * Retrieve and delete a flash message.
     *
     * @return string|null The message, or null if none set
     */
    public function getFlash(string $key): ?string
    {
        $message = $_SESSION['_flash'][$key] ?? null;

        if ($message !== null) {
            unset($_SESSION['_flash'][$key]);
        }

        return $message;
    }
}
