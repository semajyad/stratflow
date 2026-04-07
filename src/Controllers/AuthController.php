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
            'error' => $_SESSION['login_error'] ?? null,
        ]);

        unset($_SESSION['login_error']);
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
}
