<?php
/**
 * ApiProjectsController
 *
 * JSON API endpoint for projects, authenticated via Personal Access Tokens.
 * Respects project_permissions (migration 028) — restricted projects are
 * hidden from non-member users, matching UI visibility.
 *
 * Routes (all require api_auth middleware):
 *   GET /api/v1/projects — list accessible projects for the authenticated user
 */

declare(strict_types=1);

namespace StratFlow\Controllers;

use StratFlow\Core\Auth;
use StratFlow\Core\Database;
use StratFlow\Core\Request;
use StratFlow\Core\Response;
use StratFlow\Models\Project;

class ApiProjectsController
{
    protected Request  $request;
    protected Response $response;
    protected Auth     $auth;
    protected Database $db;
    protected array    $config;

    public function __construct(Request $request, Response $response, Auth $auth, Database $db, array $config)
    {
        $this->request  = $request;
        $this->response = $response;
        $this->auth     = $auth;
        $this->db       = $db;
        $this->config   = $config;
    }

    /**
     * GET /api/v1/projects
     *
     * Returns projects accessible to the authenticated user, respecting
     * project_permissions visibility rules. Order: newest first.
     */
    public function index(): void
    {
        $user   = $this->auth->user();
        $projects = Project::findAccessibleByOrgId($this->db, $user);

        $data = array_map(fn($p) => [
            'id'         => (int) $p['id'],
            'name'       => $p['name'],
            'visibility' => $p['visibility'] ?? 'everyone',
            'created_at' => $p['created_at'],
        ], $projects);

        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode(['data' => $data, 'count' => count($data)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}
