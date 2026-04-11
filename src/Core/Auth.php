<?php

declare(strict_types=1);

namespace StratFlow\Core;

/**
 * Authentication Manager
 *
 * Handles login/logout, session-based user state, and IP-based
 * rate limiting on failed login attempts (5 per 15 minutes).
 */
class Auth
{
    private Session $session;
    private Database $db;

    /**
     * In-memory principal set by ApiAuthMiddleware for token-authenticated
     * requests.  When non-null, check/user/orgId prefer this over the session
     * so that API controllers work identically to session-based controllers
     * without touching the session at all.
     *
     * @var array|null
     */
    private ?array $apiPrincipal = null;

    public function __construct(Session $session, Database $db)
    {
        $this->session = $session;
        $this->db = $db;
    }

    /**
     * Set an in-memory principal for the current request.
     *
     * Called by ApiAuthMiddleware after validating a PAT.  Does NOT touch
     * the session, does NOT regenerate the session ID, sets no cookies.
     *
     * @param array $user User row with at minimum: id, org_id, full_name, email, role
     */
    public function loginAsPrincipal(array $user): void
    {
        $this->apiPrincipal = [
            'id'                   => $user['id'],
            'org_id'               => $user['org_id'],
            'name'                 => $user['full_name'] ?? $user['name'] ?? '',
            'email'                => $user['email'],
            'role'                 => $user['role'],
            'account_type'         => $user['account_type'] ?? null,
            'has_billing_access'   => (bool) ($user['has_billing_access']   ?? false),
            'has_executive_access' => (bool) ($user['has_executive_access'] ?? false),
            'is_project_admin'     => (bool) ($user['is_project_admin']     ?? false),
        ];
    }

    /**
     * Attempt to authenticate a user by email and password.
     *
     * Does NOT check rate limiting -- caller should check isRateLimited() first
     * and show an appropriate message.
     *
     * @return bool True if credentials are valid and user is now logged in
     */
    public function attempt(string $email, string $password): bool
    {
        $stmt = $this->db->query(
            'SELECT * FROM users WHERE email = ? LIMIT 1',
            [$email]
        );

        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            return false;
        }

        $this->login($user);
        return true;
    }

    /**
     * Store user data in the session (log them in).
     *
     * @param array $user Row from the users table
     */
    public function login(array $user): void
    {
        // Regenerate session ID to prevent session fixation attacks
        session_regenerate_id(true);

        $this->session->set('user', [
            'id' => $user['id'],
            'org_id' => $user['org_id'],
            'name' => $user['full_name'],
            'email' => $user['email'],
            'role' => $user['role'],
            'account_type' => $user['account_type'] ?? null,
            'has_billing_access'   => (bool) ($user['has_billing_access']   ?? false),
            'has_executive_access' => (bool) ($user['has_executive_access'] ?? false),
            'is_project_admin'     => (bool) ($user['is_project_admin']     ?? false),
        ]);
    }

    /**
     * Log the user out by destroying the session.
     */
    public function logout(): void
    {
        $this->session->destroy();
    }

    /**
     * Check whether a user is currently authenticated.
     *
     * Returns true when an in-memory API principal is set (PAT auth) OR
     * when a valid session exists (browser auth).
     */
    public function check(): bool
    {
        if ($this->apiPrincipal !== null) {
            return true;
        }
        return $this->session->has('user');
    }

    /**
     * Return the authenticated user's data, or null.
     *
     * Prefers the in-memory API principal when set, falling back to the session.
     *
     * @return array|null User array with id, org_id, name, email, role
     */
    public function user(): ?array
    {
        if ($this->apiPrincipal !== null) {
            return $this->apiPrincipal;
        }
        return $this->session->get('user');
    }

    /**
     * Return the authenticated user's organisation ID, or null.
     */
    public function orgId(): ?int
    {
        $user = $this->user();
        return $user ? (int) $user['org_id'] : null;
    }

    /**
     * Check if an IP address is rate-limited (>= 5 failed attempts in 15 min).
     */
    public function isRateLimited(string $ip): bool
    {
        $stmt = $this->db->query(
            'SELECT COUNT(*) as cnt FROM login_attempts WHERE ip_address = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)',
            [$ip]
        );

        $row = $stmt->fetch();
        return ($row['cnt'] ?? 0) >= 5;
    }

    /**
     * Record a failed login attempt for rate-limiting purposes.
     */
    public function recordFailedAttempt(string $ip): void
    {
        $this->db->query(
            'INSERT INTO login_attempts (ip_address) VALUES (?)',
            [$ip]
        );
    }
}
