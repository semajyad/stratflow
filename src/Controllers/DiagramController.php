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
use StratFlow\Models\StrategyDiagram;
use StratFlow\Services\GeminiService;
use StratFlow\Services\Prompts\DiagramPrompt;

class DiagramController
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
     * Render the diagram page for a specific project.
     *
     * Loads the project by query-string project_id, enforces org-level
     * multi-tenancy, then renders the diagram template with the latest
     * diagram, its nodes, and the document summary for context.
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

        $this->response->render('diagram', [
            'user'             => $user,
            'project'          => $project,
            'diagram'          => $diagram,
            'nodes'            => $nodes,
            'document_summary' => $documentSummary,
            'active_page'      => 'diagram',
            'flash_message'    => $_SESSION['flash_message'] ?? null,
            'flash_error'      => $_SESSION['flash_error']   ?? null,
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
        $orgId     = (int) $user['org_id'];
        $projectId = (int) $this->request->post('project_id', 0);

        $project = Project::findById($this->db, $projectId, $orgId);
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

        if ($aiSummary === null) {
            $_SESSION['flash_error'] = 'No AI summary found. Please generate a document summary first.';
            $this->response->redirect('/app/upload?project_id=' . $projectId);
            return;
        }

        // Generate Mermaid code via Gemini
        try {
            $gemini      = new GeminiService($this->config);
            $mermaidCode = $gemini->generate(DiagramPrompt::PROMPT, $aiSummary);
        } catch (\RuntimeException $e) {
            $_SESSION['flash_error'] = 'Diagram generation failed: ' . $e->getMessage();
            $this->response->redirect('/app/diagram?project_id=' . $projectId);
            return;
        }

        // Clean response — strip markdown fences if present
        $mermaidCode = $this->cleanMermaidCode($mermaidCode);

        // Validate the response contains a graph definition
        if (stripos($mermaidCode, 'graph') === false) {
            $_SESSION['flash_error'] = 'AI returned invalid diagram code. Please try again.';
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

        $_SESSION['flash_message'] = 'Strategy diagram generated successfully.';
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
        $orgId       = (int) $user['org_id'];
        $projectId   = (int) $this->request->post('project_id', 0);
        $mermaidCode = trim((string) $this->request->post('mermaid_code', ''));

        $project = Project::findById($this->db, $projectId, $orgId);
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
        $orgId          = (int) $user['org_id'];
        $nodeId         = (int) $this->request->post('node_id', 0);
        $okrTitle       = trim((string) $this->request->post('okr_title', ''));
        $okrDescription = trim((string) $this->request->post('okr_description', ''));

        // Verify node belongs to a diagram in a project owned by the user's org
        $stmt = $this->db->query(
            "SELECT dn.id FROM diagram_nodes dn
             JOIN strategy_diagrams sd ON dn.diagram_id = sd.id
             JOIN projects p ON sd.project_id = p.id
             WHERE dn.id = :node_id AND p.org_id = :org_id
             LIMIT 1",
            [':node_id' => $nodeId, ':org_id' => $orgId]
        );

        if (!$stmt->fetch()) {
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
        $orgId     = (int) $user['org_id'];
        $projectId = (int) $this->request->post('project_id', 0);

        $project = Project::findById($this->db, $projectId, $orgId);
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
    public function saveAllOkrs(): void
    {
        $user      = $this->auth->user();
        $orgId     = (int) $user['org_id'];
        $projectId = (int) $this->request->post('project_id', 0);

        $project = Project::findById($this->db, $projectId, $orgId);
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
            if ($nodeId <= 0) continue;

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
        // Remove ```mermaid ... ``` or ``` ... ``` wrappers
        $code = preg_replace('/^```(?:mermaid)?\s*/i', '', $code);
        $code = preg_replace('/\s*```\s*$/', '', $code);

        // Fix parentheses inside square bracket labels — Mermaid can't parse them
        // e.g., A[Market Expansion (AU Focus)] → A[Market Expansion - AU Focus]
        $code = preg_replace_callback('/\[([^\]]*)\]/', function($m) {
            return '[' . str_replace(['(', ')'], [' - ', ''], $m[1]) . ']';
        }, $code);

        // Fix special chars that break Mermaid: & → and, < > → removed
        $code = preg_replace_callback('/\[([^\]]*)\]/', function($m) {
            $label = str_replace(['&', '<', '>'], ['and', '', ''], $m[1]);
            return '[' . trim($label) . ']';
        }, $code);

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

        if (preg_match_all('/([A-Za-z][A-Za-z0-9_]*)\s*[\[\(\{]([^\]\)\}]+)[\]\)\}]/', $code, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $key   = $match[1];
                $label = trim($match[2]);

                // Skip the "graph" keyword and duplicates
                if (strtolower($key) === 'graph' || isset($seen[$key])) {
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
