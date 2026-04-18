<?php

/**
 * DiagramController
 *
 * Handles the strategy diagram page: viewing/editing Mermaid.js diagrams,
 * generating new diagrams from AI summaries via Gemini, saving manual
 * code edits, and updating OKR fields on individual diagram nodes.
 */

declare(strict_types=1);

namespace StratFlow\Controllers;

use StratFlow\Core\Auth;
use StratFlow\Core\Database;
use StratFlow\Core\Request;
use StratFlow\Core\Response;
use StratFlow\Models\DiagramNode;
use StratFlow\Models\Document;
use StratFlow\Models\Project;
use StratFlow\Models\Subscription;
use StratFlow\Models\StrategyDiagram;
use StratFlow\Security\ProjectPolicy;
use StratFlow\Services\GeminiService;
use StratFlow\Services\Prompts\DiagramPrompt;

class DiagramController
{
    protected Request $request;
    protected Response $response;
    protected Auth $auth;
    protected Database $db;
    protected array $config;
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
     * Render the diagram page for a specific project.
     *
     * Loads the project by query-string project_id, enforces org-level
     * multi-tenancy, then renders the diagram template with the latest
     * diagram, its nodes, and the document summary for context.
     */
    public function index(): void
    {
        $user      = $this->auth->user();
        $projectId = (int) $this->request->get('project_id', 0);
        $project = ProjectPolicy::findViewableProject($this->db, $user, $projectId);
        if ($project === null) {
            $this->response->redirect('/app/home');
            return;
        }

        $diagram  = StrategyDiagram::findByProjectId($this->db, $projectId);
        $nodes    = $diagram ? DiagramNode::findByDiagramId($this->db, (int) $diagram['id']) : [];
// Load latest document summary for context display
        $documents      = Document::findByProjectId($this->db, $projectId);
        $documentSummary = null;
        foreach ($documents as $doc) {
            if (!empty($doc['ai_summary'])) {
                $documentSummary = $doc['ai_summary'];
                break;
            }
        }

        // If no diagram AND no summary, redirect to upload with guidance
        if ($diagram === null && $documentSummary === null) {
            $_SESSION['flash_error'] = 'To build a strategy roadmap, upload a strategy document and generate an AI summary first.';
            $this->response->redirect('/app/upload?project_id=' . $projectId);
            return;
        }

        $orgId = (int) $user['org_id'];
        $this->response->render('diagram', [
            'user'                 => $user,
            'project'              => $project,
            'diagram'              => $diagram,
            'nodes'                => $nodes,
            'document_summary'     => $documentSummary,
            'active_page'          => 'diagram',
            'has_evaluation_board' => Subscription::hasEvaluationBoard($this->db, $orgId),
            'flash_message'        => $_SESSION['flash_message'] ?? null,
            'flash_error'          => $_SESSION['flash_error']   ?? null,
        ], 'app');
        unset($_SESSION['flash_message'], $_SESSION['flash_error']);
    }

