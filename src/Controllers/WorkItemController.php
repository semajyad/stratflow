<?php
/**
 * WorkItemController
 *
 * Handles the High-Level Work Items (High Level Work Item) page: AI generation from
 * strategy diagrams, drag-and-drop reordering, CRUD operations,
 * AI description generation, and CSV/JSON export.
 */

declare(strict_types=1);

namespace StratFlow\Controllers;

use StratFlow\Core\Auth;
use StratFlow\Core\Database;
use StratFlow\Core\Request;
use StratFlow\Core\Response;
use StratFlow\Services\AuditLogger;
use StratFlow\Models\DiagramNode;
use StratFlow\Models\Document;
use StratFlow\Models\HLItemDependency;
use StratFlow\Models\HLWorkItem;
use StratFlow\Models\KeyResult;
use StratFlow\Models\Project;
use StratFlow\Models\StoryGitLink;
use StratFlow\Models\StrategyDiagram;
use StratFlow\Models\Subscription;
use StratFlow\Models\StoryQualityConfig;
use StratFlow\Security\ProjectPolicy;
use StratFlow\Services\GeminiService;
use StratFlow\Services\StoryQualityScorer;
use StratFlow\Services\StoryImprovementService;
use StratFlow\Services\Prompts\WorkItemPrompt;

