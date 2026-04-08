<?php

declare(strict_types=1);

namespace StratFlow\Middleware;

use StratFlow\Core\Auth;
use StratFlow\Core\Response;

/**
 * Authentication Middleware
 *
 * Checks that the user is logged in. Redirects to /login if not.
 */
class AuthMiddleware
{
    /**
     * Verify the user is authenticated.
     *
     * @return bool True if authenticated, false if redirected
     */
    public function handle(Auth $auth, Response $response): bool
    {
        if (!$auth->check()) {
            // Save the intended URL so we can redirect back after login
            $intendedUrl = $_SERVER['REQUEST_URI'] ?? '/app/home';
            $_SESSION['_intended_url'] = $intendedUrl;
            $response->redirect('/login');
            return false;
        }

        return true;
    }
}
