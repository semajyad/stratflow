<?php
/**
 * AuthController
 *
 * Handles the login page (GET /login), login form submission (POST /login),
 * and logout (POST /logout). Integrates with Auth for credential verification
 * and rate limiting.
 */

declare(strict_types=1);

namespace StratFlow\Controllers;

use StratFlow\Core\Auth;
use StratFlow\Core\Database;
use StratFlow\Core\Request;
use StratFlow\Core\Response;
use StratFlow\Models\PasswordToken;
use StratFlow\Models\User;
use StratFlow\Services\EmailService;

class AuthController
{
    protected Request $request;
    protected Response $response;
    protected Auth $auth;
    protected Database $db;
    protected array $config;

    public function __construct(Request $request, Response $response, Auth $auth, Database $db, array $config)
    {
        $this->request  = $request;
        $this->response = $response;
        $this->auth     = $auth;
        $this->db       = $db;
        $this->config   = $config;
    }

    /**
     * Show the login page.
     *
     * Redirects already-authenticated users to /app/home.
     * Passes any error message to the template (set by a prior failed login).
     */
    public function showLogin(): void
    {
        if ($this->auth->check()) {
            $this->response->redirect('/app/home');
        }

        $this->response->render('login', [
            'error'         => $_SESSION['login_error'] ?? null,
            'flash_message' => $_SESSION['flash_message'] ?? null,
        ]);

        unset($_SESSION['login_error'], $_SESSION['flash_message']);
    }

    /**
     * Process login form submission.
     *
     * Checks rate limiting first, then attempts authentication.
     * On success redirects to /app/home; on failure re-renders the login
     * page with an error message and records the failed attempt.
     */
    public function login(): void
    {
        $email    = trim((string) $this->request->post('email', ''));
        $password = (string) $this->request->post('password', '');
        $ip       = $this->request->ip();

        if ($this->auth->isRateLimited($ip)) {
            $this->response->render('login', [
                'error' => 'Too many login attempts. Please try again in 15 minutes.',
            ]);
            return;
        }

        if ($this->auth->attempt($email, $password)) {
            $this->response->redirect('/app/home');
        }

        $this->auth->recordFailedAttempt($ip);
        $this->response->render('login', [
            'error' => 'Invalid email or password.',
        ]);
    }

    /**
     * Log the current user out and redirect to the login page.
     */
    public function logout(): void
    {
        $this->auth->logout();
        $this->response->redirect('/login');
    }

    // =========================================================================
    // PASSWORD RESET FLOW
    // =========================================================================

    /**
     * Show the forgot-password form.
     *
     * GET /forgot-password — renders a simple email input form.
     */
    public function showForgotPassword(): void
    {
        if ($this->auth->check()) {
            $this->response->redirect('/app/home');
        }

        $this->response->render('forgot-password', []);
    }

    /**
     * Handle forgot-password form submission.
     *
     * POST /forgot-password — looks up the user by email, creates a reset token,
     * and sends a password reset email. Always shows a success message regardless
     * of whether the email exists (prevents user enumeration).
     */
    public function sendResetEmail(): void
    {
        $email = trim((string) $this->request->post('email', ''));

        // Always show success to prevent user enumeration
        $user = User::findByEmail($this->db, $email);

        if ($user && $user['is_active']) {
            $token = PasswordToken::create($this->db, (int) $user['id'], 'reset_password');
            $resetUrl = rtrim($this->config['app']['url'], '/') . '/set-password/' . $token;

            $emailService = new EmailService($this->config);
            $emailService->sendPasswordReset($email, $user['full_name'], $resetUrl);
        }

        $this->response->render('forgot-password', [
            'success' => true,
        ]);
    }

    /**
     * Show the set-password form for a given token.
     *
     * GET /set-password/{token} — validates the token and renders the
     * password input form if valid, or an error message if expired/invalid.
     *
     * @param string $token The password token from the URL
     */
    public function showSetPassword(string $token): void
    {
        $tokenRow = PasswordToken::findByToken($this->db, $token);

        $this->response->render('set-password', [
            'token'       => $token,
            'token_valid' => $tokenRow !== null,
        ]);
    }

    /**
     * Process the set-password form submission.
     *
     * POST /set-password/{token} — validates the token, checks password
     * requirements (min 8 chars, confirmation match), updates the user's
     * password hash, marks the token as used, and redirects to login.
     *
     * @param string $token The password token from the URL
     */
    public function setPassword(string $token): void
    {
        $tokenRow = PasswordToken::findByToken($this->db, $token);

        if ($tokenRow === null) {
            $this->response->render('set-password', [
                'token'       => $token,
                'token_valid' => false,
            ]);
            return;
        }

        $password     = (string) $this->request->post('password', '');
        $confirmation = (string) $this->request->post('password_confirmation', '');

        if (strlen($password) < 8) {
            $this->response->render('set-password', [
                'token'       => $token,
                'token_valid' => true,
                'error'       => 'Password must be at least 8 characters.',
            ]);
            return;
        }

        if ($password !== $confirmation) {
            $this->response->render('set-password', [
                'token'       => $token,
                'token_valid' => true,
                'error'       => 'Passwords do not match.',
            ]);
            return;
        }

        // Update user password and mark token as used
        User::update($this->db, (int) $tokenRow['user_id'], [
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ]);

        PasswordToken::markUsed($this->db, (int) $tokenRow['id']);

        $_SESSION['login_error'] = null;
        $_SESSION['flash_message'] = 'Your password has been set. You can now sign in.';
        $this->response->redirect('/login');
    }
}
