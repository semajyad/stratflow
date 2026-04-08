<?php
/**
 * WorkItemController
 *
 * Handles the High-Level Work Items (HLWI) page: AI generation from
 * strategy diagrams, drag-and-drop reordering, CRUD operations,
 * AI description generation, and CSV/JSON export.
 */

declare(strict_types=1);

namespace StratFlow\Controllers;

use StratFlow\Core\Auth;
use StratFlow\Core\Database;
use StratFlow\Core\Request;
use StratFlow\Core\Response;
use StratFlow\Models\DiagramNode;
use StratFlow\Models\Document;
use StratFlow\Models\HLWorkItem;
use StratFlow\Models\Project;
use StratFlow\Models\StrategyDiagram;
use StratFlow\Models\Subscription;
use StratFlow\Services\GeminiService;
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

        $project = Project::findById($this->db, $projectId, $orgId);
        if ($project === null) {
            $this->response->redirect('/app/home');
            return;
        }

        $workItems = HLWorkItem::findByProjectId($this->db, $projectId);
        $diagram   = StrategyDiagram::findByProjectId($this->db, $projectId);

        $this->response->render('work-items', [
            'user'                 => $user,
            'project'              => $project,
            'work_items'           => $workItems,
            'diagram'              => $diagram,
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

        $project = Project::findById($this->db, $projectId, $orgId);
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

        foreach ($itemsData as $index => $item) {
            HLWorkItem::create($this->db, [
                'project_id'        => $projectId,
                'diagram_id'        => (int) $diagram['id'],
                'priority_number'   => $item['priority_number'] ?? ($index + 1),
                'title'             => $item['title'] ?? 'Untitled Work Item',
                'description'       => $item['description'] ?? null,
                'strategic_context' => $item['strategic_context'] ?? null,
                'okr_title'         => $item['okr_title'] ?? null,
                'okr_description'   => $item['okr_description'] ?? null,
                'estimated_sprints' => $item['estimated_sprints'] ?? 2,
            ]);
        }

        $_SESSION['flash_message'] = count($itemsData) . ' work items generated successfully.';
        $this->response->redirect('/app/work-items?project_id=' . $projectId);
    }

    /**
     * Update a single work item's fields from a form submission.
     *
     * @param int $id Work item primary key (from route parameter)
     */
    public function update(int $id): void
    {
        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];

        $item = HLWorkItem::findById($this->db, $id);
        if ($item === null) {
            $this->response->redirect('/app/home');
            return;
        }

        $project = Project::findById($this->db, (int) $item['project_id'], $orgId);
        if ($project === null) {
            $this->response->redirect('/app/home');
            return;
        }

        $newDescription     = trim((string) $this->request->post('description', $item['description'] ?? ''));
        $newEstimatedSprints = $this->request->post('estimated_sprints', '');

        $updateData = [
            'title'           => trim((string) $this->request->post('title', $item['title'])),
            'description'     => $newDescription,
            'okr_title'       => trim((string) $this->request->post('okr_title', $item['okr_title'] ?? '')),
            'okr_description' => trim((string) $this->request->post('okr_description', $item['okr_description'] ?? '')),
            'owner'           => trim((string) $this->request->post('owner', $item['owner'] ?? '')),
        ];

        if ($newEstimatedSprints !== '') {
            $updateData['estimated_sprints'] = (int) $newEstimatedSprints;
        }

        HLWorkItem::update($this->db, $id, $updateData);

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
     * Delete a single work item and re-number remaining priorities.
     *
     * @param int $id Work item primary key (from route parameter)
     */
    public function delete(int $id): void
    {
        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];

        $item = HLWorkItem::findById($this->db, $id);
        if ($item === null) {
            $this->response->redirect('/app/home');
            return;
        }

        $projectId = (int) $item['project_id'];
        $project   = Project::findById($this->db, $projectId, $orgId);
        if ($project === null) {
            $this->response->redirect('/app/home');
            return;
        }

        HLWorkItem::delete($this->db, $id);

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

        $project = Project::findById($this->db, (int) $firstItem['project_id'], $orgId);
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
    public function generateDescription(int $id): void
    {
        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];

        $item = HLWorkItem::findById($this->db, $id);
        if ($item === null) {
            $this->response->json(['status' => 'error', 'message' => 'Item not found'], 404);
            return;
        }

        $project = Project::findById($this->db, (int) $item['project_id'], $orgId);
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

        $project = Project::findById($this->db, $projectId, $orgId);
        if ($project === null) {
            $this->response->redirect('/app/home');
            return;
        }

        $workItems = HLWorkItem::findByProjectId($this->db, $projectId);
        $safeName  = preg_replace('/[^a-zA-Z0-9_-]/', '_', $project['name']);

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
