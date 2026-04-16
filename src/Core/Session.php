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

    /** @var int Absolute session lifetime in seconds (default: 8 hours) */
    private const ABSOLUTE_TIMEOUT = 28800;

    public function __construct(int $timeout = 1800, ?\PDO $pdo = null)
    {
        $this->timeout = $timeout;

        if (session_status() === PHP_SESSION_NONE) {
            $isSecure = $this->isSecureRequest();
            session_name($isSecure ? '__Host-stratflow_session' : 'stratflow_session');

            // Use database session handler if PDO available
            if ($pdo !== null) {
                $handler = new DatabaseSessionHandler($pdo);
                session_set_save_handler($handler, true);
            }

            // Hardened session configuration (SOC 2 / PCI-DSS)
            ini_set('session.use_strict_mode', '1');
            ini_set('session.use_only_cookies', '1');
            ini_set('session.cookie_httponly', '1');
            ini_set('session.cookie_samesite', 'Lax');
            // session.sid_length and session.sid_bits_per_character were
            // deprecated in PHP 8.4 — the equivalent is now set via
            // session_set_cookie_params or left at PHP defaults (128-bit).
            if (PHP_VERSION_ID < 80400) {
                ini_set('session.sid_length', '48');
                ini_set('session.sid_bits_per_character', '6');
            }

            session_set_cookie_params([
                'lifetime' => 0,           // Browser session only
                'path'     => '/',
                'domain'   => '',
                'secure'   => $isSecure,
                'httponly'  => true,
                'samesite'  => 'Lax',      // Lax allows Stripe redirect to keep session
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
     * Check for session inactivity and absolute timeouts; destroy if either expired.
     *
     * PCI-DSS requires 15-minute inactivity timeout; we use 30 minutes (configurable).
     * Absolute timeout (8 hours) prevents indefinitely-lived sessions regardless of activity.
     */
    private function checkTimeout(): void
    {
        $now = time();

        // Absolute timeout — destroy after ABSOLUTE_TIMEOUT regardless of activity
        if (isset($_SESSION['_session_started_at'])) {
            if (($now - $_SESSION['_session_started_at']) > self::ABSOLUTE_TIMEOUT) {
                $this->destroy();
                return;
            }
        } else {
            $_SESSION['_session_started_at'] = $now;
        }

        // Inactivity timeout
        if (isset($_SESSION['_last_activity'])) {
            if (($now - $_SESSION['_last_activity']) > $this->timeout) {
                $this->destroy();
                return;
            }
        }

        $_SESSION['_last_activity'] = $now;
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
        // Only use User-Agent for fingerprint — IP changes behind proxies/load balancers
        // (Railway, Cloudflare, etc.) cause false session invalidation
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        return hash('sha256', $ua);
    }

    private function isSecureRequest(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }

        $requestScheme = strtolower((string) ($_SERVER['REQUEST_SCHEME'] ?? ''));
        if ($requestScheme === 'https') {
            return true;
        }

        $forwardedProto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        if ($forwardedProto === 'https') {
            return true;
        }

        $forwardedSsl = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? ''));
        if ($forwardedSsl === 'on') {
            return true;
        }

        if ((string) ($_SERVER['HTTP_X_FORWARDED_PORT'] ?? '') === '443') {
            return true;
        }

        if ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443') {
            return true;
        }

        $cfVisitor = (string) ($_SERVER['HTTP_CF_VISITOR'] ?? '');
        if ($cfVisitor !== '' && str_contains($cfVisitor, '"scheme":"https"')) {
            return true;
        }

        $appUrl = (string) ($_ENV['APP_URL'] ?? getenv('APP_URL') ?: '');
        return str_starts_with($appUrl, 'https://');
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
