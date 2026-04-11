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
use StratFlow\Models\Subscription;
use StratFlow\Models\UserStory;
use StratFlow\Security\ProjectPolicy;
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

        $project = ProjectPolicy::findViewableProject($this->db, $user, $projectId);
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
        $teams = \StratFlow\Models\Team::findByOrgId($this->db, $orgId);

        // Jira defaults are loaded client-side via /app/sprints/jira-defaults (background fetch)
        $jiraConnected = false;
        $jiraIntegration = \StratFlow\Models\Integration::findByOrgAndProvider($this->db, $orgId, 'jira');
        if ($jiraIntegration && ($jiraIntegration['status'] ?? '') === 'connected') {
            $jiraConnected = true;
        }

        $this->response->render('sprints', [
            'user'            => $user,
            'project'         => $project,
            'sprints'         => $sprints,
            'unallocated'     => $unallocated,
            'teams'           => $teams,
            'jira_connected'  => $jiraConnected,
            'active_page'     => 'sprints',
            'has_evaluation_board' => Subscription::hasEvaluationBoard($this->db, $orgId),
            'flash_message'   => $_SESSION['flash_message'] ?? null,
            'flash_error'     => $_SESSION['flash_error']   ?? null,
        ], 'app');

        unset($_SESSION['flash_message'], $_SESSION['flash_error']);
    }

    /**
     * Return Jira-derived sprint defaults as JSON for background client-side fetch.
     *
     * Returns: suggested_start (Y-m-d), sprint_length_days (7/14/21/28),
     *          next_sprint_number (int), suggested_capacity (int|null).
     *
     * Falls back gracefully when Jira is unavailable — next_sprint_number
     * is always populated from local sprint data.
     */
    public function jiraDefaults(): void
    {
        $user      = $this->auth->user();
        $orgId     = (int) $user['org_id'];
        $projectId = (int) $this->request->get('project_id', 0);
        $boardId   = (int) $this->request->get('board_id', 0);

        $project = ProjectPolicy::findViewableProject($this->db, $user, $projectId);
        if ($project === null) {
            $this->response->json(['error' => 'Not found'], 404);
            return;
        }

        // Compute local next sprint number from existing sprints
        $existingSprints  = Sprint::findByProjectId($this->db, $projectId);
        $localNextNumber  = count($existingSprints) + 1;
        foreach ($existingSprints as $s) {
            if (preg_match('/(\d+)\s*$/', $s['name'] ?? '', $m)) {
                $localNextNumber = max($localNextNumber, (int) $m[1] + 1);
            }
        }

        $result = [
            'suggested_start'    => null,
            'sprint_length_days' => 14,
            'next_sprint_number' => $localNextNumber,
            'suggested_capacity' => null,
        ];

        if ($boardId > 0) {
            $jiraIntegration = \StratFlow\Models\Integration::findByOrgAndProvider($this->db, $orgId, 'jira');
            if ($jiraIntegration && ($jiraIntegration['status'] ?? '') === 'active') {
                try {
                    $intConfig = json_decode($jiraIntegration['config_json'] ?? '{}', true) ?? [];
                    $spField   = $intConfig['field_mapping']['story_points_field'] ?? 'story_points';
                    $jira      = new \StratFlow\Services\JiraService($this->config['jira'], $jiraIntegration, $this->db);
                    $defaults  = $jira->getSprintDefaults($boardId, $spField);

                    $result['suggested_start']    = $defaults['suggested_start']    ?? null;
                    $result['sprint_length_days'] = $defaults['sprint_length_days'] ?? 14;
                    $result['suggested_capacity'] = $defaults['suggested_capacity'] ?? null;

                    // Jira's next sprint number wins if it's higher than local
                    if (!empty($defaults['next_sprint_number'])) {
                        $result['next_sprint_number'] = max($localNextNumber, (int) $defaults['next_sprint_number']);
                    }
                } catch (\Throwable) {
                    // Jira unavailable — return local defaults only
                }
            }
        }

        $this->response->json($result);
    }

    /**
     * Create a new sprint from form submission.
     */
    public function store(): void
    {
        $user      = $this->auth->user();
        $orgId     = (int) $user['org_id'];
        $projectId = (int) $this->request->post('project_id', 0);

        $project = ProjectPolicy::findEditableProject($this->db, $user, $projectId);
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
        $teamId       = $this->request->post('team_id', '') ?: null;

        Sprint::create($this->db, [
            'project_id'    => $projectId,
            'name'          => $name,
            'start_date'    => $startDate,
            'end_date'      => $endDate,
            'team_capacity' => $teamCapacity !== '' ? (int) $teamCapacity : null,
            'team_id'       => $teamId ? (int) $teamId : null,
        ]);

        $_SESSION['flash_message'] = 'Sprint created.';
        $this->response->redirect('/app/sprints?project_id=' . $projectId);
    }

    /**
     * Update a sprint's fields from form submission.
     *
     * @param int $id Sprint primary key (from route parameter)
     */
    public function update($id): void
    {
        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];

        $sprint = Sprint::findById($this->db, (int) $id);
        if ($sprint === null) {
            $this->response->redirect('/app/home');
            return;
        }

        $project = ProjectPolicy::findEditableProject($this->db, $user, (int) $sprint['project_id']);
        if ($project === null) {
            $this->response->redirect('/app/home');
            return;
        }

        $startDate    = $this->request->post('start_date', '') ?: null;
        $endDate      = $this->request->post('end_date', '') ?: null;
        $teamCapacity = $this->request->post('team_capacity', '');

        $teamId = $this->request->post('team_id', '');

        Sprint::update($this->db, $id, [
            'name'          => trim((string) $this->request->post('name', $sprint['name'])),
            'start_date'    => $startDate,
            'end_date'      => $endDate,
            'team_capacity' => $teamCapacity !== '' ? (int) $teamCapacity : null,
            'team_id'       => $teamId !== '' ? (int) $teamId : null,
        ]);

        $_SESSION['flash_message'] = 'Sprint updated.';
        $this->response->redirect('/app/sprints?project_id=' . $sprint['project_id']);
    }

    /**
     * Delete a sprint. CASCADE on sprint_stories returns stories to backlog.
     *
     * @param int $id Sprint primary key (from route parameter)
     */
    public function delete($id): void
    {
        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];

        $sprint = Sprint::findById($this->db, (int) $id);
        if ($sprint === null) {
            $this->response->redirect('/app/home');
            return;
        }

        $projectId = (int) $sprint['project_id'];
        $project   = ProjectPolicy::findEditableProject($this->db, $user, $projectId);
        if ($project === null) {
            $this->response->redirect('/app/home');
            return;
        }

        try {
            // Delete sprint stories first, then sprint
            SprintStory::deleteBySprintId($this->db, (int) $id);
            Sprint::delete($this->db, (int) $id);
            $_SESSION['flash_message'] = 'Sprint deleted. Stories returned to backlog.';
        } catch (\Throwable $e) {
            error_log('[StratFlow] Sprint delete error: ' . $e->getMessage());
            $_SESSION['flash_error'] = 'Failed to delete sprint: ' . $e->getMessage();
        }
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

        $project = ProjectPolicy::findEditableProject($this->db, $user, (int) $sprint['project_id']);
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

        $project = ProjectPolicy::findEditableProject($this->db, $user, (int) $sprint['project_id']);
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

        $project = ProjectPolicy::findEditableProject($this->db, $user, $projectId);
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

    /**
     * Auto-generate sprints and fill with stories by priority.
     *
     * Creates as many sprints as needed to fit all unallocated stories,
     * filling each sprint up to capacity in priority order.
     */
    public function autoGenerate(): void
    {
        $user      = $this->auth->user();
        $orgId     = (int) $user['org_id'];
        $projectId = (int) $this->request->post('project_id', 0);

        $project = ProjectPolicy::findEditableProject($this->db, $user, $projectId);
        if ($project === null) {
            $this->response->redirect('/app/home');
            return;
        }

        $startDate    = $this->request->post('start_date', '');
        $sprintLength = (int) $this->request->post('sprint_length', 14);
        $capacity     = (int) $this->request->post('capacity', 0);

        $numSprints   = (int) $this->request->post('num_sprints', 0);

        if ($startDate === '' || $capacity <= 0 || $numSprints <= 0) {
            $_SESSION['flash_error'] = 'Please provide a start date, number of sprints, and default capacity.';
            $this->response->redirect('/app/sprints?project_id=' . $projectId);
            return;
        }

        // Count existing sprints to continue numbering
        $existingSprints = Sprint::findByProjectId($this->db, $projectId);
        $nextNum = count($existingSprints) + 1;

        $currentDate = new \DateTime($startDate);
        $sprintsCreated = 0;

        for ($i = 0; $i < $numSprints && $i < 20; $i++) {
            $endDate = (clone $currentDate)->modify("+{$sprintLength} days");
            Sprint::create($this->db, [
                'project_id'    => $projectId,
                'name'          => 'Sprint ' . ($nextNum + $i),
                'start_date'    => $currentDate->format('Y-m-d'),
                'end_date'      => $endDate->format('Y-m-d'),
                'team_capacity' => $capacity,
            ]);
            $sprintsCreated++;
            $currentDate = $endDate;
        }

        $_SESSION['flash_message'] = "{$sprintsCreated} sprints created. Adjust capacity per sprint, then use Auto-Fill to allocate stories.";
        $this->response->redirect('/app/sprints?project_id=' . $projectId);
    }

    /**
     * Auto-fill existing sprints with unallocated stories by priority.
     *
     * Fills each sprint (in order) up to its remaining capacity with the
     * highest-priority unallocated stories.
     */
    public function autoFill(): void
    {
        $user      = $this->auth->user();
        $orgId     = (int) $user['org_id'];
        $projectId = (int) $this->request->post('project_id', 0);

        $project = ProjectPolicy::findEditableProject($this->db, $user, $projectId);
        if ($project === null) {
            $this->response->redirect('/app/home');
            return;
        }

        $sprints     = Sprint::findByProjectId($this->db, $projectId);
        $unallocated = UserStory::findUnallocated($this->db, $projectId);

        if (empty($sprints) || empty($unallocated)) {
            $_SESSION['flash_error'] = 'Need both sprints and unallocated stories to auto-fill.';
            $this->response->redirect('/app/sprints?project_id=' . $projectId);
            return;
        }

        // Priority-first packing: fill each sprint as close to capacity as possible.
        // Takes stories in priority order but if the next story doesn't fit,
        // tries smaller stories further down the queue to maximise utilisation.
        $assigned = 0;
        $remaining_stories = $unallocated; // Copy — we'll remove assigned ones

        foreach ($sprints as $sprint) {
            if (empty($remaining_stories)) break;

            $sprintId    = (int) $sprint['id'];
            $capacity    = (int) ($sprint['team_capacity'] ?? 0);
            $currentLoad = SprintStory::getSprintLoad($this->db, $sprintId);
            $remaining   = $capacity - $currentLoad;

            if ($remaining <= 0) continue;

            // Take stories in priority order; skip ones that don't fit
            // (they'll be tried in the next sprint)
            $still_remaining = [];
            foreach ($remaining_stories as $story) {
                $size = (int) ($story['size'] ?? 1);

                if ($remaining > 0 && $size <= $remaining) {
                    SprintStory::assign($this->db, $sprintId, (int) $story['id']);
                    $remaining -= $size;
                    $assigned++;
                } else {
                    // Doesn't fit this sprint — keep for next sprint
                    $still_remaining[] = $story;
                }
            }

            $remaining_stories = $still_remaining;
        }

        $skipped = count($remaining_stories);
        $msg = "{$assigned} stories allocated across sprints.";
        if ($skipped > 0) {
            $msg .= " {$skipped} stories didn't fit — add more sprints or increase capacity.";
        }
        $_SESSION['flash_message'] = $msg;
        $this->response->redirect('/app/sprints?project_id=' . $projectId);
    }
}
