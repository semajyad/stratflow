<?php
/**
 * SprintController
 *
 * Handles the Sprint Allocation page: create/edit/delete sprints,
 * drag-and-drop story assignment between backlog and sprint buckets,
 * and AI-powered auto-allocation based on priority and capacity.
 */

declare(strict_types=1);

namespace StratFlow\Controllers;

use StratFlow\Core\Auth;
use StratFlow\Core\Database;
use StratFlow\Core\Request;
use StratFlow\Core\Response;
use StratFlow\Models\Project;
use StratFlow\Models\Sprint;
use StratFlow\Models\SprintStory;
use StratFlow\Models\UserStory;
use StratFlow\Services\GeminiService;
use StratFlow\Services\Prompts\SprintPrompt;

class SprintController
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
     * Render the sprint allocation page with backlog and sprint buckets.
     *
     * Loads all sprints with their assigned stories, plus the unallocated
     * backlog, and renders the split-view template.
     */
    public function index(): void
    {
        $user      = $this->auth->user();
        $orgId     = (int) $user['org_id'];
        $projectId = (int) $this->request->get('project_id', 0);

        $project = Project::findById($this->db, $projectId, $orgId);
        if ($project === null) {
            $this->response->redirect('/app/home');
            return;
        }

        $sprints = Sprint::findByProjectId($this->db, $projectId);

        // Load stories for each sprint
        foreach ($sprints as &$sprint) {
            $sprint['stories'] = SprintStory::findBySprintId($this->db, (int) $sprint['id']);
        }
        unset($sprint);

        $unallocated = UserStory::findUnallocated($this->db, $projectId);

        $this->response->render('sprints', [
            'user'          => $user,
            'project'       => $project,
            'sprints'       => $sprints,
            'unallocated'   => $unallocated,
            'active_page'   => 'sprints',
            'flash_message' => $_SESSION['flash_message'] ?? null,
            'flash_error'   => $_SESSION['flash_error']   ?? null,
        ], 'app');

        unset($_SESSION['flash_message'], $_SESSION['flash_error']);
    }

    /**
     * Create a new sprint from form submission.
     */
    public function store(): void
    {
        $user      = $this->auth->user();
        $orgId     = (int) $user['org_id'];
        $projectId = (int) $this->request->post('project_id', 0);

        $project = Project::findById($this->db, $projectId, $orgId);
        if ($project === null) {
            $this->response->redirect('/app/home');
            return;
        }

        $name = trim((string) $this->request->post('name', ''));
        if ($name === '') {
            $_SESSION['flash_error'] = 'Sprint name is required.';
            $this->response->redirect('/app/sprints?project_id=' . $projectId);
            return;
        }

        $startDate    = $this->request->post('start_date', '') ?: null;
        $endDate      = $this->request->post('end_date', '') ?: null;
        $teamCapacity = $this->request->post('team_capacity', '');

        Sprint::create($this->db, [
            'project_id'    => $projectId,
            'name'          => $name,
            'start_date'    => $startDate,
            'end_date'      => $endDate,
            'team_capacity' => $teamCapacity !== '' ? (int) $teamCapacity : null,
        ]);

        $_SESSION['flash_message'] = 'Sprint created.';
        $this->response->redirect('/app/sprints?project_id=' . $projectId);
    }

    /**
     * Update a sprint's fields from form submission.
     *
     * @param int $id Sprint primary key (from route parameter)
     */
    public function update(int $id): void
    {
        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];

        $sprint = Sprint::findById($this->db, $id);
        if ($sprint === null) {
            $this->response->redirect('/app/home');
            return;
        }

        $project = Project::findById($this->db, (int) $sprint['project_id'], $orgId);
        if ($project === null) {
            $this->response->redirect('/app/home');
            return;
        }

        $startDate    = $this->request->post('start_date', '') ?: null;
        $endDate      = $this->request->post('end_date', '') ?: null;
        $teamCapacity = $this->request->post('team_capacity', '');

        Sprint::update($this->db, $id, [
            'name'          => trim((string) $this->request->post('name', $sprint['name'])),
            'start_date'    => $startDate,
            'end_date'      => $endDate,
            'team_capacity' => $teamCapacity !== '' ? (int) $teamCapacity : null,
        ]);

        $_SESSION['flash_message'] = 'Sprint updated.';
        $this->response->redirect('/app/sprints?project_id=' . $sprint['project_id']);
    }

    /**
     * Delete a sprint. CASCADE on sprint_stories returns stories to backlog.
     *
     * @param int $id Sprint primary key (from route parameter)
     */
    public function delete(int $id): void
    {
        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];

        $sprint = Sprint::findById($this->db, $id);
        if ($sprint === null) {
            $this->response->redirect('/app/home');
            return;
        }

        $projectId = (int) $sprint['project_id'];
        $project   = Project::findById($this->db, $projectId, $orgId);
        if ($project === null) {
            $this->response->redirect('/app/home');
            return;
        }

        Sprint::delete($this->db, $id);

        $_SESSION['flash_message'] = 'Sprint deleted. Stories returned to backlog.';
        $this->response->redirect('/app/sprints?project_id=' . $projectId);
    }

    /**
     * Assign a user story to a sprint via AJAX.
     *
     * Expects JSON body: {sprint_id, story_id}.
     * Verifies the story belongs to the same project as the sprint.
     */
    public function assignStory(): void
    {
        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];

        $body     = json_decode($this->request->body(), true);
        $sprintId = (int) ($body['sprint_id'] ?? 0);
        $storyId  = (int) ($body['story_id'] ?? 0);

        if ($sprintId === 0 || $storyId === 0) {
            $this->response->json(['status' => 'error', 'message' => 'Missing sprint_id or story_id'], 400);
            return;
        }

        $sprint = Sprint::findById($this->db, $sprintId);
        if ($sprint === null) {
            $this->response->json(['status' => 'error', 'message' => 'Sprint not found'], 404);
            return;
        }

        $story = UserStory::findById($this->db, $storyId);
        if ($story === null || (int) $story['project_id'] !== (int) $sprint['project_id']) {
            $this->response->json(['status' => 'error', 'message' => 'Story not found or project mismatch'], 404);
            return;
        }

        $project = Project::findById($this->db, (int) $sprint['project_id'], $orgId);
        if ($project === null) {
            $this->response->json(['status' => 'error', 'message' => 'Access denied'], 403);
            return;
        }

        // Unassign from any existing sprint first
        $existingSprint = SprintStory::findSprintForStory($this->db, $storyId);
        if ($existingSprint !== null) {
            SprintStory::unassign($this->db, (int) $existingSprint['id'], $storyId);
        }

        SprintStory::assign($this->db, $sprintId, $storyId);

        $load = SprintStory::getSprintLoad($this->db, $sprintId);

        $this->response->json(['status' => 'ok', 'sprint_load' => $load]);
    }

    /**
     * Unassign a user story from a sprint via AJAX.
     *
     * Expects JSON body: {sprint_id, story_id}.
     */
    public function unassignStory(): void
    {
        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];

        $body     = json_decode($this->request->body(), true);
        $sprintId = (int) ($body['sprint_id'] ?? 0);
        $storyId  = (int) ($body['story_id'] ?? 0);

        if ($sprintId === 0 || $storyId === 0) {
            $this->response->json(['status' => 'error', 'message' => 'Missing sprint_id or story_id'], 400);
            return;
        }

        $sprint = Sprint::findById($this->db, $sprintId);
        if ($sprint === null) {
            $this->response->json(['status' => 'error', 'message' => 'Sprint not found'], 404);
            return;
        }

        $project = Project::findById($this->db, (int) $sprint['project_id'], $orgId);
        if ($project === null) {
            $this->response->json(['status' => 'error', 'message' => 'Access denied'], 403);
            return;
        }

        SprintStory::unassign($this->db, $sprintId, $storyId);

        $load = SprintStory::getSprintLoad($this->db, $sprintId);

        $this->response->json(['status' => 'ok', 'sprint_load' => $load]);
    }

    /**
     * AI auto-allocate unallocated stories into available sprints.
     *
     * Builds a prompt with sprint capacities and story data, calls Gemini,
     * and assigns stories according to the AI response.
     */
    public function aiAllocate(): void
    {
        $user      = $this->auth->user();
        $orgId     = (int) $user['org_id'];
        $projectId = (int) $this->request->post('project_id', 0);

        $project = Project::findById($this->db, $projectId, $orgId);
        if ($project === null) {
            $this->response->redirect('/app/home');
            return;
        }

        $sprints     = Sprint::findByProjectId($this->db, $projectId);
        $unallocated = UserStory::findUnallocated($this->db, $projectId);

        if (empty($sprints)) {
            $_SESSION['flash_error'] = 'Create at least one sprint before auto-allocating.';
            $this->response->redirect('/app/sprints?project_id=' . $projectId);
            return;
        }

        if (empty($unallocated)) {
            $_SESSION['flash_error'] = 'No unallocated stories to assign.';
            $this->response->redirect('/app/sprints?project_id=' . $projectId);
            return;
        }

        // Build sprint info string
        $sprintLines = [];
        foreach ($sprints as $s) {
            $currentLoad = SprintStory::getSprintLoad($this->db, (int) $s['id']);
            $remaining   = max(0, (int) ($s['team_capacity'] ?? 0) - $currentLoad);
            $sprintLines[] = "- Sprint ID {$s['id']}: \"{$s['name']}\", capacity: {$s['team_capacity']} pts, already used: {$currentLoad} pts, remaining: {$remaining} pts";
        }

        // Build story info string
        $storyLines = [];
        foreach ($unallocated as $st) {
            $size    = $st['size'] ?? 'unknown';
            $blocked = $st['blocked_by'] ? " (blocked by story #{$st['blocked_by']})" : '';
            $storyLines[] = "- Story ID {$st['id']}: \"{$st['title']}\", priority: {$st['priority_number']}, size: {$size} pts{$blocked}";
        }

        $prompt = str_replace(
            ['{sprints}', '{stories}'],
            [implode("\n", $sprintLines), implode("\n", $storyLines)],
            SprintPrompt::ALLOCATE_PROMPT
        );

        try {
            $gemini      = new GeminiService($this->config);
            $assignments = $gemini->generateJson($prompt, '');
        } catch (\RuntimeException $e) {
            $_SESSION['flash_error'] = 'AI allocation failed: ' . $e->getMessage();
            $this->response->redirect('/app/sprints?project_id=' . $projectId);
            return;
        }

        if (!is_array($assignments)) {
            $_SESSION['flash_error'] = 'AI returned an invalid response.';
            $this->response->redirect('/app/sprints?project_id=' . $projectId);
            return;
        }

        // Validate sprint IDs belong to this project
        $validSprintIds = array_column($sprints, 'id');
        $validStoryIds  = array_column($unallocated, 'id');
        $assignedCount  = 0;

        foreach ($assignments as $assignment) {
            $sId = (int) ($assignment['sprint_id'] ?? 0);
            $stId = (int) ($assignment['story_id'] ?? 0);

            if (in_array($sId, $validSprintIds) && in_array($stId, $validStoryIds)) {
                SprintStory::assign($this->db, $sId, $stId);
                $assignedCount++;
            }
        }

        $_SESSION['flash_message'] = "{$assignedCount} stories auto-allocated across sprints.";
        $this->response->redirect('/app/sprints?project_id=' . $projectId);
    }
}
