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
     * Does NOT check rate limiting -- caller should check isRateLimited() first.
     * Returns 'ok' on full success, 'mfa_required' when TOTP challenge is needed,
     * or false on bad credentials.
     *
     * @return 'ok'|'mfa_required'|false
     */
    public function attempt(string $email, string $password): string|false
    {
        $stmt = $this->db->query(
            'SELECT * FROM users WHERE email = ? AND is_active = 1 LIMIT 1',
            [$email]
        );

        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            return false;
        }

        // MFA gate — store pending user id in session, caller must redirect to /login/mfa
        if (!empty($user['mfa_enabled'])) {
            $_SESSION['_mfa_pending_user_id'] = (int) $user['id'];
            return 'mfa_required';
        }

        $this->login($user);
        return 'ok';
    }

    /**
     * Complete MFA verification. Fetches the pending user from session, verifies
     * the TOTP code (or a recovery code), then calls login().
     *
     * @return bool True on success (session populated); false on bad code / no pending user
     */
    public function attemptMfa(string $code): bool
    {
        $pendingId = (int) ($_SESSION['_mfa_pending_user_id'] ?? 0);
        if ($pendingId === 0) {
            return false;
        }

        $stmt = $this->db->query(
            'SELECT * FROM users WHERE id = ? AND is_active = 1 AND mfa_enabled = 1 LIMIT 1',
            [$pendingId]
        );
        $user = $stmt->fetch();
        if (!$user) {
            return false;
        }

        $secret = \StratFlow\Core\SecretManager::unprotectString(
            json_decode((string) $user['mfa_secret'], true) ?? $user['mfa_secret']
        );

        if ($secret === null) {
            return false;
        }

        // Try TOTP code first
        if (\StratFlow\Services\TotpService::verify($secret, $code)) {
            unset($_SESSION['_mfa_pending_user_id']);
            $this->login($user);
            return true;
        }

        // Try recovery code
        $stored = json_decode((string) ($user['mfa_recovery_codes'] ?? '[]'), true) ?? [];
        $idx    = \StratFlow\Services\TotpService::matchRecoveryCode($code, $stored);
        if ($idx >= 0) {
            // Invalidate the used recovery code
            unset($stored[$idx]);
            $this->db->query(
                'UPDATE users SET mfa_recovery_codes = ? WHERE id = ?',
                [json_encode(array_values($stored)), $pendingId]
            );
            unset($_SESSION['_mfa_pending_user_id']);
            $this->login($user);
            return true;
        }

        return false;
    }

    /**
     * Enable TOTP MFA for the given user. Stores the encrypted secret and hashed
     * recovery codes. Returns the plaintext recovery codes for one-time display.
     *
     * @return string[]  Plaintext recovery codes to show the user
     */
    public function enableMfa(int $userId, string $secret): array
    {
        $recoveryCodes  = \StratFlow\Services\TotpService::generateRecoveryCodes(8);
        $hashedCodes    = array_map(
            [\StratFlow\Services\TotpService::class, 'hashRecoveryCode'],
            $recoveryCodes
        );

        // Encrypt the secret at rest
        $encryptedSecret = \StratFlow\Core\SecretManager::protectString($secret);
        $storedSecret    = is_array($encryptedSecret)
            ? json_encode($encryptedSecret, JSON_UNESCAPED_SLASHES)
            : $encryptedSecret;

        $this->db->query(
            'UPDATE users SET mfa_secret = ?, mfa_enabled = 1, mfa_recovery_codes = ? WHERE id = ?',
            [$storedSecret, json_encode($hashedCodes), $userId]
        );

        return $recoveryCodes;
    }

    /**
     * Disable TOTP MFA for the given user.
     */
    public function disableMfa(int $userId): void
    {
        $this->db->query(
            'UPDATE users SET mfa_secret = NULL, mfa_enabled = 0, mfa_recovery_codes = NULL WHERE id = ?',
            [$userId]
        );
    }

    /**
     * Store user data in the session (log them in).
     *
     * @param array $user Row from the users table
     */
    public function login(array $user): void
    {
        // Regenerate session ID to prevent session fixation attacks
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

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

        $sessionUser = $this->session->get('user');
        if (!is_array($sessionUser) || empty($sessionUser['id']) || empty($sessionUser['org_id'])) {
            return false;
        }

        $row = $this->db->query(
            'SELECT u.id
             FROM users u
             JOIN organisations o ON o.id = u.org_id
             WHERE u.id = :user_id
               AND u.org_id = :org_id
               AND u.is_active = 1
               AND o.is_active = 1
             LIMIT 1',
            [
                ':user_id' => (int) $sessionUser['id'],
                ':org_id'  => (int) $sessionUser['org_id'],
            ]
        )->fetch();

        if (!$row) {
            $this->session->destroy();
            return false;
        }

        return true;
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
