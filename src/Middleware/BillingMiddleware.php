<?php

declare(strict_types=1);

namespace StratFlow\Middleware;

use StratFlow\Core\Auth;
use StratFlow\Core\Database;
use StratFlow\Core\Response;
use StratFlow\Security\PermissionService;

/**
 * Billing Middleware
 *
 * Restricts billing pages to users with has_billing_access flag,
 * org_admin, or superadmin. Billing access is independent of role —
 * any role can be granted billing access via the flag.
 */
class BillingMiddleware
{
    public function handle(Auth $auth, Response $response): bool
    {
        $user = $auth->user();
        if (!$user) {
            $response->redirect('/login');
            return false;
        }

        $db = null;
        try {
            $db = Database::getInstance();
        } catch (\Throwable) {
            $db = null;
        }

        if (!PermissionService::canViewBilling($user, $db)) {
            $response->redirect('/app/home');
            return false;
        }
        return true;
    }
}
