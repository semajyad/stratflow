<?php
/**
 * UserStoryController
 *
 * Handles the User Stories page: AI decomposition from High Level work items,
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
use StratFlow\Models\StoryGitLink;
use StratFlow\Models\Subscription;
use StratFlow\Models\UserStory;
use StratFlow\Models\GovernanceItem;
use StratFlow\Models\StrategicBaseline;
use StratFlow\Models\StoryQualityConfig;
use StratFlow\Security\ProjectPolicy;
use StratFlow\Services\GeminiService;
use StratFlow\Services\StoryQualityScorer;
use StratFlow\Services\StoryImprovementService;
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
     * Loads stories with parent High Level titles, and High Level items for the
     * split-selection checkboxes.
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

        $stories   = UserStory::findByProjectId($this->db, $projectId);
        $workItems = HLWorkItem::findByProjectId($this->db, $projectId);
        $teams     = \StratFlow\Models\Team::findByOrgId($this->db, $orgId);
        $orgUsers  = \StratFlow\Models\User::findByOrgId($this->db, $orgId);

        // Inject git link counts in bulk to avoid N+1 queries
        $storyIds   = array_column($stories, 'id');
        $gitCounts  = StoryGitLink::countsByLocalIds($this->db, 'user_story', array_map('intval', $storyIds));
        foreach ($stories as &$story) {
            $story['git_link_count'] = $gitCounts[(int) $story['id']] ?? 0;
        }
        unset($story);

        // Load field order preference from org settings
        $orgRow = \StratFlow\Models\Organisation::findById($this->db, $orgId);
        $orgSettings = $orgRow && !empty($orgRow['settings_json'])
            ? (json_decode($orgRow['settings_json'], true) ?? []) : [];
        $defaultStOrder = ['title','description','parent_hl_item_id','team_assigned','size','acceptance_criteria','kr_hypothesis','blocked_by','git_links'];
        $fieldOrderSt = $orgSettings['field_order_story'] ?? $defaultStOrder;

        // Quality visibility: off when disabled at system level OR at org level
        $systemSettings = \StratFlow\Models\SystemSettings::get($this->db);
        $showQuality    = !empty($systemSettings['feature_story_quality'])
                          && ($orgSettings['quality']['enabled'] ?? false);

        $this->response->render('user-stories', [
            'user'                 => $user,
            'project'              => $project,
            'stories'              => $stories,
            'work_items'           => $workItems,
            'teams'                => $teams,
            'org_users'            => $orgUsers,
            'field_order_st'       => $fieldOrderSt,
            'showQuality'          => $showQuality,
            'active_page'          => 'user-stories',
            'has_evaluation_board' => Subscription::hasEvaluationBoard($this->db, $orgId),
            'flash_message'        => $_SESSION['flash_message'] ?? null,
            'flash_error'          => $_SESSION['flash_error']   ?? null,
        ], 'app');

        unset($_SESSION['flash_message'], $_SESSION['flash_error']);
    }

    /**
     * Generate user stories from selected High Level work items via AI.
     *
     * For each selected High Level item, sends title+description to Gemini
     * with DECOMPOSE_PROMPT, parses JSON, and creates UserStory records.
     */
    public function generate(): void
    {
        $user      = $this->auth->user();
        $orgId     = (int) $user['org_id'];
        $projectId = (int) $this->request->post('project_id', 0);

        $project = ProjectPolicy::findEditableProject($this->db, $user, $projectId);
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

            // Inject KR data from the work item's key results
            try {
                $krRows = $this->db->query(
                    "SELECT title, current_value, target_value, unit
                       FROM key_results
                      WHERE hl_work_item_id = :wid",
                    [':wid' => (int) $hlItemId]
                )->fetchAll();
                if (!empty($krRows)) {
                    $input .= "\n--- KEY RESULTS ---\n";
                    foreach ($krRows as $kr) {
                        $input .= "- {$kr['title']}: current={$kr['current_value']}, target={$kr['target_value']} {$kr['unit']}\n";
                    }
                    $input .= "-------------------\n";
                }
            } catch (\Throwable) {
                // key_results may not exist on a fresh deploy
            }

            // Inject org quality rules
            $qualityBlock = '';
            try {
                $qualityBlock = StoryQualityConfig::buildPromptBlock($this->db, $orgId);
            } catch (\Throwable) {
                // story_quality_config may not exist on a fresh deploy
            }
            $input .= $qualityBlock;

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
                // Normalise acceptance_criteria to newline-delimited string
                $acRaw = $storyData['acceptance_criteria'] ?? null;
                $ac = null;
                if (is_array($acRaw)) {
                    $ac = implode("\n", $acRaw);
                } elseif (is_string($acRaw) && $acRaw !== '') {
                    $ac = $acRaw;
                }

                $newStoryId = UserStory::create($this->db, [
                    'project_id'          => $projectId,
                    'parent_hl_item_id'   => (int) $hlItemId,
                    'priority_number'     => $priorityNumber++,
                    'title'               => $storyData['title'] ?? 'Untitled Story',
                    'description'         => $storyData['description'] ?? null,
                    'team_assigned'       => $hlItem['team_assigned'] ?? null,
                    'size'                => isset($storyData['size']) ? (int) $storyData['size'] : null,
                    'acceptance_criteria' => $ac,
                    'kr_hypothesis'       => isset($storyData['kr_hypothesis']) && $storyData['kr_hypothesis'] !== ''
                                             ? mb_substr((string) $storyData['kr_hypothesis'], 0, 500)
                                             : null,
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

        $project = ProjectPolicy::findEditableProject($this->db, $user, $projectId);
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

        // Inherit team from parent work item if one is selected
        $resolvedParentId = $parentHlItemId !== '' ? (int) $parentHlItemId : null;
        $teamAssigned = trim((string) $this->request->post('team_assigned', '')) ?: null;
        if ($resolvedParentId !== null) {
            $parentItem = HLWorkItem::findById($this->db, $resolvedParentId);
            if ($parentItem && !empty($parentItem['team_assigned'])) {
                $teamAssigned = $parentItem['team_assigned'];
            }
        }

        UserStory::create($this->db, [
            'project_id'        => $projectId,
            'parent_hl_item_id' => $resolvedParentId,
            'priority_number'   => $existingCount + 1,
            'title'             => $title,
            'description'       => trim((string) $this->request->post('description', '')) ?: null,
            'team_assigned'     => $teamAssigned,
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

        $project = ProjectPolicy::findEditableProject($this->db, $user, (int) $story['project_id']);
        if ($project === null) {
            $this->response->redirect('/app/home');
            return;
        }

        $parentHlItemId = $this->request->post('parent_hl_item_id', '');
        $blockedBy      = $this->request->post('blocked_by', '');
        $size           = $this->request->post('size', '');
        $assigneeRaw    = $this->request->post('assignee_user_id', null);

        $oldSize = (int) ($story['size'] ?? 0);
        $newSize = $size !== '' ? (int) $size : 0;

        // Validate assignee belongs to this org before accepting
        $assigneeUserId = null;
        if ($assigneeRaw !== null && $assigneeRaw !== '') {
            $assigneeId = (int) $assigneeRaw;
            $assigneeCheck = $this->db->query(
                'SELECT id FROM users WHERE id = :id AND org_id = :org_id LIMIT 1',
                [':id' => $assigneeId, ':org_id' => $orgId]
            )->fetch();
            if ($assigneeCheck) {
                $assigneeUserId = $assigneeId;
            }
        }

        // Inherit team from parent work item if one is set.
        // Fall back to the story's existing parent if the form didn't send one.
        $resolvedParentId = $parentHlItemId !== ''
            ? (int) $parentHlItemId
            : ((int) ($story['parent_hl_item_id'] ?? 0) ?: null);

        $inheritedTeam = null;
        if ($resolvedParentId !== null) {
            $parent = HLWorkItem::findById($this->db, $resolvedParentId);
            if ($parent && !empty($parent['team_assigned'])) {
                $inheritedTeam = $parent['team_assigned'];
            }
        }

        $storyUpdateData = [
            'title'               => trim((string) $this->request->post('title', $story['title'])),
            'description'         => trim((string) $this->request->post('description', $story['description'] ?? '')),
            'parent_hl_item_id'   => $resolvedParentId,
            'size'                => $size !== '' ? (int) $size : null,
            'blocked_by'          => $blockedBy !== '' ? (int) $blockedBy : null,
            'acceptance_criteria' => trim((string) $this->request->post('acceptance_criteria', $story['acceptance_criteria'] ?? '')) ?: null,
            'kr_hypothesis'       => mb_substr(
                trim((string) $this->request->post('kr_hypothesis', $story['kr_hypothesis'] ?? '')),
                0, 500
            ) ?: null,
        ];

        // Only include columns that exist on this deployment
        if (array_key_exists('team_assigned', $story)) {
            $storyUpdateData['team_assigned'] = $inheritedTeam ?? trim((string) $this->request->post('team_assigned', $story['team_assigned'] ?? ''));
        }
        if (array_key_exists('assignee_user_id', $story)) {
            $storyUpdateData['assignee_user_id'] = $assigneeUserId;
        }

        UserStory::update($this->db, (int) $id, $storyUpdateData);

        // Enqueue for async quality scoring — the background worker will score shortly
        UserStory::markQualityPending($this->db, (int) $id);

        // Flag parent work item for review if size changed significantly
        if ($oldSize > 0 && $newSize > 0 && abs($newSize - $oldSize) >= 2 && $story['parent_hl_item_id']) {
            HLWorkItem::update($this->db, (int) $story['parent_hl_item_id'], ['requires_review' => 1]);
        }

        $_SESSION['flash_message'] = 'User story updated.';
        $this->response->redirect('/app/user-stories?project_id=' . $story['project_id']);
    }

    /**
     * Improve a user story's low-scoring fields using AI, then re-score.
     * If quality_score is null, scores first then improves in one request.
     *
     * @param int $id User story primary key (from route parameter)
     */
    public function improve(int $id): void
    {
        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];

        $story = UserStory::findById($this->db, (int) $id);
        if ($story === null) {
            $this->response->redirect('/app/home');
            return;
        }

        $project = ProjectPolicy::findEditableProject($this->db, $user, (int) $story['project_id']);
        if ($project === null) {
            $this->response->redirect('/app/home');
            return;
        }

        $qualityBlock = '';
        try {
            $qualityBlock = StoryQualityConfig::buildPromptBlock($this->db, $orgId);
        } catch (\Throwable) {}

        // Score first if not yet scored — improvement needs the breakdown
        if ($story['quality_score'] === null) {
            $scorer = new StoryQualityScorer(new GeminiService($this->config));
            $scored = $scorer->scoreStory($story, $qualityBlock);
            if ($scored['score'] !== null) {
                UserStory::markQualityScored($this->db, (int) $id, $scored['score'], $scored['breakdown']);
                $story = UserStory::findById($this->db, (int) $id);
            } else {
                UserStory::markQualityFailed(
                    $this->db,
                    (int) $id,
                    (int) ($story['quality_attempts'] ?? 0) + 1,
                    $scored['error'] ?? 'unknown'
                );
            }
        }

        // Decode breakdown — if still null after scoring attempt, nothing to improve
        $breakdown = null;
        if (!empty($story['quality_breakdown'])) {
            $breakdown = json_decode((string) $story['quality_breakdown'], true);
        }

        if ($breakdown === null) {
            $this->response->redirect('/app/user-stories?project_id=' . (int) $story['project_id'] . '&improved=0');
            return;
        }

        // Improve fields that score below 80% of their max
        $improver       = new StoryImprovementService(new GeminiService($this->config));
        $improvedFields = $improver->improveStory($story, $breakdown, $qualityBlock);

        if (empty($improvedFields)) {
            $this->response->redirect('/app/user-stories?project_id=' . (int) $story['project_id'] . '&improved=0');
            return;
        }

        UserStory::update($this->db, (int) $id, $improvedFields);

        // Re-score with the improved content — enqueue if Gemini is unavailable
        $storyForScore = array_merge($story, $improvedFields);
        $scorer        = new StoryQualityScorer(new GeminiService($this->config));
        $scored        = $scorer->scoreStory($storyForScore, $qualityBlock);
        if ($scored['score'] !== null) {
            UserStory::markQualityScored($this->db, (int) $id, $scored['score'], $scored['breakdown']);
        } else {
            UserStory::markQualityPending($this->db, (int) $id);
        }

        $this->response->redirect('/app/user-stories?project_id=' . (int) $story['project_id'] . '&improved=1');
    }

    /**
     * Refine quality for a single story (no confirmation prompt — field-scoped, title-safe).
     */
    public function refineQuality($id): void
    {
        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];

        $story = UserStory::findById($this->db, (int) $id);
        if ($story === null) {
            $this->response->redirect('/app/home');
            return;
        }

        $project = ProjectPolicy::findEditableProject($this->db, $user, (int) $story['project_id']);
        if ($project === null) {
            $this->response->redirect('/app/home');
            return;
        }

        // Mark pending and redirect immediately — the async worker handles improvement + re-score
        UserStory::markQualityPending($this->db, (int) $id);
        $this->response->redirect('/app/user-stories?project_id=' . (int) $story['project_id']);
    }

    /**
     * Refine quality for all stories in a project that score below 80 (up to 50 rows).
     */
    public function refineAll(): void
    {
        $user      = $this->auth->user();
        $orgId     = (int) $user['org_id'];
        $projectId = (int) $this->request->post('project_id', 0);

        $project = ProjectPolicy::findEditableProject($this->db, $user, $projectId);
        if ($project === null) {
            $this->response->redirect('/app/home');
            return;
        }

        $rows = $this->db->query(
            "SELECT s.id FROM user_stories s
             JOIN projects p ON p.id = s.project_id
             WHERE s.project_id = :pid
               AND p.org_id = :oid
               AND s.quality_status = 'scored'
               AND s.quality_score < 80
             ORDER BY s.quality_score ASC
             LIMIT 50",
            [':pid' => $projectId, ':oid' => $orgId]
        )->fetchAll();

        $refined = 0;
        foreach ($rows as $story) {
            UserStory::markQualityPending($this->db, (int) $story['id']);
            $refined++;
        }

        if ($refined > 0) {
            $_SESSION['flash_message'] = "Refined {$refined} " . ($refined === 1 ? 'story' : 'stories') . " — new scores in 1–2 min.";
        } else {
            $_SESSION['flash_message'] = 'All stories already meet the quality threshold.';
        }

        $this->response->redirect('/app/user-stories?project_id=' . $projectId);
    }

    /**
     * Close a user story (sets status to 'closed').
     */
    public function close($id): void
    {
        $user      = $this->auth->user();
        $orgId     = (int) $user['org_id'];
        $storyId   = (int) $id;

        $story = UserStory::findById($this->db, $storyId);
        if ($story === null) {
            $this->response->redirect('/app/home');
            return;
        }

        $project = ProjectPolicy::findEditableProject($this->db, $user, (int) $story['project_id']);
        if ($project === null) {
            $this->response->redirect('/app/home');
            return;
        }

        UserStory::update($this->db, $storyId, ['status' => 'closed']);

        $this->response->redirect('/app/user-stories?project_id=' . (int) $story['project_id']);
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
        $project   = ProjectPolicy::findEditableProject($this->db, $user, $projectId);
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

        $project = ProjectPolicy::findEditableProject($this->db, $user, (int) $firstStory['project_id']);
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

        $project = ProjectPolicy::findEditableProject($this->db, $user, (int) $story['project_id']);
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
        UserStory::update($this->db, (int) $id, ['size' => $size]);

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

        $project = ProjectPolicy::findEditableProject($this->db, $user, $projectId);
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

        $project = ProjectPolicy::findViewableProject($this->db, $user, $projectId);
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

    /**
     * AJAX endpoint to calculate and update the quality score for a story.
     *
     * @param int $id User story primary key
     */
    public function score(string $id): void
    {
        $id    = (int) $id;
        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];

        $story = \StratFlow\Models\UserStory::findById($this->db, $id);
        if ($story === null) {
            $this->response->json(['status' => 'error', 'message' => 'Story not found'], 404);
            return;
        }

        $project = \StratFlow\Models\ProjectPolicy::findEditableProject($this->db, $user, (int) $story['project_id']);
        if ($project === null) {
            $this->response->json(['status' => 'error', 'message' => 'Access denied'], 403);
            return;
        }

        // Verify that BOTH Super Admin and Admin have enabled quality scores
        $systemSettings = \StratFlow\Models\SystemSettings::get($this->db);
        $orgRow         = \StratFlow\Models\Organisation::findById($this->db, $orgId);
        $orgSettings    = $orgRow && !empty($orgRow['settings_json'])
            ? (json_decode($orgRow['settings_json'], true) ?? []) : [];
        
        $showQuality = !empty($systemSettings['feature_story_quality'])
                       && ($orgSettings['quality']['enabled'] ?? false);

        if (!$showQuality) {
            $this->response->json(['status' => 'error', 'message' => 'Quality scoring is disabled'], 403);
            return;
        }

        $qualityBlock = '';
        try {
            $qualityBlock = \StratFlow\Models\StoryQualityConfig::buildPromptBlock($this->db, $orgId);
        } catch (\Throwable) {}

        $scorer = new \StratFlow\Services\StoryQualityScorer(new \StratFlow\Services\GeminiService($this->config));
        $scored = $scorer->scoreStory($story, $qualityBlock);

        if ($scored['score'] !== null) {
            \StratFlow\Models\UserStory::markQualityScored($this->db, $id, $scored['score'], $scored['breakdown']);

            $html = '';
            ob_start();
            $breakdownData = $scored['breakdown'];
            $itemId        = $id;
            $itemType      = 'story';
            $csrf_token    = $this->request->post('_csrf_token');
            require __DIR__ . '/../../templates/partials/quality-breakdown.php';
            $html = ob_get_clean();

            $this->response->json([
                'status'    => 'ok',
                'score'     => $scored['score'],
                'breakdown' => $scored['breakdown'],
                'html'      => $html
            ]);
            return;
        }

        // Scoring failed — update state and surface the error
        $errorKey = $scored['error'] ?? 'unknown';
        \StratFlow\Models\UserStory::markQualityFailed(
            $this->db,
            $id,
            (int) ($story['quality_attempts'] ?? 0) + 1,
            $errorKey
        );
        error_log('[StratFlow] UserStory score error: ' . $errorKey);
        $this->response->json(['status' => 'error', 'message' => 'Scoring failed: ' . $errorKey], 503);
    }

    /**
     * Delete all user stories for a project.
     *
     * Requires project_id POST param. Verifies org ownership before deleting.
     */
    public function deleteAll(): void
    {
        $user      = $this->auth->user();
        $orgId     = (int) $user['org_id'];
        $projectId = (int) $this->request->post('project_id', 0);

        if ($projectId === 0) {
            $this->response->redirect('/app/home');
            return;
        }

        $project = ProjectPolicy::findEditableProject($this->db, $user, $projectId);
        if ($project === null) {
            $this->response->redirect('/app/home');
            return;
        }

        UserStory::deleteByProjectId($this->db, $projectId);

        $_SESSION['flash_message'] = 'All user stories deleted.';
        $this->response->redirect('/app/user-stories?project_id=' . $projectId);
    }
}
