<?php
/**
 * RiskController
 *
 * Handles the Risk Modelling page: AI-generated risk identification from
 * work items, manual CRUD, linked work items, AI mitigation strategies,
 * and a 5x5 likelihood/impact heatmap.
 */

declare(strict_types=1);

namespace StratFlow\Controllers;

use StratFlow\Core\Auth;
use StratFlow\Core\Database;
use StratFlow\Core\Request;
use StratFlow\Core\Response;
use StratFlow\Models\HLWorkItem;
use StratFlow\Models\Project;
use StratFlow\Models\Risk;
use StratFlow\Models\RiskItemLink;
use StratFlow\Models\Subscription;
use StratFlow\Models\User;
use StratFlow\Security\ProjectPolicy;
use StratFlow\Services\GeminiService;
use StratFlow\Services\Prompts\RiskPrompt;

class RiskController
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
     * Render the risk modelling page for a project.
     *
     * Loads all risks with their linked work items, builds heatmap data,
     * and provides the full work item list for the link multi-select.
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

        $risks     = Risk::findByProjectId($this->db, $projectId);
        $workItems = HLWorkItem::findByProjectId($this->db, $projectId);
        $orgUsers  = User::findByOrgId($this->db, $orgId);

        // Build work item lookup by ID for display
        $workItemMap = [];
        foreach ($workItems as $wi) {
            $workItemMap[(int) $wi['id']] = $wi;
        }

        // Attach linked work item IDs and titles to each risk
        foreach ($risks as &$risk) {
            $linkedIds = RiskItemLink::findByRiskId($this->db, (int) $risk['id']);
            $risk['linked_item_ids'] = $linkedIds;
            $risk['linked_items']    = [];
            foreach ($linkedIds as $wiId) {
                if (isset($workItemMap[(int) $wiId])) {
                    $risk['linked_items'][] = $workItemMap[(int) $wiId];
                }
            }
        }
        unset($risk);

        // Build 5x5 heatmap grid: [likelihood][impact] => count
        $heatmap = [];
        for ($l = 1; $l <= 5; $l++) {
            for ($i = 1; $i <= 5; $i++) {
                $heatmap[$l][$i] = 0;
            }
        }
        foreach ($risks as $r) {
            $l = max(1, min(5, (int) $r['likelihood']));
            $i = max(1, min(5, (int) $r['impact']));
            $heatmap[$l][$i]++;
        }

        $this->response->render('risks', [
            'user'                 => $user,
            'project'              => $project,
            'risks'                => $risks,
            'work_items'           => $workItems,
            'work_item_map'        => $workItemMap,
            'org_users'            => $orgUsers,
            'heatmap'              => $heatmap,
            'active_page'          => 'risks',
            'has_evaluation_board' => Subscription::hasEvaluationBoard($this->db, $orgId),
            'flash_message'        => $_SESSION['flash_message'] ?? null,
            'flash_error'          => $_SESSION['flash_error']   ?? null,
        ], 'app');

        unset($_SESSION['flash_message'], $_SESSION['flash_error']);
    }

    /**
     * Auto-generate risks from work items using Gemini AI.
     *
     * Loads all High Level work items, sends to Gemini with GENERATE_PROMPT,
     * creates Risk records, and links them to matching work items.
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

        $workItems = HLWorkItem::findByProjectId($this->db, $projectId);
        if (empty($workItems)) {
            $_SESSION['flash_error'] = 'No work items to analyse. Generate work items first.';
            $this->response->redirect('/app/risks?project_id=' . $projectId);
            return;
        }

        // Build input text listing work items
        $lines = [];
        foreach ($workItems as $wi) {
            $desc = !empty($wi['description'])
                ? ' - ' . mb_substr($wi['description'], 0, 200)
                : '';
            $lines[] = "- {$wi['title']}{$desc}";
        }
        $input = "## Work Items\n" . implode("\n", $lines);

        try {
            $gemini    = new GeminiService($this->config);
            $generated = $gemini->generateJson(RiskPrompt::GENERATE_PROMPT, $input);
        } catch (\RuntimeException $e) {
            $_SESSION['flash_error'] = 'AI generation failed: ' . $e->getMessage();
            $this->response->redirect('/app/risks?project_id=' . $projectId);
            return;
        }

        if (!is_array($generated)) {
            $_SESSION['flash_error'] = 'AI returned unexpected format.';
            $this->response->redirect('/app/risks?project_id=' . $projectId);
            return;
        }

        // Build title-to-ID map for linking
        $titleMap = [];
        foreach ($workItems as $wi) {
            $titleMap[mb_strtolower(trim($wi['title']))] = (int) $wi['id'];
        }

        $created = 0;
        foreach ($generated as $riskData) {
            if (empty($riskData['title'])) {
                continue;
            }

            $riskId = Risk::create($this->db, [
                'project_id'  => $projectId,
                'title'       => $riskData['title'],
                'description' => $riskData['description'] ?? null,
                'likelihood'  => max(1, min(5, (int) ($riskData['likelihood'] ?? 3))),
                'impact'      => max(1, min(5, (int) ($riskData['impact'] ?? 3))),
                'priority'    => ($riskData['likelihood'] ?? 3) * ($riskData['impact'] ?? 3),
            ]);

            // Match linked_items titles to work item IDs
            $linkedItemIds = [];
            foreach (($riskData['linked_items'] ?? []) as $linkedTitle) {
                $key = mb_strtolower(trim($linkedTitle));
                if (isset($titleMap[$key])) {
                    $linkedItemIds[] = $titleMap[$key];
                }
            }

            if (!empty($linkedItemIds)) {
                RiskItemLink::createLinks($this->db, $riskId, $linkedItemIds);
            }

            $created++;
        }

        $_SESSION['flash_message'] = "Generated {$created} risk(s) from AI analysis.";
        $this->response->redirect('/app/risks?project_id=' . $projectId);
    }

    /**
     * Create a new risk from the manual form submission.
     *
     * Accepts POST with title, description, likelihood, impact,
     * and optional work_item_ids[] for linking.
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

        $title       = trim((string) $this->request->post('title', ''));
        $description = trim((string) $this->request->post('description', ''));
        $likelihood  = max(1, min(5, (int) $this->request->post('likelihood', 3)));
        $impact      = max(1, min(5, (int) $this->request->post('impact', 3)));
        $ownerRaw    = $this->request->post('owner_user_id', '');
        $ownerUserId = $ownerRaw !== '' ? (int) $ownerRaw : null;

        if ($title === '') {
            $_SESSION['flash_error'] = 'Risk title is required.';
            $this->response->redirect('/app/risks?project_id=' . $projectId);
            return;
        }

        $riskId = Risk::create($this->db, [
            'project_id'   => $projectId,
            'title'        => $title,
            'description'  => $description ?: null,
            'likelihood'   => $likelihood,
            'impact'       => $impact,
            'priority'     => $likelihood * $impact,
            'owner_user_id' => $ownerUserId,
        ]);

        // Link work items
        $workItemIds = $this->request->post('work_item_ids', []);
        if (is_array($workItemIds) && !empty($workItemIds)) {
            RiskItemLink::createLinks($this->db, $riskId, array_map('intval', $workItemIds));
        }

        $_SESSION['flash_message'] = 'Risk created successfully.';
        $this->response->redirect('/app/risks?project_id=' . $projectId);
    }

    /**
     * Update an existing risk from the edit form.
     *
     * Re-creates work item links (delete old, create new).
     *
     * @param int $id Risk ID from the URL
     */
    public function update($id): void
    {
        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];

        $risk = Risk::findById($this->db, (int) $id);
        if ($risk === null) {
            $this->response->redirect('/app/home');
            return;
        }

        $projectId = (int) $risk['project_id'];
        $project   = ProjectPolicy::findEditableProject($this->db, $user, $projectId);
        if ($project === null) {
            $this->response->redirect('/app/home');
            return;
        }

        $title       = trim((string) $this->request->post('title', ''));
        $description = trim((string) $this->request->post('description', ''));
        $likelihood  = max(1, min(5, (int) $this->request->post('likelihood', 3)));
        $impact      = max(1, min(5, (int) $this->request->post('impact', 3)));
        $ownerRaw    = $this->request->post('owner_user_id', '');
        $ownerUserId = $ownerRaw !== '' ? (int) $ownerRaw : null;

        if ($title === '') {
            $_SESSION['flash_error'] = 'Risk title is required.';
            $this->response->redirect('/app/risks?project_id=' . $projectId);
            return;
        }

        $allowedRoam = ['resolved', 'owned', 'accepted', 'mitigated'];
        $roamRaw     = strtolower(trim((string) $this->request->post('roam_status', '')));
        $roamStatus  = in_array($roamRaw, $allowedRoam, true) ? $roamRaw : null;

        Risk::update($this->db, $id, [
            'title'         => $title,
            'description'   => $description ?: null,
            'likelihood'    => $likelihood,
            'impact'        => $impact,
            'priority'      => $likelihood * $impact,
            'owner_user_id' => $ownerUserId,
            'roam_status'   => $roamStatus,
        ]);

        // Re-create links
        RiskItemLink::deleteByRiskId($this->db, $id);
        $workItemIds = $this->request->post('work_item_ids', []);
        if (is_array($workItemIds) && !empty($workItemIds)) {
            RiskItemLink::createLinks($this->db, $id, array_map('intval', $workItemIds));
        }

        $_SESSION['flash_message'] = 'Risk updated successfully.';
        $this->response->redirect('/app/risks?project_id=' . $projectId);
    }

    /**
     * Close a risk (sets status to 'closed').
     */
    public function close($id): void
    {
        $user      = $this->auth->user();
        $orgId     = (int) $user['org_id'];
        $riskId    = (int) $id;
        $projectId = (int) $this->request->post('project_id', 0);

        $risk = Risk::findById($this->db, $riskId);
        if ($risk === null || (int) $risk['project_id'] !== $projectId) {
            $this->response->redirect('/app/risks?project_id=' . $projectId);
            return;
        }

        // Verify project belongs to org
        $project = ProjectPolicy::findEditableProject($this->db, $user, $projectId);
        if ($project === null) {
            $this->response->redirect('/app/home');
            return;
        }

        Risk::update($this->db, $riskId, ['status' => 'closed']);
        $this->response->redirect('/app/risks?project_id=' . $projectId);
    }

    /**
     * Set the ROAM status on a risk (Resolved/Owned/Accepted/Mitigated).
     */
    public function setRoam($id): void
    {
        $user      = $this->auth->user();
        $orgId     = (int) $user['org_id'];
        $riskId    = (int) $id;
        $projectId = (int) $this->request->post('project_id', 0);

        $allowed = ['resolved', 'owned', 'accepted', 'mitigated'];
        $roam    = strtolower(trim((string) $this->request->post('roam_status', '')));

        $project = ProjectPolicy::findEditableProject($this->db, $user, $projectId);
        if ($project === null) {
            $this->response->redirect('/app/home');
            return;
        }

        if (in_array($roam, $allowed, true)) {
            Risk::update($this->db, $riskId, ['roam_status' => $roam]);
        }

        $this->response->redirect('/app/risks?project_id=' . $projectId);
    }

    /**
     * Delete a risk by ID. CASCADE removes associated links.
     *
     * @param int $id Risk ID from the URL
     */
    public function delete($id): void
    {
        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];

        $risk = Risk::findById($this->db, (int) $id);
        if ($risk === null) {
            $this->response->redirect('/app/home');
            return;
        }

        $projectId = (int) $risk['project_id'];
        $project   = ProjectPolicy::findEditableProject($this->db, $user, $projectId);
        if ($project === null) {
            $this->response->redirect('/app/home');
            return;
        }

        Risk::delete($this->db, (int) $id);

        $_SESSION['flash_message'] = 'Risk deleted.';
        $this->response->redirect('/app/risks?project_id=' . $projectId);
    }

    /**
     * Generate an AI mitigation strategy for a risk (AJAX).
     *
     * Loads the risk and its linked work items, builds the MITIGATION_PROMPT
     * with substitutions, calls Gemini, and saves the mitigation text.
     *
     * @param int $id Risk ID from the URL
     */
    public function generateMitigation($id): void
    {
        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];

        $risk = Risk::findById($this->db, (int) $id);
        if ($risk === null) {
            $this->response->json(['status' => 'error', 'message' => 'Risk not found'], 404);
            return;
        }

        $project = ProjectPolicy::findEditableProject($this->db, $user, (int) $risk['project_id']);
        if ($project === null) {
            $this->response->json(['status' => 'error', 'message' => 'Access denied'], 403);
            return;
        }

        // Load linked work items
        $linkedIds   = RiskItemLink::findByRiskId($this->db, $id);
        $linkedNames = [];
        foreach ($linkedIds as $wiId) {
            $wi = HLWorkItem::findById($this->db, (int) $wiId);
            if ($wi !== null) {
                $linkedNames[] = $wi['title'];
            }
        }

        // Build prompt with substitutions
        $prompt = str_replace(
            ['{title}', '{description}', '{likelihood}', '{impact}', '{linked_items}'],
            [
                $risk['title'],
                $risk['description'] ?? 'No description',
                (string) $risk['likelihood'],
                (string) $risk['impact'],
                !empty($linkedNames) ? implode(', ', $linkedNames) : 'None',
            ],
            RiskPrompt::MITIGATION_PROMPT
        );

        try {
            $gemini     = new GeminiService($this->config);
            $mitigation = $gemini->generate($prompt, '');
            $mitigation = trim($mitigation);
        } catch (\RuntimeException $e) {
            $this->response->json(['status' => 'error', 'message' => $e->getMessage()], 500);
            return;
        }

        // Save mitigation to the risk
        Risk::update($this->db, $id, ['mitigation' => $mitigation]);

        $this->response->json(['status' => 'ok', 'mitigation' => $mitigation]);
    }
}
