<?php

declare(strict_types=1);

namespace StratFlow\Middleware;

use StratFlow\Core\Auth;
use StratFlow\Core\Request;
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
            if (Request::expectsJson()) {
                Response::applySecurityHeaders('app');
                http_response_code(403);
                header('Content-Type: application/json');
                echo json_encode(['error' => 'Insufficient permissions']);
            } else {
                $response->redirect('/app/home');
            }
            return false;
        }

        return true;
    }
}