    /**
     * Generate a Mermaid.js diagram from the project's AI document summary.
     *
     * Loads the latest document with an ai_summary, sends it to Gemini with
     * the DiagramPrompt, parses nodes from the resulting Mermaid code, and
     * stores the diagram and nodes in the database.
     */
    public function generate(): void
    {
        $user      = $this->auth->user();
        $projectId = (int) $this->request->post('project_id', 0);
        $project = ProjectPolicy::findEditableProject($this->db, $user, $projectId);
        if ($project === null) {
            $this->response->redirect('/app/home');
            return;
        }

        // Find the latest document with an AI summary
        $documents = Document::findByProjectId($this->db, $projectId);
        $aiSummary = null;
        foreach ($documents as $doc) {
            if (!empty($doc['ai_summary'])) {
                $aiSummary = $doc['ai_summary'];
                break;
            }
        }

        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
        if ($aiSummary === null) {
            $msg = 'No AI summary found. Upload a document and generate a summary first.';
            if ($isAjax) {
                $this->response->json(['error' => $msg], 400);
                return;
            }
            $_SESSION['flash_error'] = $msg;
            $this->response->redirect('/app/upload?project_id=' . $projectId);
            return;
        }

        // Generate Mermaid code via Gemini — retry up to 2 times on bad output
        $gemini = new GeminiService($this->config);
        $mermaidCode = null;
        $lastError = '';

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $prompt = $attempt === 1
                    ? DiagramPrompt::PROMPT
                    : DiagramPrompt::PROMPT . "\n\nIMPORTANT: Your previous response was invalid. You MUST output ONLY valid Mermaid.js code starting with 'graph TD'. No explanations, no markdown fences, no extra text.";
                $raw = $gemini->generate($prompt, $aiSummary);
                $cleaned = $this->cleanMermaidCode($raw);
                if (stripos($cleaned, 'graph') !== false) {
                    $mermaidCode = $cleaned;
                    break;
                }

                $lastError = 'AI did not return valid Mermaid diagram code (attempt ' . $attempt . ')';
                \StratFlow\Services\Logger::warn("[DiagramGen] Attempt {$attempt} failed: no 'graph' keyword. Raw output: " . substr($raw, 0, 200));
            } catch (\RuntimeException $e) {
                $lastError = $e->getMessage();
                \StratFlow\Services\Logger::warn("[DiagramGen] Attempt {$attempt} exception: " . $e->getMessage());
            }
        }

        if ($mermaidCode === null) {
            $msg = 'Diagram generation failed: ' . $lastError . '. Please try again.';
            if ($isAjax) {
                $this->response->json(['error' => $msg], 400);
                return;
            }
            $_SESSION['flash_error'] = $msg;
            $this->response->redirect('/app/diagram?project_id=' . $projectId);
            return;
        }

        // Delete existing diagram and nodes for this project
        $existingDiagram = StrategyDiagram::findByProjectId($this->db, $projectId);
        if ($existingDiagram !== null) {
            DiagramNode::deleteByDiagramId($this->db, (int) $existingDiagram['id']);
            StrategyDiagram::deleteByProjectId($this->db, $projectId);
        }

        // Create new diagram
        $diagramId = StrategyDiagram::create($this->db, [
            'project_id'   => $projectId,
            'mermaid_code' => $mermaidCode,
            'created_by'   => (int) $user['id'],
        ]);
