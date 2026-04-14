<?php

/**
 * DriftController
 *
 * Handles the Governance Dashboard: drift detection results, baseline
 * management, alert acknowledgement/resolution, and governance queue
 * review (approve/reject proposed changes).
 */

declare(strict_types=1);

namespace StratFlow\Controllers;

use StratFlow\Core\Auth;
use StratFlow\Core\Database;
use StratFlow\Core\Request;
use StratFlow\Core\Response;
use StratFlow\Models\DriftAlert;
use StratFlow\Models\GovernanceItem;
use StratFlow\Models\HLWorkItem;
use StratFlow\Models\Organisation;
use StratFlow\Models\Project;
use StratFlow\Models\StrategicBaseline;
use StratFlow\Models\Subscription;
use StratFlow\Security\ProjectPolicy;
use StratFlow\Services\DriftDetectionService;
use StratFlow\Services\GeminiService;

class DriftController
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
     * Render the governance dashboard.
     *
     * Loads active alerts, pending governance items, and baseline history
     * for the selected project. Requires project_id query parameter.
     */
    public function dashboard(): void
    {
        $user      = $this->auth->user();
        $orgId     = (int) $user['org_id'];
        $projectId = (int) $this->request->get('project_id', 0);
        $project = ProjectPolicy::findViewableProject($this->db, $user, $projectId);
        if ($project === null) {
            $this->response->redirect('/app/home');
            return;
        }

        $alerts          = DriftAlert::findActiveByProjectId($this->db, $projectId);
        $governanceItems = GovernanceItem::findPendingByProjectId($this->db, $projectId);
        $baselines       = StrategicBaseline::findByProjectId($this->db, $projectId);
// Decode baseline snapshots for display
        $baselineData = array_map(function ($b) {

            $snapshot = json_decode($b['snapshot_json'], true);
            return [
                'id'              => $b['id'],
                'created_at'      => $b['created_at'],
                'work_item_count' => count($snapshot['work_items'] ?? []),
                'total_story_size' => $snapshot['stories']['total_size'] ?? 0,
                'story_count'     => $snapshot['stories']['total_count'] ?? 0,
            ];
        }, $baselines);
        $this->response->render('governance', [
            'user'             => $user,
            'project'          => $project,
            'alerts'           => $alerts,
            'governance_items' => $governanceItems,
            'baselines'        => $baselineData,
            'active_page'      => 'governance',
            'has_evaluation_board' => Subscription::hasEvaluationBoard($this->db, $orgId),
            'flash_message'    => $_SESSION['flash_message'] ?? null,
            'flash_error'      => $_SESSION['flash_error']   ?? null,
        ], 'app');
        unset($_SESSION['flash_message'], $_SESSION['flash_error']);
    }

    /**
     * Create a new baseline snapshot for the project.
     *
     * POST handler. Captures current work items and story metrics
     * as a point-in-time reference for future drift detection.
     */
    public function createBaseline(): void
    {
        $user      = $this->auth->user();
        $orgId     = (int) $user['org_id'];
        $projectId = (int) $this->request->post('project_id', 0);
        $project = ProjectPolicy::findEditableProject($this->db, $user, $projectId);
        if ($project === null) {
            $this->response->redirect('/app/home');
            return;
        }

        $service = new DriftDetectionService($this->db);
        $service->createBaseline($projectId);
        $_SESSION['flash_message'] = 'Strategic baseline created successfully.';
        $this->response->redirect('/app/governance?project_id=' . $projectId);
    }

    /**
     * Run drift detection against the latest baseline.
     *
     * POST handler. Loads the org's capacity_tripwire_percent setting,
     * creates a DriftDetectionService with Gemini for alignment checks,
     * and runs full drift detection.
     */
    public function runDetection(): void
    {
        $user      = $this->auth->user();
        $orgId     = (int) $user['org_id'];
        $projectId = (int) $this->request->post('project_id', 0);
        $project = ProjectPolicy::findEditableProject($this->db, $user, $projectId);
        if ($project === null) {
            $this->response->redirect('/app/home');
            return;
        }

        // Load org settings for threshold
        $org = Organisation::findById($this->db, $orgId);
        $settings = json_decode($org['settings_json'] ?? '{}', true);
        $threshold = ((int) ($settings['capacity_tripwire_percent'] ?? 20)) / 100;
// Create service with Gemini for alignment checks
        $gemini = null;
        try {
            $gemini = new GeminiService($this->config);
        } catch (\Exception $e) {
        // Continue without AI alignment checks
        }

        $service = new DriftDetectionService($this->db, $gemini);
        $drifts = $service->detectDrift($projectId, $threshold);
        $count = count($drifts);
        if ($count > 0) {
            $_SESSION['flash_message'] = "{$count} drift issue(s) detected.";
        } else {
            $_SESSION['flash_message'] = 'No drift detected. Project is on track.';
        }

        $this->response->redirect('/app/governance?project_id=' . $projectId);
    }

    /**
     * Acknowledge or resolve a drift alert.
     *
     * POST handler. Accepts action='acknowledge' or action='resolve'.
     *
     * @param int $id Alert primary key from route parameter
     */
    public function acknowledgeAlert($id): void
    {
        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];
        $alert = DriftAlert::findById($this->db, (int) $id);
        if ($alert === null) {
            $this->response->redirect('/app/home');
            return;
        }

        $project = ProjectPolicy::findEditableProject($this->db, $user, (int) $alert['project_id']);
        if ($project === null) {
            $this->response->redirect('/app/home');
            return;
        }

        $action     = $this->request->post('action', 'acknowledge');
        $status     = $action === 'resolve' ? 'resolved' : 'acknowledged';
        $redirectTo = $this->request->post('redirect_to', '');
        DriftAlert::updateStatus($this->db, (int) $id, $status);
        $_SESSION['flash_message'] = 'Alert ' . $status . '.';
        $dest = ($redirectTo !== '' && str_starts_with($redirectTo, '/app/'))
            ? $redirectTo
            : '/app/governance?project_id=' . $alert['project_id'];
        $this->response->redirect($dest);
    }

    /**
     * Approve or reject a governance queue item.
     *
     * POST handler. If approved and the item is a scope change,
     * clears the requires_review flag on the related work item.
     *
     * @param int $id Governance item primary key from route parameter
     */
    public function reviewChange($id): void
    {
        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];
        $item = GovernanceItem::findById($this->db, (int) $id);
        if ($item === null) {
            $this->response->redirect('/app/home');
            return;
        }

        $project = ProjectPolicy::findEditableProject($this->db, $user, (int) $item['project_id']);
        if ($project === null) {
            $this->response->redirect('/app/home');
            return;
        }

        $action     = $this->request->post('action', 'approve');
        $status     = $action === 'reject' ? 'rejected' : 'approved';
        $redirectTo = $this->request->post('redirect_to', '');
        GovernanceItem::updateStatus($this->db, (int) $id, $status, (int) $user['id']);
// If approved and it's a scope change, clear requires_review on the related item
        if ($status === 'approved') {
            $details = json_decode($item['proposed_change_json'], true);
            $relatedItemId = $details['parent_item_id'] ?? $details['work_item_id'] ?? null;
            if ($relatedItemId) {
                HLWorkItem::update($this->db, (int) $relatedItemId, ['requires_review' => 0]);
            }
        }

        $_SESSION['flash_message'] = 'Change ' . $status . '.';
        $dest = ($redirectTo !== '' && str_starts_with($redirectTo, '/app/'))
            ? $redirectTo
            : '/app/governance?project_id=' . $item['project_id'];
        $this->response->redirect($dest);
    }
}
