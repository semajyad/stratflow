<?php
/**
 * TraceabilityController
 *
 * Renders the Traceability page: a read-only collapsible tree showing the
 * full chain from OKR node → HL work item → user story → Jira key → git
 * links, with status rollups at every level.
 *
 * This is a monitoring view — no forms, no writes, no AI calls.
 */

declare(strict_types=1);

namespace StratFlow\Controllers;

use StratFlow\Core\Auth;
use StratFlow\Core\Database;
use StratFlow\Core\Request;
use StratFlow\Core\Response;
use StratFlow\Services\TraceabilityService;

class TraceabilityController
{
    // ===========================
    // PROPERTIES
    // ===========================

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

    // ===========================
    // ACTIONS
    // ===========================

    /**
     * Render the traceability tree for a project.
     *
     * Falls back to $_SESSION['_last_project_id'] when no project_id GET
     * param is present. Delegates data assembly to TraceabilityService and
     * renders the traceability template inside the app layout.
     */
    public function index(): void
    {
        $user      = $this->auth->user();
        $orgId     = (int) $user['org_id'];
        $projectId = (int) $this->request->get('project_id', $_SESSION['_last_project_id'] ?? 0);

        $service = new TraceabilityService($this->db);
        $tree    = $service->forProject($projectId, $orgId);

        if ($tree === null) {
            $this->response->redirect('/app/home');
            return;
        }

        $this->response->render('traceability', [
            'user'        => $user,
            'project'     => $tree['project'],
            'tree'        => $tree,
            'active_page' => 'traceability',
        ], 'app');
    }
}
