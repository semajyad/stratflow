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
     * For developer-role users: only /app/account/* routes are accessible.
     * All other app routes redirect to the token management page.
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

        // Developer accounts may only access the token management page.
        // They use the MCP server for everything else.
        $user = $auth->user();
        if (($user['role'] ?? '') === 'developer') {
            $uri = $_SERVER['REQUEST_URI'] ?? '';
            $path = parse_url($uri, PHP_URL_PATH) ?? '';
            if (!str_starts_with($path, '/app/account/')) {
                $response->redirect('/app/account/tokens');
                return false;
            }
        }

        return true;
    }
}
