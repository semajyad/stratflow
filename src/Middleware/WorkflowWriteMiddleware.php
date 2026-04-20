<?php

declare(strict_types=1);

namespace StratFlow\Middleware;

use StratFlow\Core\Auth;
use StratFlow\Core\Response;
use StratFlow\Security\PermissionService;

/**
 * Restricts workflow write actions to accounts with workflow.edit capability.
 */
class WorkflowWriteMiddleware
{
    public function handle(Auth $auth, Response $response): bool
    {
        $user = $auth->user();
        if (!$user || !PermissionService::can($user, PermissionService::WORKFLOW_EDIT)) {
            if (self::isAjaxRequest()) {
                Response::applySecurityHeaders('app');
                http_response_code(403);
                echo json_encode(['error' => 'Insufficient permissions']);
            } else {
                $response->redirect('/app/home');
            }
            return false;
        }

        return true;
    }

    private static function isAjaxRequest(): bool
    {
        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
            return true;
        }
        $ct = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
        return str_starts_with($ct, 'application/json');
    }
}
