<?php
/**
 * HomeController
 *
 * Handles the authenticated dashboard home page (GET /app/home)
 * and project creation (POST /app/projects).
 */

declare(strict_types=1);

namespace StratFlow\Controllers;

use StratFlow\Core\Auth;
use StratFlow\Core\Database;
use StratFlow\Core\Request;
use StratFlow\Core\Response;
use StratFlow\Models\Project;

class HomeController
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

    // ===========================
    // ACTIONS
    // ===========================

    /**
     * Render the authenticated dashboard home page.
     *
     * Loads all projects belonging to the current user's organisation
     * and renders the home template inside the app layout.
     */
    public function index(): void
    {
        $user   = $this->auth->user();
        $orgId  = (int) $user['org_id'];

        $projects = Project::findByOrgId($this->db, $orgId);

        $this->response->render('home', [
            'user'        => $user,
            'projects'    => $projects,
            'active_page' => 'home',
            'flash_message' => $_SESSION['flash_message'] ?? null,
            'flash_error'   => $_SESSION['flash_error']   ?? null,
        ], 'app');

        unset($_SESSION['flash_message'], $_SESSION['flash_error']);
    }

    /**
     * Handle project creation form submission.
     *
     * Validates the project name, creates the project scoped to the
     * current user's organisation, then redirects back to the dashboard.
     */
    public function createProject(): void
    {
        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];
        $name  = trim((string) $this->request->post('name', ''));

        if ($name === '') {
            $_SESSION['flash_error'] = 'Project name cannot be empty.';
            $this->response->redirect('/app/home');
            return;
        }

        Project::create($this->db, [
            'org_id'     => $orgId,
            'name'       => $name,
            'status'     => 'draft',
            'created_by' => (int) $user['id'],
        ]);

        $_SESSION['flash_message'] = 'Project "' . $name . '" created successfully.';
        $this->response->redirect('/app/home');
    }
}
