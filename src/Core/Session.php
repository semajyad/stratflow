<?php

declare(strict_types=1);

namespace StratFlow\Core;

/**
 * Secure Session Manager
 *
 * Wraps PHP sessions with secure cookie parameters and provides
 * typed accessors plus flash message support.
 */
class Session
{
    public function __construct()
    {
        if (session_status() === PHP_SESSION_NONE) {
            $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'domain' => '',
                'secure' => $isSecure,
                'httponly' => true,
                'samesite' => 'Strict',
            ]);

            session_start();
        }
    }

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
