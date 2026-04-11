<?php
declare(strict_types=1);

namespace StratFlow\Middleware;

use StratFlow\Core\Auth;
use StratFlow\Core\Response;
use StratFlow\Security\PermissionService;

/**
 * Restricts project administration actions to accounts that can manage projects.
 */
class ProjectManageMiddleware
{
    public function handle(Auth $auth, Response $response): bool
    {
        $user = $auth->user();

        $canManage = $user && (
            PermissionService::can($user, PermissionService::PROJECT_EDIT_SETTINGS)
            || PermissionService::can($user, PermissionService::PROJECT_MANAGE_ACCESS)
            || PermissionService::can($user, PermissionService::PROJECT_DELETE)
        );

        if (!$canManage) {
            $response->redirect('/app/home');
            return false;
        }

        return true;
    }
}
