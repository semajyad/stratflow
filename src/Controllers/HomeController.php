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

        // Check if Jira is connected for this org and load Jira projects
        $jiraConnected = false;
        $jiraProjects = [];
        $integration = \StratFlow\Models\Integration::findByOrgAndProvider($this->db, $orgId, 'jira');
        if ($integration && $integration['status'] === 'active') {
            $jiraConnected = true;
            try {
                $jiraService = new \StratFlow\Services\JiraService($this->config['jira'] ?? [], $integration, $this->db);
                $jiraProjects = $jiraService->getProjects();
            } catch (\Throwable $e) {
                // Jira API failed — just skip project list
                $jiraProjects = [];
            }
        }

        $this->response->render('home', [
            'user'           => $user,
            'projects'       => $projects,
            'jira_connected' => $jiraConnected,
            'jira_projects'  => $jiraProjects,
            'active_page'    => 'home',
            'flash_message'  => $_SESSION['flash_message'] ?? null,
            'flash_error'    => $_SESSION['flash_error']   ?? null,
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

    /**
     * Link a StratFlow project to a Jira project.
     */
    public function linkJira($id): void
    {
        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];
        $projectId = (int) $id;

        $project = Project::findById($this->db, $projectId, $orgId);
        if ($project === null) {
            $this->response->redirect('/app/home');
            return;
        }

        $jiraKey = trim((string) $this->request->post('jira_project_key', ''));
        Project::update($this->db, $projectId, [
            'jira_project_key' => $jiraKey ?: null,
        ], $orgId);

        if ($jiraKey) {
            $_SESSION['flash_message'] = "Project linked to Jira project {$jiraKey}.";
        } else {
            $_SESSION['flash_message'] = 'Jira link removed.';
        }
        $this->response->redirect('/app/home');
    }

    /**
     * Rename a project.
     */
    public function renameProject($id): void
    {
        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];
        $projectId = (int) $id;
        $name = trim((string) $this->request->post('name', ''));

        if ($name === '') {
            $_SESSION['flash_error'] = 'Project name cannot be empty.';
            $this->response->redirect('/app/home');
            return;
        }

        $project = Project::findById($this->db, $projectId, $orgId);
        if ($project === null) {
            $this->response->redirect('/app/home');
            return;
        }

        Project::update($this->db, $projectId, ['name' => $name], $orgId);
        $_SESSION['flash_message'] = 'Project renamed to "' . $name . '".';
        $this->response->redirect('/app/home');
    }

    /**
     * Delete a project (admin only).
     */
    public function deleteProject($id): void
    {
        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];
        $projectId = (int) $id;

        $project = Project::findById($this->db, $projectId, $orgId);
        if ($project === null) {
            $this->response->redirect('/app/home');
            return;
        }

        Project::delete($this->db, $projectId, $orgId);
        $_SESSION['flash_message'] = 'Project "' . $project['name'] . '" deleted.';
        $this->response->redirect('/app/home');
    }
}
