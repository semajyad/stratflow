<?php
/**
 * PrioritisationController
 *
 * Handles the Prioritisation page: framework selection (RICE / WSJF),
 * per-item score saving via AJAX, AI-assisted baseline estimation,
 * and re-ranking work items by calculated score.
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
use StratFlow\Services\GeminiService;
use StratFlow\Services\Prompts\PrioritisationPrompt;

class PrioritisationController
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
     * Render the prioritisation page for a project.
     *
     * Loads work items ordered by priority, the project's selected framework,
     * and renders the prioritisation template.
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
        $framework = $project['selected_framework'] ?? 'rice';

        $this->response->render('prioritisation', [
            'user'                 => $user,
            'project'              => $project,
            'work_items'           => $workItems,
            'framework'            => $framework,
            'active_page'          => 'prioritisation',
            'has_evaluation_board' => Subscription::hasEvaluationBoard($this->db, $orgId),
            'flash_message'        => $_SESSION['flash_message'] ?? null,
            'flash_error'          => $_SESSION['flash_error']   ?? null,
        ], 'app');

        unset($_SESSION['flash_message'], $_SESSION['flash_error']);
    }

    /**
     * Update the project's selected prioritisation framework.
     *
     * Accepts POST with project_id and framework ('rice' or 'wsjf').
     * Redirects back to the prioritisation page.
     */
    public function selectFramework(): void
    {
        $user      = $this->auth->user();
        $orgId     = (int) $user['org_id'];
        $projectId = (int) $this->request->post('project_id', 0);
        $framework = (string) $this->request->post('framework', 'rice');

        if (!in_array($framework, ['rice', 'wsjf'], true)) {
            $framework = 'rice';
        }

        $project = Project::findById($this->db, $projectId, $orgId);
        if ($project === null) {
            $this->response->redirect('/app/home');
            return;
        }

        Project::update($this->db, $projectId, ['selected_framework' => $framework], $orgId);

        $_SESSION['flash_message'] = 'Framework updated to ' . strtoupper($framework) . '.';
        $this->response->redirect('/app/prioritisation?project_id=' . $projectId);
    }

    /**
     * Save scores for a single work item via AJAX.
     *
     * Accepts JSON body with item_id and scores object. Calculates the
     * final_score based on the current framework and persists everything.
     *
     * @return void JSON response with status and final_score
     */
    public function saveScores(): void
    {
        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];
        $body  = json_decode($this->request->body(), true);

        $itemId = (int) ($body['item_id'] ?? 0);
        $scores = $body['scores'] ?? [];

        if ($itemId === 0 || empty($scores)) {
            $this->response->json(['status' => 'error', 'message' => 'Missing item_id or scores'], 400);
            return;
        }

        // Verify org access
        $item = HLWorkItem::findById($this->db, $itemId);
        if ($item === null) {
            $this->response->json(['status' => 'error', 'message' => 'Item not found'], 404);
            return;
        }

        $project = Project::findById($this->db, (int) $item['project_id'], $orgId);
        if ($project === null) {
            $this->response->json(['status' => 'error', 'message' => 'Access denied'], 403);
            return;
        }

        $framework = $project['selected_framework'] ?? 'rice';

        // Calculate final score
        $finalScore = $this->calculateScore($framework, $scores);
        $scores['final_score'] = $finalScore;

        HLWorkItem::updateScores($this->db, $itemId, $scores);

        $this->response->json(['status' => 'ok', 'final_score' => round($finalScore, 1)]);
    }

    /**
     * Re-rank work items by their final_score descending.
     *
     * Loads items ordered by score, re-assigns priority_number 1..N,
     * and redirects back to the prioritisation page.
     */
    public function rerank(): void
    {
        $user      = $this->auth->user();
        $orgId     = (int) $user['org_id'];
        $projectId = (int) $this->request->post('project_id', 0);

        $project = Project::findById($this->db, $projectId, $orgId);
        if ($project === null) {
            $this->response->redirect('/app/home');
            return;
        }

        $items   = HLWorkItem::findByProjectIdRankedByScore($this->db, $projectId);
        $updates = [];

        foreach ($items as $index => $item) {
            $updates[] = [
                'id'              => (int) $item['id'],
                'priority_number' => $index + 1,
            ];
        }

        if (!empty($updates)) {
            HLWorkItem::batchUpdatePriority($this->db, $updates);
        }

        $_SESSION['flash_message'] = 'Work items re-ranked by score.';
        $this->response->redirect('/app/prioritisation?project_id=' . $projectId);
    }

    /**
     * Request AI baseline scores for all work items via AJAX.
     *
     * Sends work item titles and descriptions to Gemini with the
     * appropriate framework prompt, returns suggested scores.
     *
     * @return void JSON response with array of suggested scores
     */
    public function aiBaseline(): void
    {
        $user      = $this->auth->user();
        $orgId     = (int) $user['org_id'];
        $body      = json_decode($this->request->body(), true);
        $projectId = (int) ($body['project_id'] ?? 0);

        $project = Project::findById($this->db, $projectId, $orgId);
        if ($project === null) {
            $this->response->json(['status' => 'error', 'message' => 'Project not found'], 404);
            return;
        }

        $framework = $project['selected_framework'] ?? 'rice';
        $workItems = HLWorkItem::findByProjectId($this->db, $projectId);

        if (empty($workItems)) {
            $this->response->json(['status' => 'error', 'message' => 'No work items to score'], 400);
            return;
        }

        // Build input listing items
        $input = $this->buildAiInput($workItems);

        // Select prompt based on framework
        $prompt = $framework === 'wsjf'
            ? PrioritisationPrompt::WSJF_PROMPT
            : PrioritisationPrompt::RICE_PROMPT;

        try {
            $gemini     = new GeminiService($this->config);
            $suggestions = $gemini->generateJson($prompt, $input);
        } catch (\RuntimeException $e) {
            $this->response->json(['status' => 'error', 'message' => $e->getMessage()], 500);
            return;
        }

        if (!is_array($suggestions)) {
            $this->response->json(['status' => 'error', 'message' => 'AI returned unexpected format'], 500);
            return;
        }

        $this->response->json(['status' => 'ok', 'suggestions' => $suggestions]);
    }

    // ===========================
    // HELPERS
    // ===========================

    /**
     * Calculate the final prioritisation score from component scores.
     *
     * RICE:  (Reach * Impact * Confidence) / Effort
     * WSJF:  (Business Value + Time Criticality + Risk Reduction) / Job Size
     *
     * @param string $framework 'rice' or 'wsjf'
     * @param array  $scores    Component score values
     * @return float            Calculated final score
     */
    private function calculateScore(string $framework, array $scores): float
    {
        if ($framework === 'rice') {
            $reach      = (float) ($scores['rice_reach'] ?? 0);
            $impact     = (float) ($scores['rice_impact'] ?? 0);
            $confidence = (float) ($scores['rice_confidence'] ?? 0);
            $effort     = (float) ($scores['rice_effort'] ?? 1);

            return $effort > 0 ? ($reach * $impact * $confidence) / $effort : 0;
        }

        // WSJF
        $bv   = (float) ($scores['wsjf_business_value'] ?? 0);
        $tc   = (float) ($scores['wsjf_time_criticality'] ?? 0);
        $rr   = (float) ($scores['wsjf_risk_reduction'] ?? 0);
        $size = (float) ($scores['wsjf_job_size'] ?? 1);

        return $size > 0 ? ($bv + $tc + $rr) / $size : 0;
    }

    /**
     * Build a formatted text listing of work items for AI input.
     *
     * @param array $workItems Array of work item rows
     * @return string          Formatted text with IDs, titles, and descriptions
     */
    private function buildAiInput(array $workItems): string
    {
        $lines = [];

        foreach ($workItems as $item) {
            $desc = !empty($item['description'])
                ? ' - ' . mb_substr($item['description'], 0, 200)
                : '';
            $lines[] = "- ID: {$item['id']}, Title: {$item['title']}{$desc}";
        }

        return "## Work Items\n" . implode("\n", $lines);
    }
}
