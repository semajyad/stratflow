<?php
/**
 * UserStoryController
 *
 * Handles the User Stories page: AI decomposition from HL work items,
 * drag-and-drop reordering, CRUD operations, AI size suggestion,
 * dependency tracking, and CSV/JSON/Jira export.
 */

declare(strict_types=1);

namespace StratFlow\Controllers;

use StratFlow\Core\Auth;
use StratFlow\Core\Database;
use StratFlow\Core\Request;
use StratFlow\Core\Response;
use StratFlow\Models\HLWorkItem;
use StratFlow\Models\Project;
use StratFlow\Models\Subscription;
use StratFlow\Models\UserStory;
use StratFlow\Models\GovernanceItem;
use StratFlow\Models\StrategicBaseline;
use StratFlow\Services\GeminiService;
use StratFlow\Services\Prompts\UserStoryPrompt;

class UserStoryController
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
     * Render the user stories page for a specific project.
     *
     * Loads stories with parent HL titles, and HL items for the
     * split-selection checkboxes.
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

        $stories   = UserStory::findByProjectId($this->db, $projectId);
        $workItems = HLWorkItem::findByProjectId($this->db, $projectId);
        $teams     = \StratFlow\Models\Team::findByOrgId($this->db, $orgId);

        $this->response->render('user-stories', [
            'user'                 => $user,
            'project'              => $project,
            'stories'              => $stories,
            'work_items'           => $workItems,
            'teams'                => $teams,
            'active_page'          => 'user-stories',
            'has_evaluation_board' => Subscription::hasEvaluationBoard($this->db, $orgId),
            'flash_message'        => $_SESSION['flash_message'] ?? null,
            'flash_error'          => $_SESSION['flash_error']   ?? null,
        ], 'app');

        unset($_SESSION['flash_message'], $_SESSION['flash_error']);
    }

    /**
     * Generate user stories from selected HL work items via AI.
     *
     * For each selected HL item, sends title+description to Gemini
     * with DECOMPOSE_PROMPT, parses JSON, and creates UserStory records.
     */
    public function generate(): void
    {
        $user      = $this->auth->user();
        $orgId     = (int) $user['org_id'];
        $projectId = (int) $this->request->post('project_id', 0);

        $project = Project::findById($this->db, $projectId, $orgId);
        if ($project === null) {
            $this->response->redirect('/app/home');
            return;
        }

        $hlItemIds = $this->request->post('hl_item_ids', []);
        if (!is_array($hlItemIds) || empty($hlItemIds)) {
            $_SESSION['flash_error'] = 'Please select at least one work item to decompose.';
            $this->response->redirect('/app/user-stories?project_id=' . $projectId);
            return;
        }

        // Determine starting priority number
        $existingCount = UserStory::countByProjectId($this->db, $projectId);
        $priorityNumber = $existingCount + 1;

        $totalCreated = 0;

        foreach ($hlItemIds as $hlItemId) {
            $hlItem = HLWorkItem::findById($this->db, (int) $hlItemId);
            if ($hlItem === null || (int) $hlItem['project_id'] !== $projectId) {
                continue;
            }

            // Build input for AI
            $input = "## Work Item\nTitle: {$hlItem['title']}\n";
            if (!empty($hlItem['description'])) {
                $input .= "Description: {$hlItem['description']}\n";
            }
            if (!empty($hlItem['strategic_context'])) {
                $input .= "Strategic Context: {$hlItem['strategic_context']}\n";
            }

            try {
                $gemini     = new GeminiService($this->config);
                $storiesData = $gemini->generateJson(UserStoryPrompt::DECOMPOSE_PROMPT, $input);
            } catch (\RuntimeException $e) {
                $_SESSION['flash_error'] = 'AI decomposition failed for "' . $hlItem['title'] . '": ' . $e->getMessage();
                $this->response->redirect('/app/user-stories?project_id=' . $projectId);
                return;
            }

            if (!is_array($storiesData) || empty($storiesData)) {
                continue;
            }

            foreach ($storiesData as $storyData) {
                UserStory::create($this->db, [
                    'project_id'        => $projectId,
                    'parent_hl_item_id' => (int) $hlItemId,
                    'priority_number'   => $priorityNumber++,
                    'title'             => $storyData['title'] ?? 'Untitled Story',
                    'description'       => $storyData['description'] ?? null,
                    'size'              => isset($storyData['size']) ? (int) $storyData['size'] : null,
                ]);
                $totalCreated++;
            }
        }

        // Create governance queue item if a baseline exists
        $baseline = StrategicBaseline::findLatestByProjectId($this->db, $projectId);
        if ($baseline && $totalCreated > 0) {
            $parentTitles = [];
            foreach ($hlItemIds as $hlItemId) {
                $hlItem = HLWorkItem::findById($this->db, (int) $hlItemId);
                if ($hlItem) {
                    $parentTitles[] = $hlItem['title'];
                }
            }
            GovernanceItem::create($this->db, [
                'project_id' => $projectId,
                'change_type' => 'new_story',
                'proposed_change_json' => [
                    'stories_created' => $totalCreated,
                    'parent_items' => $parentTitles,
                ],
            ]);
        }

        $_SESSION['flash_message'] = "{$totalCreated} user stories generated successfully.";
        $this->response->redirect('/app/user-stories?project_id=' . $projectId);
    }

    /**
     * Create a new user story from manual form submission.
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

        $title = trim((string) $this->request->post('title', ''));
        if ($title === '') {
            $_SESSION['flash_error'] = 'Story title is required.';
            $this->response->redirect('/app/user-stories?project_id=' . $projectId);
            return;
        }

        $existingCount = UserStory::countByProjectId($this->db, $projectId);

        $parentHlItemId = $this->request->post('parent_hl_item_id', '');
        $blockedBy      = $this->request->post('blocked_by', '');
        $size           = $this->request->post('size', '');

        UserStory::create($this->db, [
            'project_id'        => $projectId,
            'parent_hl_item_id' => $parentHlItemId !== '' ? (int) $parentHlItemId : null,
            'priority_number'   => $existingCount + 1,
            'title'             => $title,
            'description'       => trim((string) $this->request->post('description', '')) ?: null,
            'team_assigned'     => trim((string) $this->request->post('team_assigned', '')) ?: null,
            'size'              => $size !== '' ? (int) $size : null,
            'blocked_by'        => $blockedBy !== '' ? (int) $blockedBy : null,
        ]);

        $_SESSION['flash_message'] = 'User story created.';
        $this->response->redirect('/app/user-stories?project_id=' . $projectId);
    }

    /**
     * Update a single user story's fields from form submission.
     *
     * @param int $id User story primary key (from route parameter)
     */
    public function update($id): void
    {
        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];

        $story = UserStory::findById($this->db, (int) $id);
        if ($story === null) {
            $this->response->redirect('/app/home');
            return;
        }

        $project = Project::findById($this->db, (int) $story['project_id'], $orgId);
        if ($project === null) {
            $this->response->redirect('/app/home');
            return;
        }

        $parentHlItemId = $this->request->post('parent_hl_item_id', '');
        $blockedBy      = $this->request->post('blocked_by', '');
        $size           = $this->request->post('size', '');

        $oldSize = (int) ($story['size'] ?? 0);
        $newSize = $size !== '' ? (int) $size : 0;

        UserStory::update($this->db, $id, [
            'title'             => trim((string) $this->request->post('title', $story['title'])),
            'description'       => trim((string) $this->request->post('description', $story['description'] ?? '')),
            'parent_hl_item_id' => $parentHlItemId !== '' ? (int) $parentHlItemId : null,
            'team_assigned'     => trim((string) $this->request->post('team_assigned', $story['team_assigned'] ?? '')),
            'size'              => $size !== '' ? (int) $size : null,
            'blocked_by'        => $blockedBy !== '' ? (int) $blockedBy : null,
        ]);

        // Flag parent work item for review if size changed significantly
        if ($oldSize > 0 && $newSize > 0 && abs($newSize - $oldSize) >= 2 && $story['parent_hl_item_id']) {
            HLWorkItem::update($this->db, (int) $story['parent_hl_item_id'], ['requires_review' => 1]);
        }

        $_SESSION['flash_message'] = 'User story updated.';
        $this->response->redirect('/app/user-stories?project_id=' . $story['project_id']);
    }

    /**
     * Delete a single user story and re-number remaining priorities.
     *
     * @param int $id User story primary key (from route parameter)
     */
    public function delete($id): void
    {
        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];

        $story = UserStory::findById($this->db, (int) $id);
        if ($story === null) {
            $this->response->redirect('/app/home');
            return;
        }

        $projectId = (int) $story['project_id'];
        $project   = Project::findById($this->db, $projectId, $orgId);
        if ($project === null) {
            $this->response->redirect('/app/home');
            return;
        }

        UserStory::delete($this->db, (int) $id);

        // Re-number remaining stories
        $remaining = UserStory::findByProjectId($this->db, $projectId);
        $updates   = [];
        foreach ($remaining as $index => $s) {
            $updates[] = ['id' => (int) $s['id'], 'priority_number' => $index + 1];
        }
        if (!empty($updates)) {
            UserStory::batchUpdatePriority($this->db, $updates);
        }

        $_SESSION['flash_message'] = 'User story deleted.';
        $this->response->redirect('/app/user-stories?project_id=' . $projectId);
    }

    /**
     * Reorder user stories via AJAX drag-and-drop.
     *
     * Accepts JSON body with an `order` array of {id, position} objects.
     * Returns JSON response.
     */
    public function reorder(): void
    {
        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];

        $body  = json_decode($this->request->body(), true);
        $order = $body['order'] ?? [];

        if (empty($order)) {
            $this->response->json(['status' => 'error', 'message' => 'No order data provided'], 400);
            return;
        }

        // Verify org access via the first item
        $firstStory = UserStory::findById($this->db, (int) $order[0]['id']);
        if ($firstStory === null) {
            $this->response->json(['status' => 'error', 'message' => 'Story not found'], 404);
            return;
        }

        $project = Project::findById($this->db, (int) $firstStory['project_id'], $orgId);
        if ($project === null) {
            $this->response->json(['status' => 'error', 'message' => 'Access denied'], 403);
            return;
        }

        $updates = [];
        foreach ($order as $entry) {
            $updates[] = [
                'id'              => (int) $entry['id'],
                'priority_number' => (int) $entry['position'],
            ];
        }

        UserStory::batchUpdatePriority($this->db, $updates);

        $this->response->json(['status' => 'ok']);
    }

    /**
     * AI-suggest story point size for a user story (AJAX).
     *
     * Loads the story, builds SIZE_PROMPT with substitutions,
     * calls Gemini, and returns JSON with size and reasoning.
     *
     * @param int $id User story primary key (from route parameter)
     */
    public function suggestSize($id): void
    {
        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];

        $story = UserStory::findById($this->db, (int) $id);
        if ($story === null) {
            $this->response->json(['status' => 'error', 'message' => 'Story not found'], 404);
            return;
        }

        $project = Project::findById($this->db, (int) $story['project_id'], $orgId);
        if ($project === null) {
            $this->response->json(['status' => 'error', 'message' => 'Access denied'], 403);
            return;
        }

        $prompt = str_replace(
            ['{title}', '{description}'],
            [$story['title'], $story['description'] ?? 'No description provided'],
            UserStoryPrompt::SIZE_PROMPT
        );

        try {
            $gemini = new GeminiService($this->config);
            $result = $gemini->generateJson($prompt, '');
        } catch (\RuntimeException $e) {
            $this->response->json(['status' => 'error', 'message' => $e->getMessage()], 500);
            return;
        }

        $size      = (int) ($result['size'] ?? 3);
        $reasoning = $result['reasoning'] ?? '';

        // Save the suggested size
        UserStory::update($this->db, $id, ['size' => $size]);

        $this->response->json(['status' => 'ok', 'size' => $size, 'reasoning' => $reasoning]);
    }

    /**
     * Re-estimate story point sizes for all user stories in a project via AI.
     *
     * Sends story titles and descriptions to Gemini with a sizing prompt and
     * batch-updates the size field for each returned story.
     */
    public function regenerateSizing(): void
    {
        $user      = $this->auth->user();
        $orgId     = (int) $user['org_id'];
        $projectId = (int) $this->request->post('project_id', 0);

        $project = Project::findById($this->db, $projectId, $orgId);
        if ($project === null) {
            $this->response->redirect('/app/home');
            return;
        }

        $stories = UserStory::findByProjectId($this->db, $projectId);
        if (empty($stories)) {
            $_SESSION['flash_error'] = 'No user stories to size.';
            $this->response->redirect('/app/user-stories?project_id=' . $projectId);
            return;
        }

        // Build prompt for all stories in one call
        $sizingPrompt = <<<'PROMPT'
You are an Agile estimation expert. For each user story below, estimate the story point size using the modified Fibonacci scale: 1, 2, 3, 5, 8, 13.

Return a JSON array where each element has: "id" (integer), "size" (integer from the Fibonacci scale).

User stories:
PROMPT;

        $itemLines = [];
        foreach ($stories as $story) {
            $line = "- ID {$story['id']}: {$story['title']}";
            if (!empty($story['description'])) {
                $line .= ' — ' . mb_substr($story['description'], 0, 100);
            }
            $itemLines[] = $line;
        }
        $input = implode("\n", $itemLines);

        try {
            $gemini  = new GeminiService($this->config);
            $results = $gemini->generateJson($sizingPrompt, $input);
        } catch (\RuntimeException $e) {
            $_SESSION['flash_error'] = 'Sizing regeneration failed: ' . $e->getMessage();
            $this->response->redirect('/app/user-stories?project_id=' . $projectId);
            return;
        }

        if (!is_array($results) || empty($results)) {
            $_SESSION['flash_error'] = 'AI returned an unexpected format. Please try again.';
            $this->response->redirect('/app/user-stories?project_id=' . $projectId);
            return;
        }

        // Build a lookup of allowed IDs for security
        $allowedIds    = array_column($stories, 'id');
        $validSizes    = [1, 2, 3, 5, 8, 13];
        $updatedCount  = 0;

        foreach ($results as $result) {
            $storyId = (int) ($result['id'] ?? 0);
            $size    = (int) ($result['size'] ?? 3);

            // Snap to nearest valid Fibonacci value
            if (!in_array($size, $validSizes, true)) {
                $closest = $validSizes[0];
                foreach ($validSizes as $v) {
                    if (abs($v - $size) < abs($closest - $size)) {
                        $closest = $v;
                    }
                }
                $size = $closest;
            }

            if (in_array($storyId, $allowedIds, true)) {
                UserStory::update($this->db, $storyId, ['size' => $size]);
                $updatedCount++;
            }
        }

        $_SESSION['flash_message'] = "Story point sizing regenerated for {$updatedCount} user stories.";
        $this->response->redirect('/app/user-stories?project_id=' . $projectId);
    }

    /**
     * Export user stories as CSV, JSON, or Jira-compatible CSV download.
     *
     * Reads format from query string and sends the appropriate file.
     */
    public function export(): void
    {
        $user      = $this->auth->user();
        $orgId     = (int) $user['org_id'];
        $projectId = (int) $this->request->get('project_id', 0);
        $format    = strtolower((string) $this->request->get('format', 'csv'));

        $project = Project::findById($this->db, $projectId, $orgId);
        if ($project === null) {
            $this->response->redirect('/app/home');
            return;
        }

        $stories  = UserStory::findByProjectId($this->db, $projectId);
        $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $project['name']);

        if ($format === 'json') {
            $exportData = array_map(function ($story) {
                return [
                    'priority'      => (int) $story['priority_number'],
                    'title'         => $story['title'],
                    'description'   => $story['description'] ?? '',
                    'parent_item'   => $story['parent_title'] ?? '',
                    'team_assigned' => $story['team_assigned'] ?? '',
                    'size'          => $story['size'] !== null ? (int) $story['size'] : null,
                    'blocked_by'    => $story['blocked_by'] !== null ? (int) $story['blocked_by'] : null,
                ];
            }, $stories);

            $content = json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $this->response->download($content, $safeName . '_user_stories.json', 'application/json');
            return;
        }

        if ($format === 'jira') {
            // Jira-compatible CSV
            $handle = fopen('php://temp', 'r+');
            fputcsv($handle, ['Summary', 'Description', 'Issue Type', 'Epic Link', 'Story Points']);

            foreach ($stories as $story) {
                fputcsv($handle, [
                    $story['title'],
                    $story['description'] ?? '',
                    'Story',
                    $story['parent_title'] ?? '',
                    $story['size'] ?? '',
                ]);
            }

            rewind($handle);
            $content = stream_get_contents($handle);
            fclose($handle);

            $this->response->download($content, $safeName . '_user_stories_jira.csv', 'text/csv');
            return;
        }

        // Default: CSV
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['Priority', 'Title', 'Description', 'Parent Work Item', 'Team', 'Size', 'Blocked By']);

        foreach ($stories as $story) {
            fputcsv($handle, [
                $story['priority_number'],
                $story['title'],
                $story['description'] ?? '',
                $story['parent_title'] ?? '',
                $story['team_assigned'] ?? '',
                $story['size'] ?? '',
                $story['blocked_by'] ?? '',
            ]);
        }

        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        $this->response->download($content, $safeName . '_user_stories.csv', 'text/csv');
    }
}
