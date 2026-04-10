<?php
/**
 * ExecutiveController
 *
 * Renders the org-wide Executive Dashboard: portfolio status, backlog health,
 * sprint velocity, risk register, drift alerts, governance queue, integration
 * health, subscription state, and recent audit activity — all rolled up across
 * every project in the authenticated user's organisation.
 *
 * All queries are scoped to $orgId (from $_SESSION['user']['org_id']); no
 * cross-org data ever escapes. The route is gated by ExecutiveMiddleware so
 * only superadmin or users with has_executive_access = 1 can reach this page.
 */

declare(strict_types=1);

namespace StratFlow\Controllers;

use StratFlow\Core\Auth;
use StratFlow\Core\Database;
use StratFlow\Core\Request;
use StratFlow\Core\Response;
use StratFlow\Models\KeyResult;

class ExecutiveController
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

    /**
     * Render the Executive Dashboard.
     *
     * Runs ~10 aggregate queries against the org's data and passes the
     * results to the executive template. All queries are org-scoped.
     */
    public function dashboard(): void
    {
        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];

        // ── 1. Portfolio status ───────────────────────────────────────────────
        $portfolioRows = $this->db->query(
            'SELECT status, COUNT(*) AS cnt FROM projects WHERE org_id = :oid GROUP BY status',
            [':oid' => $orgId]
        )->fetchAll();

        $portfolio = ['draft' => 0, 'active' => 0, 'completed' => 0];
        foreach ($portfolioRows as $row) {
            $portfolio[$row['status']] = (int) $row['cnt'];
        }
        $portfolio['total'] = array_sum($portfolio);

        // ── 2. Backlog health (work items across all org projects) ────────────
        $backlogRows = $this->db->query(
            'SELECT hwi.status, COUNT(*) AS cnt
               FROM hl_work_items hwi
               JOIN projects p ON hwi.project_id = p.id
              WHERE p.org_id = :oid
              GROUP BY hwi.status',
            [':oid' => $orgId]
        )->fetchAll();

        $backlog = ['backlog' => 0, 'in_progress' => 0, 'in_review' => 0, 'done' => 0];
        foreach ($backlogRows as $row) {
            $key = $row['status'];
            $backlog[$key] = (int) $row['cnt'];
        }
        $backlog['total'] = array_sum($backlog);

        // ── 3. Top 5 highest-priority work items ──────────────────────────────
        $topItems = $this->db->query(
            'SELECT hwi.title, hwi.final_score, hwi.priority_number, hwi.status, p.name AS project_name
               FROM hl_work_items hwi
               JOIN projects p ON hwi.project_id = p.id
              WHERE p.org_id = :oid
                AND hwi.status != \'done\'
              ORDER BY hwi.final_score DESC
              LIMIT 5',
            [':oid' => $orgId]
        )->fetchAll();

        // ── 4. Sprint velocity (last 4 completed sprints) ─────────────────────
        $velocityRows = $this->db->query(
            'SELECT s.name AS sprint_name, s.end_date,
                    COALESCE(SUM(us.size), 0) AS total_points
               FROM sprints s
               JOIN projects p ON s.project_id = p.id
               LEFT JOIN sprint_stories ss ON ss.sprint_id = s.id
               LEFT JOIN user_stories us ON us.id = ss.user_story_id AND us.status = \'done\'
              WHERE p.org_id = :oid
                AND s.status = \'completed\'
              GROUP BY s.id, s.name, s.end_date
              ORDER BY s.end_date DESC
              LIMIT 4',
            [':oid' => $orgId]
        )->fetchAll();
        // Reverse so chart reads oldest → newest
        $velocity = array_reverse($velocityRows);

        // ── 5. Active sprint capacity ─────────────────────────────────────────
        $activeSprints = $this->db->query(
            'SELECT s.name AS sprint_name, s.team_capacity AS capacity,
                    p.name AS project_name,
                    COALESCE(SUM(us.size), 0) AS allocated_points
               FROM sprints s
               JOIN projects p ON s.project_id = p.id
               LEFT JOIN sprint_stories ss ON ss.sprint_id = s.id
               LEFT JOIN user_stories us ON us.id = ss.user_story_id
              WHERE p.org_id = :oid
                AND s.status = \'active\'
              GROUP BY s.id, s.name, s.team_capacity, p.name
              ORDER BY p.name, s.name',
            [':oid' => $orgId]
        )->fetchAll();

        // ── 6. Risk summary by priority band ──────────────────────────────────
        // Priority stored as likelihood * impact (1–25); bands: low <5, medium 5–14, high ≥15
        $riskSummaryRows = $this->db->query(
            'SELECT
                SUM(CASE WHEN (likelihood * impact) >= 15 THEN 1 ELSE 0 END) AS high,
                SUM(CASE WHEN (likelihood * impact) BETWEEN 5 AND 14 THEN 1 ELSE 0 END) AS medium,
                SUM(CASE WHEN (likelihood * impact) < 5 THEN 1 ELSE 0 END) AS low
               FROM risks r
               JOIN projects p ON r.project_id = p.id
              WHERE p.org_id = :oid',
            [':oid' => $orgId]
        )->fetch();

        $riskSummary = [
            'high'   => (int) ($riskSummaryRows['high']   ?? 0),
            'medium' => (int) ($riskSummaryRows['medium'] ?? 0),
            'low'    => (int) ($riskSummaryRows['low']    ?? 0),
        ];
        $riskSummary['total'] = array_sum($riskSummary);

        // ── 7. Drift alert counts by severity (active only) ───────────────────
        $driftRows = $this->db->query(
            'SELECT da.severity, COUNT(*) AS cnt
               FROM drift_alerts da
               JOIN projects p ON da.project_id = p.id
              WHERE p.org_id = :oid
                AND da.status = \'active\'
              GROUP BY da.severity',
            [':oid' => $orgId]
        )->fetchAll();

        $driftAlerts = ['info' => 0, 'warning' => 0, 'critical' => 0];
        foreach ($driftRows as $row) {
            $driftAlerts[$row['severity']] = (int) $row['cnt'];
        }
        $driftAlerts['total'] = array_sum($driftAlerts);

        // ── 8. Governance queue depth ─────────────────────────────────────────
        $govRow = $this->db->query(
            'SELECT COUNT(*) AS cnt
               FROM governance_queue gq
               JOIN projects p ON gq.project_id = p.id
              WHERE p.org_id = :oid
                AND gq.status = \'pending\'',
            [':oid' => $orgId]
        )->fetch();
        $governanceQueueDepth = (int) ($govRow['cnt'] ?? 0);

        // ── 9. Integration health ─────────────────────────────────────────────
        $integrations = $this->db->query(
            'SELECT display_name AS name, provider, status, last_sync_at, error_count
               FROM integrations
              WHERE org_id = :oid
              ORDER BY display_name',
            [':oid' => $orgId]
        )->fetchAll();

        // ── 10. Subscription / seat usage ────────────────────────────────────
        $subscription = $this->db->query(
            'SELECT plan_type, status, expires_at, user_seat_limit
               FROM subscriptions
              WHERE org_id = :oid
              LIMIT 1',
            [':oid' => $orgId]
        )->fetch() ?: [];

        $seatUsedRow = $this->db->query(
            'SELECT COUNT(*) AS cnt FROM users WHERE org_id = :oid AND is_active = 1',
            [':oid' => $orgId]
        )->fetch();
        $seatsUsed  = (int) ($seatUsedRow['cnt'] ?? 0);
        $seatLimit  = (int) ($subscription['user_seat_limit'] ?? 0);

        // ── Table A: Top 10 active risks ──────────────────────────────────────
        $topRisks = $this->db->query(
            'SELECT r.title, r.likelihood, r.impact, (r.likelihood * r.impact) AS priority,
                    p.name AS project_name
               FROM risks r
               JOIN projects p ON r.project_id = p.id
              WHERE p.org_id = :oid
              ORDER BY priority DESC
              LIMIT 10',
            [':oid' => $orgId]
        )->fetchAll();

        // ── Table B: Active critical drift alerts ─────────────────────────────
        $criticalAlerts = $this->db->query(
            'SELECT da.alert_type, da.details_json, da.created_at, p.name AS project_name
               FROM drift_alerts da
               JOIN projects p ON da.project_id = p.id
              WHERE p.org_id = :oid
                AND da.status = \'active\'
                AND da.severity = \'critical\'
              ORDER BY da.created_at DESC
              LIMIT 10',
            [':oid' => $orgId]
        )->fetchAll();

        // ── Table C: Recent audit events ──────────────────────────────────────
        $recentAudit = $this->db->query(
            'SELECT al.event_type, al.created_at, al.ip_address,
                    u.full_name AS actor_name, al.details_json
               FROM audit_logs al
               INNER JOIN users u ON al.user_id = u.id
              WHERE u.org_id = :oid
              ORDER BY al.created_at DESC
              LIMIT 10',
            [':oid' => $orgId]
        )->fetchAll();

        $this->response->render('executive', [
            'user'                 => $user,
            'active_page'          => 'executive',
            // KPI cards
            'portfolio'            => $portfolio,
            'backlog'              => $backlog,
            'top_items'            => $topItems,
            'velocity'             => $velocity,
            'active_sprints'       => $activeSprints,
            'risk_summary'         => $riskSummary,
            'drift_alerts'         => $driftAlerts,
            'governance_queue'     => $governanceQueueDepth,
            'integrations'         => $integrations,
            // Subscription banner
            'subscription'         => $subscription,
            'seats_used'           => $seatsUsed,
            'seat_limit'           => $seatLimit,
            // Detail tables
            'top_risks'            => $topRisks,
            'critical_alerts'      => $criticalAlerts,
            'recent_audit'         => $recentAudit,
            // Flash
            'flash_message'        => $_SESSION['flash_message'] ?? null,
            'flash_error'          => $_SESSION['flash_error']   ?? null,
        ], 'app');

        unset($_SESSION['flash_message'], $_SESSION['flash_error']);
    }

    /**
     * Render the per-project Executive Dashboard (OKR/KR view).
     *
     * Loads all OKR work items for the given project, their associated Key
     * Results, risks, and dependency data, then renders the executive-project
     * template. Access is org-scoped; a 404 is returned if the project does
     * not belong to the authenticated user's organisation.
     *
     * @param int $id The project ID from the route parameter.
     */
    public function projectDashboard(int $id): void
    {
        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];

        // Verify project belongs to this org
        $project = $this->db->query(
            "SELECT id, name, updated_at FROM projects WHERE id = :id AND org_id = :oid LIMIT 1",
            [':id' => $id, ':oid' => $orgId]
        )->fetch();

        if ($project === false) {
            http_response_code(404);
            $this->response->render('errors/404', [], 'app');
            return;
        }

        // Project selector (all active projects in org)
        $projects = $this->db->query(
            "SELECT id, name FROM projects WHERE org_id = :oid AND status != 'deleted' ORDER BY name ASC",
            [':oid' => $orgId]
        )->fetchAll();

        // OKR work items for this project (items with an okr_title)
        $okrItems = $this->db->query(
            "SELECT hwi.id, hwi.title, hwi.okr_title, hwi.okr_description,
                    hwi.priority_number, hwi.status
               FROM hl_work_items hwi
              WHERE hwi.project_id = :pid
                AND hwi.okr_title IS NOT NULL
                AND hwi.okr_title != ''
              ORDER BY hwi.priority_number ASC",
            [':pid' => $id]
        )->fetchAll();

        // KRs per work item
        $krRows = KeyResult::findByProjectOkrs($this->db, $id, $orgId);
        $krsByItemId = [];
        foreach ($krRows as $kr) {
            $krsByItemId[(int) $kr['work_item_id']][] = $kr;
        }

        // Contributions per KR (for the expandable PR list)
        $contributionsByKrId = [];
        foreach ($krRows as $kr) {
            $contribs = \StratFlow\Models\KeyResultContribution::findByKeyResultId(
                $this->db, (int) $kr['id'], $orgId
            );
            if (!empty($contribs)) {
                $contributionsByKrId[(int) $kr['id']] = $contribs;
            }
        }

        // Risks per work item (via risk_item_links)
        $riskRows = $this->db->query(
            "SELECT ril.work_item_id, r.title, r.likelihood, r.impact,
                    (r.likelihood * r.impact) AS priority
               FROM risk_item_links ril
               JOIN risks r ON ril.risk_id = r.id
               JOIN projects p ON r.project_id = p.id
              WHERE p.id = :pid AND p.org_id = :oid",
            [':pid' => $id, ':oid' => $orgId]
        )->fetchAll();
        $risksByItemId = [];
        foreach ($riskRows as $risk) {
            $risksByItemId[(int) $risk['work_item_id']][] = $risk;
        }

        // Dependencies per work item
        $depRows = $this->db->query(
            "SELECT hid.item_id, hid.depends_on_id, hid.dependency_type,
                    blocker.title AS blocker_title, blocker.status AS blocker_status,
                    blocked.title AS blocked_title, blocked.status AS blocked_status
               FROM hl_item_dependencies hid
               JOIN hl_work_items blocker ON hid.depends_on_id = blocker.id
               JOIN projects bp ON blocker.project_id = bp.id
               JOIN hl_work_items blocked ON hid.item_id = blocked.id
               JOIN projects bpd ON blocked.project_id = bpd.id
              WHERE (blocker.project_id = :pid OR blocked.project_id = :pid)
                AND bp.org_id = :oid AND bpd.org_id = :oid",
            [':pid' => $id, ':oid' => $orgId]
        )->fetchAll();
        $depsByItemId = [];
        foreach ($depRows as $dep) {
            $depsByItemId[(int) $dep['item_id']]['blocked_by'][]   = $dep;
            $depsByItemId[(int) $dep['depends_on_id']]['blocks'][] = $dep;
        }

        // Overall health summary
        $healthCounts = ['on_track' => 0, 'at_risk' => 0, 'off_track' => 0];
        foreach ($krRows as $kr) {
            $s = $kr['status'];
            if (isset($healthCounts[$s])) {
                $healthCounts[$s]++;
            }
        }

        $this->response->render('executive-project', [
            'user'                   => $user,
            'active_page'            => 'executive',
            'project'                => $project,
            'projects'               => $projects,
            'okr_items'              => $okrItems,
            'krs_by_item_id'         => $krsByItemId,
            'risks_by_item'          => $risksByItemId,
            'deps_by_item'           => $depsByItemId,
            'health_counts'          => $healthCounts,
            'contributions_by_kr_id' => $contributionsByKrId,
            'flash_message'          => $_SESSION['flash_message'] ?? null,
            'flash_error'            => $_SESSION['flash_error']   ?? null,
        ], 'app');

        unset($_SESSION['flash_message'], $_SESSION['flash_error']);
    }
}