class WorkItemController
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
     * Render the work items page for a specific project.
     *
     * Loads work items ordered by priority, the diagram for a mini
     * thumbnail, and renders the work-items template.
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

        $workItems = HLWorkItem::findByProjectId($this->db, $projectId);
        $diagram   = StrategyDiagram::findByProjectId($this->db, $projectId);

        // Attach dependency data to each work item for the template
        foreach ($workItems as &$item) {
            $deps = HLItemDependency::findByItemId($this->db, (int) $item['id']);
            $item['dependencies']       = $deps;
            $item['dependency_titles']  = implode(', ', array_column($deps, 'depends_on_title'));
        }
        unset($item);

        // Inject git link counts in bulk to avoid N+1 queries
        $itemIds   = array_column($workItems, 'id');
        $gitCounts = StoryGitLink::countsByLocalIds($this->db, 'hl_work_item', array_map('intval', $itemIds));
        foreach ($workItems as &$item) {
            $item['git_link_count'] = $gitCounts[(int) $item['id']] ?? 0;
        }
        unset($item);

        // Build KR map: item_id => [kr rows] — single bulk query to avoid N+1
        $workItemIds = array_map('intval', array_column($workItems, 'id'));
        try {
            $krsByItemId = KeyResult::findByWorkItemIds($this->db, $workItemIds, $orgId);
        } catch (\Throwable $e) {
            // Graceful degradation if key_results table is missing (pending migration)
            $krsByItemId = [];
            error_log('[WorkItems] KR lookup failed: ' . $e->getMessage());
        }

        // Distinct non-empty OKR titles for the modal datalist
        $distinctOkrTitles = array_values(array_filter(
            array_unique(array_column($workItems, 'okr_title')),
            fn($t) => $t !== null && $t !== ''
        ));

        // Load org settings (field order, sizing method, sprint length)
        $orgRow = \StratFlow\Models\Organisation::findById($this->db, $orgId);
        $orgSettings = $orgRow && !empty($orgRow['settings_json'])
            ? (json_decode($orgRow['settings_json'], true) ?? []) : [];
        $defaultWiOrder = ['title','okr_title','okr_description','owner','estimated_sprints','description','acceptance_criteria','kr_hypothesis','git_links'];
        $fieldOrderWi       = $orgSettings['field_order_work_item'] ?? $defaultWiOrder;
        $hlSizingMethod     = $orgSettings['hl_item_sizing_method'] ?? 'sprints';
        $sprintLengthWeeks  = (int) ($orgSettings['sprint_length_weeks'] ?? 2);

        // Quality visibility: off when disabled at system level OR at org level
        $systemSettings = \StratFlow\Models\SystemSettings::get($this->db);
        $showQuality    = !empty($systemSettings['feature_story_quality'])
                          && ($orgSettings['quality']['enabled'] ?? false);

        $teams = \StratFlow\Models\Team::findByOrgId($this->db, $orgId);

        $this->response->render('work-items', [
            'user'                 => $user,
            'project'              => $project,
            'work_items'           => $workItems,
            'krs_by_item_id'       => $krsByItemId,
            'diagram'              => $diagram,
            'distinct_okr_titles'  => $distinctOkrTitles,
            'teams'                => $teams,
            'field_order_wi'       => $fieldOrderWi,
            'hl_sizing_method'     => $hlSizingMethod,
            'sprint_length_weeks'  => $sprintLengthWeeks,
            'showQuality'          => $showQuality,
            'active_page'          => 'work-items',
            'has_evaluation_board' => Subscription::hasEvaluationBoard($this->db, $orgId),
            'flash_message'        => $_SESSION['flash_message'] ?? null,
            'flash_error'          => $_SESSION['flash_error']   ?? null,
        ], 'app');

        unset($_SESSION['flash_message'], $_SESSION['flash_error']);
    }

    /**
     * Generate work items from the project's strategy diagram via AI.
     *
     * Loads the diagram (mermaid code + nodes with OKRs) and document
     * summary, sends them to Gemini, deletes existing work items, and
     * creates new ones from the AI response.
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

        // Load diagram and nodes
        $diagram = StrategyDiagram::findByProjectId($this->db, $projectId);
        if ($diagram === null) {
            $_SESSION['flash_error'] = 'No strategy diagram found. Please generate a diagram first.';
            $this->response->redirect('/app/diagram?project_id=' . $projectId);
            return;
        }

        $nodes = DiagramNode::findByDiagramId($this->db, (int) $diagram['id']);

        // Load document summary
        $documents      = Document::findByProjectId($this->db, $projectId);
        $documentSummary = '';
        foreach ($documents as $doc) {
            if (!empty($doc['ai_summary'])) {
                $documentSummary = $doc['ai_summary'];
                break;
            }
        }

        // Build combined input for AI
        $input = $this->buildGenerationInput($diagram, $nodes, $documentSummary);

        // Inject org quality rules (splitting patterns + mandatory conditions)
        $qualityBlock = '';
        try {
            $qualityBlock = StoryQualityConfig::buildPromptBlock($this->db, $orgId);
        } catch (\Throwable) {
            // Table may not exist on a fresh deploy — proceed without config
        }
        $input .= $qualityBlock;

        // Inject KR data so AI can generate accurate kr_hypothesis values
        try {
            $krRows = $this->db->query(
                "SELECT kr.title, kr.current_value, kr.target_value, kr.unit
                   FROM key_results kr
                   JOIN hl_work_items hwi ON kr.hl_work_item_id = hwi.id
                  WHERE hwi.project_id = :pid",
                [':pid' => $projectId]
            )->fetchAll();
            if (!empty($krRows)) {
                $input .= "\n--- KEY RESULTS ---\n";
                foreach ($krRows as $kr) {
                    $input .= "- {$kr['title']}: current={$kr['current_value']}, target={$kr['target_value']} {$kr['unit']}\n";
                }
                $input .= "-------------------\n";
            }
        } catch (\Throwable) {
            // key_results table may not exist on a fresh deploy — proceed without KR data
        }

        // Generate work items via Gemini
        try {
            $gemini    = new GeminiService($this->config);
            $itemsData = $gemini->generateJson(WorkItemPrompt::PROMPT, $input);
        } catch (\RuntimeException $e) {
            $_SESSION['flash_error'] = 'Work item generation failed: ' . $e->getMessage();
            $this->response->redirect('/app/work-items?project_id=' . $projectId);
            return;
        }

        // Validate response is an array
        if (!is_array($itemsData) || empty($itemsData)) {
            $_SESSION['flash_error'] = 'AI returned an unexpected format. Please try again.';
            $this->response->redirect('/app/work-items?project_id=' . $projectId);
            return;
        }

        // Delete existing work items and create new ones
        HLWorkItem::deleteByProjectId($this->db, $projectId);

        // First pass: create all items and build a map of priority_number => new DB id
        // Quality scoring is deferred — scores are null until first update or "Improve with AI"
        $priorityToId = [];
        foreach ($itemsData as $index => $item) {
            $priorityNumber = (int) ($item['priority_number'] ?? ($index + 1));
            // Normalise acceptance_criteria: AI may return array or newline-delimited string
            $acRaw = $item['acceptance_criteria'] ?? null;
            $ac = null;
            if (is_array($acRaw)) {
                $ac = implode("\n", $acRaw);
            } elseif (is_string($acRaw) && $acRaw !== '') {
                $ac = $acRaw;
            }

            $newId = HLWorkItem::create($this->db, [
                'project_id'          => $projectId,
                'diagram_id'          => (int) $diagram['id'],
                'priority_number'     => $priorityNumber,
                'title'               => $item['title'] ?? 'Untitled Work Item',
                'description'         => $item['description'] ?? null,
                'strategic_context'   => $item['strategic_context'] ?? null,
                'okr_title'           => $item['okr_title'] ?? null,
                'okr_description'     => $item['okr_description'] ?? null,
                'estimated_sprints'   => $item['estimated_sprints'] ?? 2,
                'acceptance_criteria' => $ac,
                'kr_hypothesis'       => isset($item['kr_hypothesis']) && $item['kr_hypothesis'] !== ''
                                         ? mb_substr((string) $item['kr_hypothesis'], 0, 500)
                                         : null,
            ]);

            $priorityToId[$priorityNumber] = $newId;
        }

        // Score each new item immediately so the user sees real scores on page load.
        // One improvement pass if score <80 (capped to prevent latency blowout).
        try {
            $geminiSvc = new GeminiService($this->config);
            $scorer    = new StoryQualityScorer($geminiSvc);
            $improver  = new StoryImprovementService($geminiSvc);
            foreach ($priorityToId as $newId) {
                $freshItem = HLWorkItem::findById($this->db, $newId);
                if ($freshItem === null) {
                    continue;
                }
                $scored = $scorer->scoreWorkItem($freshItem, $qualityBlock);
                if ($scored['score'] !== null) {
                    if ($scored['score'] < 80) {
                        $improvedFields = $improver->improveWorkItem($freshItem, $scored['breakdown'], $qualityBlock);
                        if (!empty($improvedFields)) {
                            HLWorkItem::update($this->db, $newId, $improvedFields);
                            $freshItem = array_merge($freshItem, $improvedFields);
                        }
                        $scored2 = $scorer->scoreWorkItem($freshItem, $qualityBlock);
                        if ($scored2['score'] !== null) {
                            HLWorkItem::markQualityScored($this->db, $newId, $scored2['score'], $scored2['breakdown']);
                        } else {
                            HLWorkItem::markQualityScored($this->db, $newId, $scored['score'], $scored['breakdown']);
                        }
                    } else {
                        HLWorkItem::markQualityScored($this->db, $newId, $scored['score'], $scored['breakdown']);
                    }
                }
                // On failure, quality_status stays 'pending' — the async worker will retry
            }
        } catch (\Throwable) {
            // Non-fatal: worker will pick up any unscored items on its next tick
        }

        // Second pass: create dependency records using the priority → id map
        foreach ($itemsData as $index => $item) {
            $priorityNumber  = (int) ($item['priority_number'] ?? ($index + 1));
            $itemId          = $priorityToId[$priorityNumber] ?? null;
            $depPriorities   = $item['dependencies'] ?? [];

            if ($itemId === null || empty($depPriorities) || !is_array($depPriorities)) {
                continue;
            }

            $dependsOnIds = [];
            foreach ($depPriorities as $depPriority) {
                $depId = $priorityToId[(int) $depPriority] ?? null;
                if ($depId !== null) {
                    $dependsOnIds[] = $depId;
                }
            }

            if (!empty($dependsOnIds)) {
                HLItemDependency::createBatch($this->db, $itemId, $dependsOnIds);
            }
        }

        $_SESSION['flash_message'] = count($itemsData) . ' work items generated successfully.';
        $this->response->redirect('/app/work-items?project_id=' . $projectId);
    }

    /**
     * Create a new work item from manual form submission.
     *
     * Appends the new item at the end of the priority list for the project.
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
            $_SESSION['flash_error'] = 'Work item title is required.';
            $this->response->redirect('/app/work-items?project_id=' . $projectId);
            return;
        }

        $existing         = HLWorkItem::findByProjectId($this->db, $projectId);
        $maxPriority      = count($existing);
        $estimatedSprints = $this->request->post('estimated_sprints', '');

        HLWorkItem::create($this->db, [
            'project_id'        => $projectId,
            'priority_number'   => $maxPriority + 1,
            'title'             => $title,
            'description'       => trim((string) $this->request->post('description', '')) ?: null,
            'okr_title'         => trim((string) $this->request->post('okr_title', '')) ?: null,
            'okr_description'   => trim((string) $this->request->post('okr_description', '')) ?: null,
            'owner'             => trim((string) $this->request->post('owner', '')) ?: null,
            'estimated_sprints' => $estimatedSprints !== '' ? (int) $estimatedSprints : 2,
        ]);

        $_SESSION['flash_message'] = 'Work item created.';
        $this->response->redirect('/app/work-items?project_id=' . $projectId);
    }

    /**
     * Re-estimate sprint sizing for all work items in a project via AI.
     *
     * Sends all current work items to Gemini with SIZING_PROMPT and batch-
     * updates the estimated_sprints field for each returned item.
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

        $workItems = HLWorkItem::findByProjectId($this->db, $projectId);
        if (empty($workItems)) {
            $_SESSION['flash_error'] = 'No work items to size.';
            $this->response->redirect('/app/work-items?project_id=' . $projectId);
            return;
        }

        // Build work items input list for AI
        $itemLines = [];
        foreach ($workItems as $item) {
            $line = "- ID {$item['id']}: {$item['title']}";
            if (!empty($item['description'])) {
                $line .= ' — ' . mb_substr($item['description'], 0, 120);
            }
            $itemLines[] = $line;
        }
        $input = implode("\n", $itemLines);

        try {
            $gemini  = new GeminiService($this->config);
            $results = $gemini->generateJson(WorkItemPrompt::SIZING_PROMPT, $input);
        } catch (\RuntimeException $e) {
            $_SESSION['flash_error'] = 'Sizing regeneration failed: ' . $e->getMessage();
            $this->response->redirect('/app/work-items?project_id=' . $projectId);
            return;
        }

        if (!is_array($results) || empty($results)) {
            $_SESSION['flash_error'] = 'AI returned an unexpected format. Please try again.';
            $this->response->redirect('/app/work-items?project_id=' . $projectId);
            return;
        }

        // Build a lookup of allowed IDs for security
        $allowedIds = array_column($workItems, 'id');

        foreach ($results as $result) {
            $itemId           = (int) ($result['id'] ?? 0);
            $estimatedSprints = max(1, min(6, (int) ($result['estimated_sprints'] ?? 2)));

            if (in_array($itemId, $allowedIds, true)) {
                HLWorkItem::update($this->db, $itemId, ['estimated_sprints' => $estimatedSprints]);
            }
        }

        $_SESSION['flash_message'] = 'Sprint sizing regenerated for ' . count($results) . ' work items.';
        $this->response->redirect('/app/work-items?project_id=' . $projectId);
    }

    /**
     * Update a single work item's fields from a form submission.
     *
     * @param int $id Work item primary key (from route parameter)
     */
    public function update($id): void
    {
        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];

        $item = HLWorkItem::findById($this->db, (int) $id);
        if ($item === null) {
            $this->response->redirect('/app/home');
            return;
        }

        $project = ProjectPolicy::findEditableProject($this->db, $user, (int) $item['project_id']);
        if ($project === null) {
            $this->response->redirect('/app/home');
            return;
        }

        $newDescription     = trim((string) $this->request->post('description', $item['description'] ?? ''));
        $newEstimatedSprints = $this->request->post('estimated_sprints', '');

        $updateData = [
            'title'               => trim((string) $this->request->post('title', $item['title'])),
            'description'         => $newDescription,
            'okr_title'           => trim((string) $this->request->post('okr_title', $item['okr_title'] ?? '')),
            'okr_description'     => trim((string) $this->request->post('okr_description', $item['okr_description'] ?? '')),
            'owner'               => trim((string) $this->request->post('owner', $item['owner'] ?? '')),
            'acceptance_criteria' => trim((string) $this->request->post('acceptance_criteria', $item['acceptance_criteria'] ?? '')) ?: null,
            'kr_hypothesis'       => mb_substr(
                trim((string) $this->request->post('kr_hypothesis', $item['kr_hypothesis'] ?? '')),
                0, 500
            ) ?: null,
        ];

        // Only update team_assigned if the column exists on this deployment
        if (array_key_exists('team_assigned', $item)) {
            $updateData['team_assigned'] = trim((string) $this->request->post('team_assigned', $item['team_assigned'] ?? '')) ?: null;
        }

        if ($newEstimatedSprints !== '') {
            $updateData['estimated_sprints'] = (int) $newEstimatedSprints;
        }

        HLWorkItem::update($this->db, (int) $id, $updateData);

        // Enqueue for async quality scoring — the background worker will score shortly
        HLWorkItem::markQualityPending($this->db, (int) $id);

        // Flag for review if description or sprint estimate changed
        $descChanged    = $newDescription !== ($item['description'] ?? '');
        $sprintChanged  = $newEstimatedSprints !== '' && (int) $newEstimatedSprints !== (int) $item['estimated_sprints'];
        if ($descChanged || $sprintChanged) {
            HLWorkItem::update($this->db, $id, ['requires_review' => 1]);
        }

        $_SESSION['flash_message'] = 'Work item updated.';
        $this->response->redirect('/app/work-items?project_id=' . $item['project_id']);
    }

    /**
     * Improve a work item's low-scoring fields using AI, then re-score.
     * If quality_score is null, scores first then improves in one request.
     *
     * @param int $id Work item primary key (from route parameter)
     */
    public function improve(int $id): void
    {
        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];

        $item = HLWorkItem::findById($this->db, (int) $id);
        if ($item === null) {
            $this->response->redirect('/app/home');
            return;
        }

        $project = ProjectPolicy::findEditableProject($this->db, $user, (int) $item['project_id']);
        if ($project === null) {
            $this->response->redirect('/app/home');
            return;
        }

        $qualityBlock = '';
        try {
            $qualityBlock = StoryQualityConfig::buildPromptBlock($this->db, $orgId);
        } catch (\Throwable) {}

        // Score first if not yet scored — improvement needs the breakdown
        if ($item['quality_score'] === null) {
            $scorer = new StoryQualityScorer(new GeminiService($this->config));
            $scored = $scorer->scoreWorkItem($item, $qualityBlock);
            if ($scored['score'] !== null) {
                HLWorkItem::markQualityScored($this->db, (int) $id, $scored['score'], $scored['breakdown']);
                $item = HLWorkItem::findById($this->db, (int) $id);
            } else {
                HLWorkItem::markQualityFailed(
                    $this->db,
                    (int) $id,
                    (int) ($item['quality_attempts'] ?? 0) + 1,
                    $scored['error'] ?? 'unknown'
                );
            }
        }

        // Decode breakdown — if still null after scoring attempt, nothing to improve
        $breakdown = null;
        if (!empty($item['quality_breakdown'])) {
            $breakdown = json_decode((string) $item['quality_breakdown'], true);
        }

        if ($breakdown === null) {
            $this->response->redirect('/app/work-items?project_id=' . (int) $item['project_id'] . '&improved=0');
            return;
        }

        // Improve fields that score below 80% of their max
        $improver       = new StoryImprovementService(new GeminiService($this->config));
        $improvedFields = $improver->improveWorkItem($item, $breakdown, $qualityBlock);

        if (empty($improvedFields)) {
            $this->response->redirect('/app/work-items?project_id=' . (int) $item['project_id'] . '&improved=0');
            return;
        }

        HLWorkItem::update($this->db, (int) $id, $improvedFields);

        // Re-score with the improved content — failure is non-fatal
        $itemForScore = array_merge($item, $improvedFields);
        // Re-score with the improved content — enqueue if Gemini is unavailable
        $scorer       = new StoryQualityScorer(new GeminiService($this->config));
        $scored       = $scorer->scoreWorkItem($itemForScore, $qualityBlock);
        if ($scored['score'] !== null) {
            HLWorkItem::markQualityScored($this->db, (int) $id, $scored['score'], $scored['breakdown']);
        } else {
            HLWorkItem::markQualityPending($this->db, (int) $id);
        }

        $this->response->redirect('/app/work-items?project_id=' . (int) $item['project_id'] . '&improved=1');
    }

    /**
     * Refine quality for a single work item (no confirmation prompt — field-scoped, title-safe).
     */
    public function refineQuality($id): void
    {
        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];

        $item = HLWorkItem::findById($this->db, (int) $id);
        if ($item === null) {
            $this->response->redirect('/app/home');
            return;
        }

        $project = ProjectPolicy::findEditableProject($this->db, $user, (int) $item['project_id']);
        if ($project === null) {
            $this->response->redirect('/app/home');
            return;
        }

        $qualityBlock = '';
        try {
            $qualityBlock = StoryQualityConfig::buildPromptBlock($this->db, $orgId);
        } catch (\Throwable) {}

        // Ensure we have a breakdown to work from
        if ($item['quality_score'] === null || empty($item['quality_breakdown'])) {
            $scorer = new StoryQualityScorer(new GeminiService($this->config));
            $scored = $scorer->scoreWorkItem($item, $qualityBlock);
            if ($scored['score'] !== null) {
                HLWorkItem::markQualityScored($this->db, (int) $id, $scored['score'], $scored['breakdown']);
                $item = HLWorkItem::findById($this->db, (int) $id);
            }
        }

        $breakdown = !empty($item['quality_breakdown'])
            ? json_decode((string) $item['quality_breakdown'], true)
            : null;

        if ($breakdown === null) {
            $this->response->redirect('/app/work-items?project_id=' . (int) $item['project_id']);
            return;
        }

        $improver       = new StoryImprovementService(new GeminiService($this->config));
        $improvedFields = $improver->improveWorkItem($item, $breakdown, $qualityBlock);

        if (!empty($improvedFields)) {
            HLWorkItem::update($this->db, (int) $id, $improvedFields);
        }

        HLWorkItem::markQualityPending($this->db, (int) $id);
        $this->response->redirect('/app/work-items?project_id=' . (int) $item['project_id']);
    }

    /**
     * Refine quality for all work items in a project that score below 80 (up to 50 rows).
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

        $qualityBlock = '';
        try {
            $qualityBlock = StoryQualityConfig::buildPromptBlock($this->db, $orgId);
        } catch (\Throwable) {}

        $rows = $this->db->query(
            "SELECT wi.* FROM hl_work_items wi
             JOIN projects p ON p.id = wi.project_id
             WHERE wi.project_id = :pid
               AND p.org_id = :oid
               AND wi.quality_status = 'scored'
               AND wi.quality_score < 80
             ORDER BY wi.quality_score ASC
             LIMIT 50",
            [':pid' => $projectId, ':oid' => $orgId]
        )->fetchAll();

        $refined = 0;
        $geminiSvc = new GeminiService($this->config);
        $improver  = new StoryImprovementService($geminiSvc);

        foreach ($rows as $item) {
            $breakdown = !empty($item['quality_breakdown'])
                ? json_decode((string) $item['quality_breakdown'], true)
                : null;

            if ($breakdown === null) {
                continue;
            }

            $improvedFields = $improver->improveWorkItem($item, $breakdown, $qualityBlock);
            if (!empty($improvedFields)) {
                HLWorkItem::update($this->db, (int) $item['id'], $improvedFields);
            }
            HLWorkItem::markQualityPending($this->db, (int) $item['id']);
            $refined++;
        }

        if ($refined > 0) {
            $_SESSION['flash_message'] = "Refined {$refined} work " . ($refined === 1 ? 'item' : 'items') . " — new scores in 1–2 min.";
        } else {
            $_SESSION['flash_message'] = 'All work items already meet the quality threshold.';
        }

        $this->response->redirect('/app/work-items?project_id=' . $projectId);
    }

    /**
     * Close a work item (sets status to 'closed').
     */
    public function close($id): void
    {
        $user   = $this->auth->user();
        $orgId  = (int) $user['org_id'];
        $item   = HLWorkItem::findById($this->db, (int) $id);

        if ($item === null) {
            $this->response->redirect('/app/home');
            return;
        }

        $projectId = (int) $item['project_id'];
        $project   = ProjectPolicy::findEditableProject($this->db, $user, $projectId);
        if ($project === null) {
            $this->response->redirect('/app/home');
            return;
        }

        HLWorkItem::update($this->db, (int) $id, ['status' => 'closed']);

        $this->response->redirect('/app/work-items?project_id=' . $projectId);
    }

    /**
     * Delete a single work item and re-number remaining priorities.
     *
     * @param int $id Work item primary key (from route parameter)
     */
    public function delete($id): void
    {
        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];

        $item = HLWorkItem::findById($this->db, (int) $id);
        if ($item === null) {
            $this->response->redirect('/app/home');
            return;
        }

        $projectId = (int) $item['project_id'];
        $project   = ProjectPolicy::findEditableProject($this->db, $user, $projectId);
        if ($project === null) {
            $this->response->redirect('/app/home');
            return;
        }

        HLWorkItem::delete($this->db, (int) $id);

        // Re-number remaining items
        $remaining = HLWorkItem::findByProjectId($this->db, $projectId);
        $updates   = [];
        foreach ($remaining as $index => $wi) {
            $updates[] = ['id' => (int) $wi['id'], 'priority_number' => $index + 1];
        }
        if (!empty($updates)) {
            HLWorkItem::batchUpdatePriority($this->db, $updates);
        }

        $_SESSION['flash_message'] = 'Work item deleted.';
        $this->response->redirect('/app/work-items?project_id=' . $projectId);
    }

    /**
     * Reorder work items via AJAX drag-and-drop.
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
        $firstItem = HLWorkItem::findById($this->db, (int) $order[0]['id']);
        if ($firstItem === null) {
            $this->response->json(['status' => 'error', 'message' => 'Item not found'], 404);
            return;
        }

        $project = ProjectPolicy::findEditableProject($this->db, $user, (int) $firstItem['project_id']);
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

        HLWorkItem::batchUpdatePriority($this->db, $updates);

        $this->response->json(['status' => 'ok']);
    }

    /**
     * Generate a detailed AI description for a single work item (AJAX).
     *
     * Loads the work item, project context, and document summary, then
     * calls Gemini to produce a detailed scope description.
     *
     * @param int $id Work item primary key (from route parameter)
     */
    public function generateDescription($id): void
    {
        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];

        $item = HLWorkItem::findById($this->db, (int) $id);
        if ($item === null) {
            $this->response->json(['status' => 'error', 'message' => 'Item not found'], 404);
            return;
        }

        $project = ProjectPolicy::findEditableProject($this->db, $user, (int) $item['project_id']);
        if ($project === null) {
            $this->response->json(['status' => 'error', 'message' => 'Access denied'], 403);
            return;
        }

        // Load document summary for context
        $documents      = Document::findByProjectId($this->db, (int) $item['project_id']);
        $documentSummary = '';
        foreach ($documents as $doc) {
            if (!empty($doc['ai_summary'])) {
                $documentSummary = $doc['ai_summary'];
                break;
            }
        }

        // Build prompt with placeholders replaced
        $prompt = str_replace(
            ['{title}', '{context}', '{summary}'],
            [$item['title'], $item['strategic_context'] ?? '', $documentSummary],
            WorkItemPrompt::DESCRIPTION_PROMPT
        );

        try {
            $gemini      = new GeminiService($this->config);
            $description = $gemini->generate($prompt, '');
        } catch (\RuntimeException $e) {
            $this->response->json(['status' => 'error', 'message' => $e->getMessage()], 500);
            return;
        }

        // Update the work item's description
        HLWorkItem::update($this->db, $id, ['description' => $description]);

        $this->response->json(['status' => 'ok', 'description' => $description]);
    }

    /**
     * AJAX endpoint to calculate and update the quality score for a work item.
     *
     * @param int $id HL work item primary key
     */
    public function score(string $id): void
    {
        $id    = (int) $id;
        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];

        $item = \StratFlow\Models\HLWorkItem::findById($this->db, $id);
        if ($item === null) {
            $this->response->json(['status' => 'error', 'message' => 'Work item not found'], 404);
            return;
        }

        $project = \StratFlow\Models\ProjectPolicy::findEditableProject($this->db, $user, (int) $item['project_id']);
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
        $scored = $scorer->scoreWorkItem($item, $qualityBlock);

        if ($scored['score'] !== null) {
            \StratFlow\Models\HLWorkItem::markQualityScored($this->db, $id, $scored['score'], $scored['breakdown']);

            $html = '';
            ob_start();
            $breakdownData = $scored['breakdown'];
            $itemId        = $id;
            $itemType      = 'work-item';
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
        \StratFlow\Models\HLWorkItem::markQualityFailed(
            $this->db,
            $id,
            (int) ($item['quality_attempts'] ?? 0) + 1,
            $errorKey
        );
        error_log('[StratFlow] WorkItem score error: ' . $errorKey);
        $this->response->json(['status' => 'error', 'message' => 'Scoring failed: ' . $errorKey], 503);

        $this->response->json(['status' => 'error', 'message' => 'Scoring failed']);
    }

    /**
     * Export work items as CSV or JSON download.
     *
     * Reads format from query string (csv or json) and sends the
     * appropriate file download response.
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

        $workItems = HLWorkItem::findByProjectId($this->db, $projectId);
        $safeName  = preg_replace('/[^a-zA-Z0-9_-]/', '_', $project['name']);

        AuditLogger::log($this->db, (int) $user['id'], AuditLogger::DATA_EXPORT, $this->request->ip(), $_SERVER['HTTP_USER_AGENT'] ?? '', [
            'project_id' => $projectId,
            'format'     => $format,
            'type'       => 'work_items_export',
        ]);

        if ($format === 'json') {
            $exportData = array_map(function ($item) {
                return [
                    'priority'          => (int) $item['priority_number'],
                    'title'             => $item['title'],
                    'description'       => $item['description'] ?? '',
                    'strategic_context' => $item['strategic_context'] ?? '',
                    'okr_title'         => $item['okr_title'] ?? '',
                    'okr_description'   => $item['okr_description'] ?? '',
                    'owner'             => $item['owner'] ?? '',
                    'estimated_sprints' => (int) $item['estimated_sprints'],
                ];
            }, $workItems);

            $content = json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $this->response->download($content, $safeName . '_work_items.json', 'application/json');
            return;
        }

        // Default: CSV
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['Priority', 'Title', 'Description', 'Strategic Context', 'OKR Title', 'Owner', 'Estimated Sprints']);

        foreach ($workItems as $item) {
            fputcsv($handle, [
                $item['priority_number'],
                $item['title'],
                $item['description'] ?? '',
                $item['strategic_context'] ?? '',
                $item['okr_title'] ?? '',
                $item['owner'] ?? '',
                $item['estimated_sprints'],
            ]);
        }

        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        $this->response->download($content, $safeName . '_work_items.csv', 'text/csv');
    }

    // ===========================
    // HELPERS
    // ===========================

    /**
     * Build the combined input string for AI work item generation.
     *
     * Combines the Mermaid diagram code, node OKR data, and document
     * summary into a single formatted string for the AI prompt.
     *
     * @param array  $diagram         Diagram row
     * @param array  $nodes           Array of node rows
     * @param string $documentSummary AI-generated document summary
     * @return string                 Combined input for Gemini
     */
    private function buildGenerationInput(array $diagram, array $nodes, string $documentSummary): string
    {
        $parts = [];

        $parts[] = "## Mermaid Strategy Diagram\n```\n" . $diagram['mermaid_code'] . "\n```";

        if (!empty($nodes)) {
            $parts[] = "## Node OKR Data";
            foreach ($nodes as $node) {
                $okrTitle = $node['okr_title'] ?? '';
                $okrDesc  = $node['okr_description'] ?? '';
                if ($okrTitle || $okrDesc) {
                    $parts[] = "- **{$node['node_key']}** ({$node['label']}): OKR: {$okrTitle} - {$okrDesc}";
                } else {
                    $parts[] = "- **{$node['node_key']}** ({$node['label']}): No OKR defined";
                }
            }
        }

        if (!empty($documentSummary)) {
            $parts[] = "## Document Summary\n" . $documentSummary;
        }

        return implode("\n\n", $parts);
    }
}
