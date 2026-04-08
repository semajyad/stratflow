<?php
declare(strict_types=1);

namespace StratFlow\Middleware;

use StratFlow\Core\Auth;
use StratFlow\Core\Response;

/**
 * Superadmin Middleware
 *
 * Restricts access to routes that require the superadmin role.
 * Redirects unauthorised users to the app home page.
 */
class SuperadminMiddleware
{
    /**
     * Check that the current user has the superadmin role.
     *
     * @param Auth     $auth     The authentication service
     * @param Response $response The response service used for redirects
     * @return bool True if the user is a superadmin, false if redirected
     */
    public function handle(Auth $auth, Response $response): bool
    {
        $user = $auth->user();
        if (!$user || $user['role'] !== 'superadmin') {
            $response->redirect('/app/home');
            return false;
        }
        return true;
    }
}
