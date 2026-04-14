<?php

declare(strict_types=1);

namespace StratFlow\Middleware;

use StratFlow\Core\Auth;
use StratFlow\Core\Response;
use StratFlow\Security\PermissionService;

/**
 * Restricts project creation to accounts with project.create capability.
 */
class ProjectCreateMiddleware
{
    public function handle(Auth $auth, Response $response): bool
    {
        $user = $auth->user();
        if (!$user || !PermissionService::can($user, PermissionService::PROJECT_CREATE)) {
            $response->redirect('/app/home');
            return false;
        }

        return true;
    }
}
