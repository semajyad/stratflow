<?php
declare(strict_types=1);

namespace StratFlow\Middleware;

use StratFlow\Core\Auth;
use StratFlow\Core\Response;

/**
 * Admin Middleware
 *
 * Restricts access to routes that require org_admin or superadmin role.
 * Redirects unauthorised users to the app home page.
 */
class AdminMiddleware
{
    /**
     * Check that the current user has an admin role.
     *
     * @param Auth     $auth     The authentication service
     * @param Response $response The response service used for redirects
     * @return bool True if the user is an admin, false if redirected
     */
    public function handle(Auth $auth, Response $response): bool
    {
        $user = $auth->user();
        if (!$user || !in_array($user['role'], ['org_admin', 'superadmin'])) {
            $response->redirect('/app/home');
            return false;
        }
        return true;
    }
}
