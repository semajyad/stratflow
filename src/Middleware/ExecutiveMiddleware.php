<?php
declare(strict_types=1);

namespace StratFlow\Middleware;

use StratFlow\Core\Auth;
use StratFlow\Core\Response;

/**
 * Executive Middleware
 *
 * Restricts the Executive Dashboard to users with the has_executive_access flag
 * or superadmin role. Unlike BillingMiddleware, org_admin does NOT receive
 * automatic access — every user except superadmin must be explicitly flagged
 * by an admin via Admin → Users.
 */
class ExecutiveMiddleware
{
    public function handle(Auth $auth, Response $response): bool
    {
        $user = $auth->user();
        if (!$user) {
            $response->redirect('/login');
            return false;
        }

        $isSuperadmin    = $user['role'] === 'superadmin';
        $hasExecAccess   = (bool) ($user['has_executive_access'] ?? false);

        if (!$isSuperadmin && !$hasExecAccess) {
            $response->redirect('/app/home');
            return false;
        }

        return true;
    }
}
