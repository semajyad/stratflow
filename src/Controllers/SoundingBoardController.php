<?php

/**
 * SoundingBoardController
 *
 * Handles AI persona-based evaluations of screen content. Supports
 * running evaluations, viewing results, accepting/rejecting individual
 * persona responses, and browsing evaluation history.
 */

declare(strict_types=1);

namespace StratFlow\Controllers;

use StratFlow\Core\Auth;
use StratFlow\Core\Database;
use StratFlow\Core\Request;
use StratFlow\Core\Response;
use StratFlow\Models\EvaluationResult;
use StratFlow\Models\Subscription;
use StratFlow\Security\ProjectPolicy;
use StratFlow\Services\GeminiService;
use StratFlow\Services\PanelResolverService;
use StratFlow\Services\SoundingBoardService;

class SoundingBoardController
{
    // ===========================
    // PROPERTIES
    // ===========================

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
     * Run an AI evaluation with the selected panel and criticism level.
     *
     * Expects JSON body: project_id, panel_type, evaluation_level, screen_context, screen_content.
     * Returns JSON with the evaluation ID and per-persona results.
     */
    public function evaluate(): void
    {
        $body = json_decode($this->request->body(), true);
        if (!$body) {
            $this->response->json(['error' => 'Invalid JSON body'], 400);
            return;
        }

        $user      = $this->auth->user();
        $orgId     = (int) $user['org_id'];
        $projectId = (int) ($body['project_id'] ?? 0);
        $project = ProjectPolicy::findEditableProject($this->db, $user, $projectId);
        if ($project === null) {
            $this->response->json(['error' => 'Project not found'], 404);
            return;
        }

        // Check subscription has evaluation board access
        if (!Subscription::hasEvaluationBoard($this->db, $orgId)) {
            $this->response->json(['error' => 'Evaluation board not available on your plan'], 403);
            return;
        }

        $panelType       = $body['panel_type'] ?? 'executive';
        $evaluationLevel = $body['evaluation_level'] ?? 'devils_advocate';
        $screenContext   = $body['screen_context'] ?? '';
        $screenContent   = $body['screen_content'] ?? '';
        if (empty($screenContent)) {
            $this->response->json(['error' => 'No screen content provided'], 400);
            return;
        }

        // Validate evaluation level
        $validLevels = ['devils_advocate', 'red_teaming', 'gordon_ramsay'];
        if (!in_array($evaluationLevel, $validLevels, true)) {
            $evaluationLevel = 'devils_advocate';
        }

        // Load panel members: org-specific → system default → seeded
        $resolver          = new PanelResolverService($this->db);
        [$panel, $members] = $resolver->resolveWithMembers($orgId, $panelType);

        if (empty($members)) {
            $this->response->json(['error' => 'No panel members configured'], 400);
            return;
        }

        // Load custom level prompts if any
        $settings = SystemSettings::get($this->db);
        $customLevels = null;
        if (!empty($settings['evaluation_levels_json'])) {
            $customLevels = json_decode($settings['evaluation_levels_json'], true);
        }

        // Run the AI evaluation
        $gemini  = new GeminiService($this->config);
        $service = new SoundingBoardService($gemini);
        $results = $service->evaluate($members, $evaluationLevel, $screenContent, $customLevels);
// Store results
        $evalId = EvaluationResult::create($this->db, [
            'project_id'       => $projectId,
            'panel_id'         => (int) $panel['id'],
            'evaluation_level' => $evaluationLevel,
            'screen_context'   => $screenContext,
            'results_json'     => json_encode($results),
            'status'           => 'pending',
        ]);
        $this->response->json([
            'id'      => $evalId,
            'results' => $results,
        ]);
    }

    /**
     * Load a single evaluation result by ID.
     *
     * Returns JSON with the evaluation data and decoded results.
     *
     * @param int $id Evaluation result primary key
     */
    public function results($id): void
    {
        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];
        $eval = EvaluationResult::findById($this->db, (int) $id);
        if ($eval === null) {
            $this->response->json(['error' => 'Evaluation not found'], 404);
            return;
        }

        // Verify org access via project
        $project = ProjectPolicy::findViewableProject($this->db, $user, (int) $eval['project_id']);
        if ($project === null) {
            $this->response->json(['error' => 'Access denied'], 403);
            return;
        }

        $eval['results'] = json_decode($eval['results_json'], true);
        unset($eval['results_json']);
        $this->response->json($eval);
    }

    /**
     * Accept or reject an individual persona's response within an evaluation.
     *
     * Expects JSON body: member_index, action ('accept' or 'reject').
     * Updates the results_json in-place and recalculates overall status.
     *
     * @param int $id Evaluation result primary key
     */
    public function respond($id): void
    {
        $body = json_decode($this->request->body(), true);
        if (!$body) {
            $this->response->json(['error' => 'Invalid JSON body'], 400);
            return;
        }

        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];
        $eval = EvaluationResult::findById($this->db, (int) $id);
        if ($eval === null) {
            $this->response->json(['error' => 'Evaluation not found'], 404);
            return;
        }

        // Verify org access via project
        $project = ProjectPolicy::findEditableProject($this->db, $user, (int) $eval['project_id']);
        if ($project === null) {
            $this->response->json(['error' => 'Access denied'], 403);
            return;
        }

        $memberIndex = (int) ($body['member_index'] ?? -1);
        $action      = $body['action'] ?? '';
        if (!in_array($action, ['accept', 'reject'], true)) {
            $this->response->json(['error' => 'Invalid action — must be accept or reject'], 400);
            return;
        }

        $results = json_decode($eval['results_json'], true);
        if (!isset($results[$memberIndex])) {
            $this->response->json(['error' => 'Invalid member index'], 400);
            return;
        }

        // Update the individual response status
        $results[$memberIndex]['status'] = $action . 'ed';
// Update the stored JSON
        $this->db->query("UPDATE evaluation_results SET results_json = :results_json WHERE id = :id", [
                ':results_json' => json_encode($results),
                ':id'           => $id,
            ]);
// Determine overall status
        $statuses = array_column($results, 'status');
        $allResolved = !in_array('pending', $statuses, true) && !in_array('error', $statuses, true);
        if ($allResolved) {
            $allAccepted = array_unique($statuses) === ['accepted'];
            $overallStatus = $allAccepted ? 'accepted' : 'partial';
            EvaluationResult::updateStatus($this->db, $id, $overallStatus);
        }

        $this->response->json([
            'status'       => 'ok',
            'member_index' => $memberIndex,
            'action'       => $action,
        ]);
    }

    /**
     * Return evaluation history for a project.
     *
     * Expects query param: project_id. Returns JSON array of past evaluations.
     */
    public function history(): void
    {
        $user      = $this->auth->user();
        $orgId     = (int) $user['org_id'];
        $projectId = (int) $this->request->get('project_id', 0);
        $project = ProjectPolicy::findViewableProject($this->db, $user, $projectId);
        if ($project === null) {
            $this->response->json(['error' => 'Project not found'], 404);
            return;
        }

        $evaluations = EvaluationResult::findByProjectId($this->db, $projectId);
// Decode results_json for each evaluation
        foreach ($evaluations as &$eval) {
            $eval['results'] = json_decode($eval['results_json'], true);
            unset($eval['results_json']);
        }

        $this->response->json($evaluations);
    }
}