// Parse nodes and create records
        $nodes = $this->parseNodes($mermaidCode);
        if (!empty($nodes)) {
            DiagramNode::createBatch($this->db, $diagramId, $nodes);
        }

        $nodeCount = count($nodes);
        $msg = $nodeCount === 0
            ? 'Diagram generated but no nodes could be parsed. You may need to edit the Mermaid code.'
            : "Strategy diagram generated with {$nodeCount} nodes.";
        if ($isAjax) {
            $this->response->json([
                'success'      => true,
                'message'      => $msg,
                'mermaid_code' => $mermaidCode,
                'node_count'   => $nodeCount,
            ]);
            return;
        }

        $_SESSION['flash_message'] = $msg;
        $this->response->redirect('/app/diagram?project_id=' . $projectId);
    }

    /**
     * Save manually edited Mermaid code and OKR data.
     *
     * Updates the diagram's mermaid_code (incrementing version), re-parses
     * the nodes from the new code, and saves any submitted OKR data.
     */
    public function save(): void
    {
        $user        = $this->auth->user();
        $projectId   = (int) $this->request->post('project_id', 0);
        $mermaidCode = trim((string) $this->request->post('mermaid_code', ''));
        $project = ProjectPolicy::findEditableProject($this->db, $user, $projectId);
        if ($project === null) {
            $this->response->redirect('/app/home');
            return;
        }

        $diagram = StrategyDiagram::findByProjectId($this->db, $projectId);
        if ($diagram === null) {
            $_SESSION['flash_error'] = 'No diagram found to update. Generate one first.';
            $this->response->redirect('/app/diagram?project_id=' . $projectId);
            return;
        }

        // Update the diagram code (version is incremented automatically)
        StrategyDiagram::update($this->db, (int) $diagram['id'], [
            'mermaid_code' => $mermaidCode,
        ]);
// Re-parse nodes from updated code
        DiagramNode::deleteByDiagramId($this->db, (int) $diagram['id']);
        $nodes = $this->parseNodes($mermaidCode);
        if (!empty($nodes)) {
            DiagramNode::createBatch($this->db, (int) $diagram['id'], $nodes);
        }

        // Save OKR data if submitted
        $okrTitles       = $this->request->post('okr_title', []);
        $okrDescriptions = $this->request->post('okr_description', []);
        if (is_array($okrTitles)) {
            $freshNodes = DiagramNode::findByDiagramId($this->db, (int) $diagram['id']);
            foreach ($freshNodes as $node) {
                $nodeKey = $node['node_key'];
                if (isset($okrTitles[$nodeKey]) || isset($okrDescriptions[$nodeKey])) {
                    DiagramNode::update($this->db, (int) $node['id'], [
                        'okr_title'       => $okrTitles[$nodeKey] ?? '',
                        'okr_description' => $okrDescriptions[$nodeKey] ?? '',
                    ]);
                }
            }
        }

        $_SESSION['flash_message'] = 'Diagram saved successfully.';
        $this->response->redirect('/app/diagram?project_id=' . $projectId);
    }

    /**
     * Save OKR data for a single diagram node (AJAX handler).
     *
     * Accepts node_id, okr_title, and okr_description via POST and
     * returns a JSON response indicating success.
     */
    public function saveOkr(): void
    {
        $user           = $this->auth->user();
        $nodeId         = (int) $this->request->post('node_id', 0);
        $okrTitle       = trim((string) $this->request->post('okr_title', ''));
        $okrDescription = trim((string) $this->request->post('okr_description', ''));
        $row = $this->db->query("SELECT dn.id, sd.project_id FROM diagram_nodes dn
             JOIN strategy_diagrams sd ON dn.diagram_id = sd.id
             WHERE dn.id = :node_id
             LIMIT 1", [':node_id' => $nodeId])->fetch();
        if (!$row || ProjectPolicy::findEditableProject($this->db, $user, (int) $row['project_id']) === null) {
            $this->response->json(['status' => 'error', 'message' => 'Access denied'], 403);
            return;
        }

        DiagramNode::update($this->db, (int) $nodeId, [
            'okr_title'       => $okrTitle,
            'okr_description' => $okrDescription,
        ]);
        $this->response->json(['status' => 'ok']);
    }

    /**
     * Generate SMART OKRs for all diagram nodes using AI.
     */
    public function generateOkrs(): void
    {
        $user      = $this->auth->user();
        $projectId = (int) $this->request->post('project_id', 0);
        $project = ProjectPolicy::findEditableProject($this->db, $user, $projectId);
        if ($project === null) {
            $this->response->redirect('/app/home');
            return;
        }

        $diagram = StrategyDiagram::findByProjectId($this->db, $projectId);
        if ($diagram === null) {
            $_SESSION['flash_error'] = 'No diagram found. Generate a diagram first.';
            $this->response->redirect('/app/diagram?project_id=' . $projectId);
            return;
        }

        $nodes = DiagramNode::findByDiagramId($this->db, (int) $diagram['id']);
        if (empty($nodes)) {
            $_SESSION['flash_error'] = 'No nodes found in the diagram.';
            $this->response->redirect('/app/diagram?project_id=' . $projectId);
            return;
        }

        // Build input: list of nodes with their labels + any document context
        $nodeLines = [];
        foreach ($nodes as $n) {
            $nodeLines[] = "- Node {$n['node_key']}: {$n['label']}";
        }

        // Get document summary for context
        $docs = \StratFlow\Models\Document::findByProjectId($this->db, $projectId);
        $summary = '';
        foreach ($docs as $doc) {
            if (!empty($doc['ai_summary'])) {
                $summary = $doc['ai_summary'];
                break;
            }
        }

        $input = "Strategic Nodes:\n" . implode("\n", $nodeLines);
        if ($summary) {
            $input .= "\n\nStrategic Context:\n" . $summary;
        }

        try {
            $gemini = new GeminiService($this->config);
            $okrs = $gemini->generateJson(\StratFlow\Services\Prompts\DiagramPrompt::OKR_PROMPT, $input);
        } catch (\RuntimeException $e) {
            $_SESSION['flash_error'] = 'OKR generation failed: ' . $e->getMessage();
            $this->response->redirect('/app/diagram?project_id=' . $projectId);
            return;
        }

        // Map OKRs back to nodes by node_key
        $okrMap = [];
        foreach ($okrs as $okr) {
            $key = $okr['node_key'] ?? '';
            $okrMap[strtoupper(trim($key))] = $okr;
        }

        $updated = 0;
        foreach ($nodes as $node) {
            $key = strtoupper(trim($node['node_key']));
            if (isset($okrMap[$key])) {
                DiagramNode::update($this->db, (int) $node['id'], [
                    'okr_title'       => trim($okrMap[$key]['okr_title'] ?? ''),
                    'okr_description' => trim($okrMap[$key]['okr_description'] ?? ''),
                ]);
                $updated++;
            }
        }

        $_SESSION['flash_message'] = "{$updated} SMART OKRs generated. Review and edit as needed, then save.";
        $this->response->redirect('/app/diagram?project_id=' . $projectId);
    }

    /**
     * Save all OKRs in a single form POST.
     */
    /**
     * Manually add a new OKR (creates a new diagram node).
     */
    public function addOkr(): void
    {
        $user      = $this->auth->user();
        $projectId = (int) $this->request->post('project_id', 0);
        $nodeId    = (int) $this->request->post('node_id', 0);
        $project = ProjectPolicy::findEditableProject($this->db, $user, $projectId);
        if ($project === null) {
            $this->response->redirect('/app/home');
            return;
        }

        if ($nodeId === 0) {
            $_SESSION['flash_message'] = 'Please select a strategic initiative.';
            $this->response->redirect('/app/diagram?project_id=' . $projectId);
            return;
        }

        $okrTitle       = trim((string) $this->request->post('okr_title', ''));
        $okrDescription = trim((string) $this->request->post('okr_description', ''));
// Confirm node belongs to a diagram in this project (org-scoped)
        $row = $this->db->query("SELECT dn.id FROM diagram_nodes dn
             JOIN strategy_diagrams sd ON dn.diagram_id = sd.id
             WHERE dn.id = :node_id AND sd.project_id = :project_id LIMIT 1", [':node_id' => $nodeId, ':project_id' => $projectId])->fetch();
        if (!$row) {
            $_SESSION['flash_message'] = 'Initiative not found.';
            $this->response->redirect('/app/diagram?project_id=' . $projectId);
            return;
        }

        $this->db->query("UPDATE diagram_nodes SET okr_title = :okr_title, okr_description = :okr_description WHERE id = :id", [':okr_title' => $okrTitle ?: null, ':okr_description' => $okrDescription ?: null, ':id' => $nodeId]);
        $_SESSION['flash_message'] = 'OKR saved.';
        $this->response->redirect('/app/diagram?project_id=' . $projectId);
    }

    /**
     * Generate the next node key after the existing set.
     * Follows Excel-column-style progression: A … Z, AA, AB …
    /**
     * Delete a single OKR node (org-scoped).
     */
    public function deleteOkr(): void
    {
        $user      = $this->auth->user();
        $nodeId    = (int) $this->request->post('node_id', 0);
        $projectId = (int) $this->request->post('project_id', 0);
        $project = ProjectPolicy::findEditableProject($this->db, $user, $projectId);
        if ($project === null) {
            $this->response->redirect('/app/home');
            return;
        }

        $stmt = $this->db->query("SELECT dn.id FROM diagram_nodes dn
             JOIN strategy_diagrams sd ON dn.diagram_id = sd.id
             WHERE dn.id = :node_id AND sd.project_id = :project_id
             LIMIT 1", [':node_id' => $nodeId, ':project_id' => $projectId]);
        if (!$stmt->fetch()) {
            $_SESSION['flash_message'] = 'OKR not found.';
            $this->response->redirect('/app/diagram?project_id=' . $projectId);
            return;
        }

        DiagramNode::delete($this->db, $nodeId);
        $_SESSION['flash_message'] = 'OKR deleted.';
        $this->response->redirect('/app/diagram?project_id=' . $projectId);
    }

    public function saveAllOkrs(): void
    {
        $user      = $this->auth->user();
        $projectId = (int) $this->request->post('project_id', 0);
        $project = ProjectPolicy::findEditableProject($this->db, $user, $projectId);
        if ($project === null) {
            $this->response->redirect('/app/home');
            return;
        }

        $nodes = $this->request->post('nodes', []);
        if (!is_array($nodes)) {
            $nodes = [];
        }

        $updated = 0;
        foreach ($nodes as $nodeData) {
            $nodeId = (int) ($nodeData['id'] ?? 0);
            if ($nodeId <= 0) {
                continue;
            }

            $row = $this->db->query("SELECT dn.id
                   FROM diagram_nodes dn
                   JOIN strategy_diagrams sd ON dn.diagram_id = sd.id
                  WHERE dn.id = :node_id AND sd.project_id = :project_id
                  LIMIT 1", [':node_id' => $nodeId, ':project_id' => $projectId])->fetch();
            if (!$row) {
                continue;
            }

            DiagramNode::update($this->db, (int) $nodeId, [
                'okr_title'       => trim((string) ($nodeData['okr_title'] ?? '')),
                'okr_description' => trim((string) ($nodeData['okr_description'] ?? '')),
            ]);
            $updated++;
        }

        $_SESSION['flash_message'] = "{$updated} OKRs saved.";
        $this->response->redirect('/app/diagram?project_id=' . $projectId);
    }

    // ===========================
    // HELPERS
    // ===========================

    /**
     * Strip markdown code fences from Gemini's Mermaid output.
     *
     * @param string $code Raw output from Gemini
     * @return string      Cleaned Mermaid code
     */
    private function cleanMermaidCode(string $code): string
    {
        $code = trim($code);
// Remove markdown fences (```mermaid ... ``` or ``` ... ```)
        $code = preg_replace('/^```(?:mermaid)?\s*\n?/im', '', $code);
        $code = preg_replace('/\n?\s*```\s*$/m', '', $code);
// Remove any text before the first "graph" line (AI sometimes adds preamble)
        if (preg_match('/(graph\s+(?:TD|TB|LR|RL|BT))/i', $code, $m, PREG_OFFSET_CAPTURE)) {
            $code = substr($code, $m[0][1]);
        }

        // Clean labels in square brackets: remove chars that break Mermaid
        $code = preg_replace_callback('/\[([^\]]*)\]/', function ($m) {

            $label = $m[1];
            $label = str_replace(['(', ')', '&', '<', '>', '"', "'", '#', ';'], [' - ', '', 'and', '', '', '', '', '', ''], $label);
            $label = preg_replace('/\s+/', ' ', trim($label));
            return '[' . $label . ']';
        }, $code);
// Fix common AI mistakes: "--->" should be "-->"
        $code = str_replace('--->', '-->', $code);
// Fix "-- text -->" link labels that sometimes break
        $code = preg_replace('/--\s*\|[^|]*\|\s*>/', '-->', $code);
// Remove empty lines
        $code = preg_replace('/\n{3,}/', "\n", $code);
        return trim($code);
    }

    /**
     * Parse node IDs and labels from Mermaid diagram code.
     *
     * Matches patterns like A[Label Text], B(Label Text), C{Label Text}.
     *
     * @param string $code Mermaid diagram code
     * @return array       Array of arrays with keys: node_key, label
     */
    private function parseNodes(string $code): array
    {
        $nodes = [];
        $seen  = [];
// Match node definitions: A[Label], B(Label), C{Label}, node_1[Label], etc.
        // Supports: single letters, multi-char IDs, IDs with underscores/numbers
        if (preg_match_all('/\b([A-Za-z][A-Za-z0-9_]*)\s*[\[\(\{]([^\]\)\}]+)[\]\)\}]/', $code, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $key   = $match[1];
                $label = trim($match[2]);
            // Skip keywords and duplicates
                $lower = strtolower($key);
                if (in_array($lower, ['graph', 'subgraph', 'end', 'style', 'class', 'click', 'td', 'tb', 'lr', 'rl', 'bt']) || isset($seen[$key])) {
                    continue;
                }

                // Skip if label is empty or too short
                if (strlen($label) < 2) {
                    continue;
                }

                $seen[$key] = true;
                $nodes[]    = [
                    'node_key' => $key,
                    'label'    => $label,
                ];
            }
        }

        return $nodes;
    }
}
