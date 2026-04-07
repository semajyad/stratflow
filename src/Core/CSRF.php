<?php

declare(strict_types=1);

namespace StratFlow\Core;

/**
 * CSRF Token Manager
 *
 * Generates and validates per-session CSRF tokens.
 * Uses a single token per session (not per-request) to avoid
 * breaking the back button.
 */
class CSRF
{
    private Session $session;

    public function __construct(Session $session)
    {
        $this->session = $session;
    }

    /**
     * Generate a new CSRF token, store it in the session, and return it.
     */
    public function generateToken(): string
    {
        $token = bin2hex(random_bytes(32));
        $this->session->set('_csrf_token', $token);
        return $token;
    }

    /**
     * Validate a submitted token against the session token.
     *
     * @param string $token The token from the form submission
     * @return bool True if valid
     */
    public function validateToken(string $token): bool
    {
        $stored = $this->session->get('_csrf_token');

        if ($stored === null) {
            return false;
        }

        return hash_equals($stored, $token);
    }

    /**
     * Return the current CSRF token, generating one if none exists.
     */
    public function getToken(): string
    {
        $token = $this->session->get('_csrf_token');

        if ($token === null) {
            $token = $this->generateToken();
        }

        return $token;
    }
}
