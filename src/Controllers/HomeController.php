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

        // Compute smart "next step" destination and progress for each project
        foreach ($projects as &$p) {
            $stage = $this->getProjectStage($this->db, (int) $p['id']);
            $p['next_step_url']  = $stage['next_url'] . '?project_id=' . (int) $p['id'];
            $p['next_step_label'] = $stage['next_label'];
            $p['steps_complete']  = $stage['steps_complete'];
            $p['steps_total']     = $stage['steps_total'];
        }
        unset($p);

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

    /**
     * Compute the next uncompleted workflow step for a project.
     *
     * Returns the target URL and label for a "smart" project link, plus
     * a progress count (steps_complete / steps_total) for progress dots.
     *
     * @return array{next_url:string, next_label:string, steps_complete:int, steps_total:int}
     */
    public static function getProjectStage(Database $db, int $projectId): array
    {
        // Check each workflow step in order and find the first uncompleted one
        $steps = [
            ['key' => 'upload',         'url' => '/app/upload',         'label' => 'Upload Document'],
            ['key' => 'diagram',        'url' => '/app/diagram',        'label' => 'Generate Roadmap'],
            ['key' => 'work-items',     'url' => '/app/work-items',     'label' => 'Generate Work Items'],
            ['key' => 'prioritisation', 'url' => '/app/prioritisation', 'label' => 'Prioritise'],
            ['key' => 'risks',          'url' => '/app/risks',          'label' => 'Model Risks'],
            ['key' => 'user-stories',   'url' => '/app/user-stories',   'label' => 'Decompose Stories'],
            ['key' => 'sprints',        'url' => '/app/sprints',        'label' => 'Allocate Sprints'],
            ['key' => 'governance',     'url' => '/app/governance',     'label' => 'Governance'],
        ];

        // Run completion checks (avoid hammering DB — use simple COUNT queries)
        $completion = self::computeStepCompletion($db, $projectId);

        $complete = 0;
        $nextUrl   = $steps[0]['url'];
        $nextLabel = $steps[0]['label'];
        $foundNext = false;

        foreach ($steps as $step) {
            if (!empty($completion[$step['key']])) {
                $complete++;
                continue;
            }
            // First uncompleted step becomes the "next"
            if (!$foundNext) {
                $nextUrl   = $step['url'];
                $nextLabel = $step['label'];
                $foundNext = true;
            }
        }

        // If all steps complete, default to governance (last step)
        if (!$foundNext) {
            $nextUrl   = '/app/governance';
            $nextLabel = 'View Governance';
        }

        return [
            'next_url'       => $nextUrl,
            'next_label'     => $nextLabel,
            'steps_complete' => $complete,
            'steps_total'    => count($steps),
            'completion'     => $completion,
        ];
    }

    /**
     * Return array keyed by step name with true/false for each workflow step.
     */
    public static function computeStepCompletion(Database $db, int $projectId): array
    {
        $completion = [];

        // Upload: has any document with extracted text
        $stmt = $db->query(
            "SELECT COUNT(*) AS c FROM documents WHERE project_id = :p AND extracted_text IS NOT NULL AND extracted_text != ''",
            [':p' => $projectId]
        );
        $completion['upload'] = ($stmt->fetch()['c'] ?? 0) > 0;

        // Diagram: has strategy_diagrams row
        $stmt = $db->query(
            "SELECT COUNT(*) AS c FROM strategy_diagrams WHERE project_id = :p",
            [':p' => $projectId]
        );
        $completion['diagram'] = ($stmt->fetch()['c'] ?? 0) > 0;

        // Work items
        $stmt = $db->query(
            "SELECT COUNT(*) AS c FROM hl_work_items WHERE project_id = :p",
            [':p' => $projectId]
        );
        $workItemCount = (int) ($stmt->fetch()['c'] ?? 0);
        $completion['work-items'] = $workItemCount > 0;

        // Prioritisation: any work item with final_score set
        $stmt = $db->query(
            "SELECT COUNT(*) AS c FROM hl_work_items WHERE project_id = :p AND final_score IS NOT NULL",
            [':p' => $projectId]
        );
        $completion['prioritisation'] = ($stmt->fetch()['c'] ?? 0) > 0;

        // Risks
        $stmt = $db->query(
            "SELECT COUNT(*) AS c FROM risks WHERE project_id = :p",
            [':p' => $projectId]
        );
        $completion['risks'] = ($stmt->fetch()['c'] ?? 0) > 0;

        // User stories
        $stmt = $db->query(
            "SELECT COUNT(*) AS c FROM user_stories WHERE project_id = :p",
            [':p' => $projectId]
        );
        $completion['user-stories'] = ($stmt->fetch()['c'] ?? 0) > 0;

        // Sprints
        $stmt = $db->query(
            "SELECT COUNT(*) AS c FROM sprints WHERE project_id = :p",
            [':p' => $projectId]
        );
        $completion['sprints'] = ($stmt->fetch()['c'] ?? 0) > 0;

        // Governance: has a baseline
        $stmt = $db->query(
            "SELECT COUNT(*) AS c FROM strategic_baselines WHERE project_id = :p",
            [':p' => $projectId]
        );
        $completion['governance'] = ($stmt->fetch()['c'] ?? 0) > 0;

        return $completion;
    }
}
