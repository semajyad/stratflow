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

        // ── 8. Governance queue depth + items ─────────────────────────────────
        $govItems = $this->db->query(
            'SELECT gq.id, gq.change_type, gq.proposed_change_json, gq.created_at,
                    gq.project_id, p.name AS project_name
               FROM governance_queue gq
               JOIN projects p ON gq.project_id = p.id
              WHERE p.org_id = :oid
                AND gq.status = \'pending\'
              ORDER BY gq.created_at DESC
              LIMIT 20',
            [':oid' => $orgId]
        )->fetchAll();
        $governanceQueueDepth = count($govItems);

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

        // ── OKR / KR health across all projects ──────────────────────────────
        // Source: diagram_nodes — these are the OKRs set on the strategy roadmap.
        // The KR text lines (KR1:, KR2:...) are stored in okr_description as free text.
        // We count KR lines per node to show a "X KRs" badge, and also try to enrich
        // with structured key_results rows if they exist.
        $okrItems = [];
        try {
            $okrItems = $this->db->query(
                "SELECT dn.id AS item_id, dn.node_key, dn.okr_title, dn.okr_description,
                        p.id AS project_id, p.name AS project_name,
                        0 AS on_track, 0 AS at_risk, 0 AS off_track,
                        0 AS not_started, 0 AS achieved, 0 AS kr_count
                   FROM diagram_nodes dn
                   JOIN strategy_diagrams sd ON sd.id = dn.diagram_id
                   JOIN projects p           ON p.id  = sd.project_id
                  WHERE p.org_id = :oid
                    AND dn.okr_title IS NOT NULL
                    AND TRIM(dn.okr_title) != ''
                  ORDER BY p.name ASC, dn.id ASC",
                [':oid' => $orgId]
            )->fetchAll();
        } catch (\Throwable $e) {
            error_log('[Executive] diagram_nodes OKR query failed: ' . $e->getMessage());
        }

        // Parse KR text lines from okr_description for each node.
        // Lines starting with KR (e.g. "KR1: ...") are extracted into kr_lines;
        // other non-empty lines (e.g. description context) are kept in description_lines.
        foreach ($okrItems as &$okr) {
            $okr['kr_lines']          = [];
            $okr['description_lines'] = [];
            if (!empty($okr['okr_description'])) {
                foreach (preg_split('/\r?\n/', trim($okr['okr_description'])) as $line) {
                    $line = trim($line);
                    if ($line === '') {
                        continue;
                    }
                    if (preg_match('/^\s*KR\d*[\s:]/i', $line)) {
                        $okr['kr_lines'][] = $line;
                    } else {
                        $okr['description_lines'][] = $line;
                    }
                }
            }
            $okr['kr_count'] = count($okr['kr_lines']);
            // Attach structured KRs for per-KR progress display
            $okrKey = strtolower(trim($okr['okr_title'])) . '::' . (int) $okr['project_id'];
            $okr['structured_krs'] = $structuredKrsByKey[$okrKey] ?? [];
        }
        unset($okr);

        // Index by item_id so we can optionally merge structured key_results below.
        $okrIndex = [];
        foreach ($okrItems as $i => $okr) {
            $okrIndex[(int) $okr['item_id']] = $i;
        }

        $okrHealth = ['on_track' => 0, 'at_risk' => 0, 'off_track' => 0];
        // Optionally enrich with structured KR status counts from key_results.
        // Degrades silently if table is absent or no rows exist.
        try {
            $krCounts = $this->db->query(
                "SELECT kr.hl_work_item_id AS item_id,
                        COALESCE(SUM(CASE WHEN kr.status = 'on_track'    THEN 1 ELSE 0 END), 0) AS on_track,
                        COALESCE(SUM(CASE WHEN kr.status = 'at_risk'     THEN 1 ELSE 0 END), 0) AS at_risk,
                        COALESCE(SUM(CASE WHEN kr.status = 'off_track'   THEN 1 ELSE 0 END), 0) AS off_track,
                        COALESCE(SUM(CASE WHEN kr.status = 'not_started' THEN 1 ELSE 0 END), 0) AS not_started,
                        COALESCE(SUM(CASE WHEN kr.status = 'achieved'    THEN 1 ELSE 0 END), 0) AS achieved,
                        COUNT(kr.id) AS kr_count
                   FROM key_results kr
                   JOIN hl_work_items hwi ON hwi.id = kr.hl_work_item_id
                   JOIN projects p ON hwi.project_id = p.id
                  WHERE p.org_id = :oid
                  GROUP BY kr.hl_work_item_id",
                [':oid' => $orgId]
            )->fetchAll();

            foreach ($krCounts as $kc) {
                $okrHealth['on_track']  += (int) $kc['on_track'];
                $okrHealth['at_risk']   += (int) $kc['at_risk'];
                $okrHealth['off_track'] += (int) $kc['off_track'];
            }
        } catch (\Throwable $e) {
            error_log('[Executive] KR counts query failed: ' . $e->getMessage());
        }

        // ── Structured KR detail per OKR (for per-KR progress in expanded rows) ──
        $structuredKrsByKey = [];
        try {
            $krDetailRows = $this->db->query(
                "SELECT LOWER(TRIM(hwi.okr_title)) AS okr_key,
                        hwi.project_id,
                        kr.title       AS kr_title,
                        kr.baseline_value,
                        kr.target_value,
                        kr.current_value,
                        kr.unit,
                        kr.status      AS kr_status,
                        kr.ai_momentum
                   FROM key_results kr
                   JOIN hl_work_items hwi ON hwi.id = kr.hl_work_item_id
                   JOIN projects p ON hwi.project_id = p.id
                  WHERE p.org_id = :oid
                  ORDER BY kr.display_order ASC, kr.id ASC",
                [':oid' => $orgId]
            )->fetchAll();
            foreach ($krDetailRows as $row) {
                $key = $row['okr_key'] . '::' . (int) $row['project_id'];
                $structuredKrsByKey[$key][] = $row;
            }
        } catch (\Throwable $e) {
            error_log('[Executive] structured KR detail query failed: ' . $e->getMessage());
        }

        // ── Story completion per project (progress proxy for OKR rows) ──────────
        // Aggregates user_stories.status across all work items in each project.
        // Used on the OKR section of the dashboard to show "X% complete" bars.
        $storyProgressByProject = [];
        try {
            $spRows = $this->db->query(
                "SELECT p.id AS project_id,
                        COUNT(us.id)                                          AS total,
                        SUM(CASE WHEN us.status = 'done'        THEN 1 ELSE 0 END) AS done,
                        SUM(CASE WHEN us.status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress
                   FROM projects p
                   LEFT JOIN hl_work_items hwi ON hwi.project_id = p.id
                   LEFT JOIN user_stories  us  ON us.parent_hl_item_id = hwi.id
                  WHERE p.org_id = :oid
                  GROUP BY p.id",
                [':oid' => $orgId]
            )->fetchAll();
            foreach ($spRows as $row) {
                $storyProgressByProject[(int) $row['project_id']] = [
                    'total'       => (int) $row['total'],
                    'done'        => (int) $row['done'],
                    'in_progress' => (int) $row['in_progress'],
                    'pct'         => $row['total'] > 0
                        ? (int) round($row['done'] / $row['total'] * 100)
                        : 0,
                ];
            }
        } catch (\Throwable $e) {
            error_log('[Executive] story progress query failed: ' . $e->getMessage());
        }

        // ── Merged PR count per project (activity indicator) ─────────────────
        $mergedPrByProject = [];
        try {
            $prRows = $this->db->query(
                "SELECT p.id AS project_id, COUNT(DISTINCT sgl.id) AS merged_prs
                   FROM projects p
                   JOIN hl_work_items hwi ON hwi.project_id = p.id
                   JOIN user_stories  us  ON us.parent_hl_item_id = hwi.id
                   JOIN story_git_links sgl
                     ON sgl.local_type = 'user_story'
                    AND sgl.local_id   = us.id
                    AND sgl.status     = 'merged'
                  WHERE p.org_id = :oid
                  GROUP BY p.id",
                [':oid' => $orgId]
            )->fetchAll();
            foreach ($prRows as $row) {
                $mergedPrByProject[(int) $row['project_id']] = (int) $row['merged_prs'];
            }
        } catch (\Throwable $e) {
            error_log('[Executive] merged PR count query failed: ' . $e->getMessage());
        }

        // ── Table A: Top 10 active risks ──────────────────────────────────────
        $topRisks = $this->db->query(
            'SELECT r.id, r.title, r.description, r.mitigation,
                    r.likelihood, r.impact, (r.likelihood * r.impact) AS priority,
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
            'SELECT da.id, da.alert_type, da.details_json, da.created_at,
                    da.project_id, p.name AS project_name
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
            'user'             => $user,
            'active_page'      => 'executive',
            // Status bar
            'portfolio'        => $portfolio,
            'risk_summary'     => $riskSummary,
            'governance_queue' => $governanceQueueDepth,
            'drift_alerts'     => $driftAlerts,
            // OKR health
            'okr_health'              => $okrHealth,
            'okr_items'               => $okrItems,
            'story_progress'          => $storyProgressByProject,
            'merged_prs_by_project'   => $mergedPrByProject,
            // Risk detail
            'top_risks'          => $topRisks,
            'critical_alerts'    => $criticalAlerts,
            'governance_items'   => $govItems,
            // Flash
            'flash_message'    => $_SESSION['flash_message'] ?? null,
            'flash_error'      => $_SESSION['flash_error']   ?? null,
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
    public function projectDashboard($id): void
    {
        $id    = (int) $id;
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

        // Source OKRs from diagram_nodes — same data as the Strategy Roadmap page.
        $okrItems = [];
        try {
            $okrItems = $this->db->query(
                "SELECT dn.id, dn.okr_title, dn.okr_description
                   FROM diagram_nodes dn
                   JOIN strategy_diagrams sd ON sd.id = dn.diagram_id
                  WHERE sd.project_id = :pid
                    AND dn.okr_title IS NOT NULL
                    AND TRIM(dn.okr_title) != ''
                  ORDER BY dn.id ASC",
                [':pid' => $id]
            )->fetchAll();
        } catch (\Throwable $e) {
            error_log('[Executive] projectDashboard OKR query failed: ' . $e->getMessage());
        }

        // Parse KR text lines from each node's okr_description.
        foreach ($okrItems as &$okr) {
            $okr['kr_lines'] = [];
            if (!empty($okr['okr_description'])) {
                foreach (preg_split('/\r?\n/', trim($okr['okr_description'])) as $line) {
                    $line = trim($line);
                    if ($line !== '') {
                        $okr['kr_lines'][] = $line;
                    }
                }
            }
        }
        unset($okr);

        $healthCounts = ['total_okrs' => count($okrItems), 'total_krs' => 0];
        foreach ($okrItems as $o) {
            $healthCounts['total_krs'] += count($o['kr_lines']);
        }

        // ── Story progress per OKR (matched by okr_title on hl_work_items) ────
        // Also pull kr_hypothesis breakdown so each KR text line can show progress.
        $okrStoryProgress = [];   // keyed by okr_title (lower-trimmed)
        $krHypothesisData = [];   // keyed by okr_title → kr_hypothesis → [done, total]
        try {
            $spRows = $this->db->query(
                "SELECT hwi.okr_title,
                        us.kr_hypothesis,
                        COUNT(us.id)                                                AS total,
                        SUM(CASE WHEN us.status = 'done'        THEN 1 ELSE 0 END) AS done,
                        SUM(CASE WHEN us.status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress
                   FROM hl_work_items hwi
                   JOIN user_stories  us ON us.parent_hl_item_id = hwi.id
                  WHERE hwi.project_id = :pid
                    AND hwi.okr_title IS NOT NULL
                    AND TRIM(hwi.okr_title) != ''
                  GROUP BY hwi.okr_title, us.kr_hypothesis",
                [':pid' => $id]
            )->fetchAll();

            foreach ($spRows as $row) {
                $key = strtolower(trim($row['okr_title']));
                if (!isset($okrStoryProgress[$key])) {
                    $okrStoryProgress[$key] = ['total' => 0, 'done' => 0, 'in_progress' => 0];
                }
                $okrStoryProgress[$key]['total']       += (int) $row['total'];
                $okrStoryProgress[$key]['done']        += (int) $row['done'];
                $okrStoryProgress[$key]['in_progress'] += (int) $row['in_progress'];

                // KR hypothesis breakdown (may be null)
                $krKey = trim((string) $row['kr_hypothesis']);
                if ($krKey !== '') {
                    if (!isset($krHypothesisData[$key])) {
                        $krHypothesisData[$key] = [];
                    }
                    if (!isset($krHypothesisData[$key][$krKey])) {
                        $krHypothesisData[$key][$krKey] = ['total' => 0, 'done' => 0];
                    }
                    $krHypothesisData[$key][$krKey]['total'] += (int) $row['total'];
                    $krHypothesisData[$key][$krKey]['done']  += (int) $row['done'];
                }
            }
        } catch (\Throwable $e) {
            error_log('[Executive] projectDashboard story progress query failed: ' . $e->getMessage());
        }

        // ── Structured key_results for work items in this project ──────────
        $structuredKrsByOkrTitle = [];
        try {
            $krRows = $this->db->query(
                "SELECT hwi.okr_title,
                        kr.title       AS kr_title,
                        kr.baseline_value,
                        kr.target_value,
                        kr.current_value,
                        kr.unit,
                        kr.status      AS kr_status,
                        kr.ai_momentum
                   FROM key_results   kr
                   JOIN hl_work_items hwi ON hwi.id = kr.hl_work_item_id
                  WHERE hwi.project_id = :pid
                    AND hwi.okr_title IS NOT NULL
                  ORDER BY kr.display_order ASC, kr.id ASC",
                [':pid' => $id]
            )->fetchAll();

            foreach ($krRows as $row) {
                $key = strtolower(trim($row['okr_title']));
                $structuredKrsByOkrTitle[$key][] = $row;
            }
        } catch (\Throwable $e) {
            error_log('[Executive] projectDashboard structured KR query failed: ' . $e->getMessage());
        }

        // ── Attach progress data to each OKR item ────────────────────────────
        foreach ($okrItems as &$okr) {
            $key                   = strtolower(trim($okr['okr_title']));
            $sp                    = $okrStoryProgress[$key] ?? ['total' => 0, 'done' => 0, 'in_progress' => 0];
            $okr['story_total']    = $sp['total'];
            $okr['story_done']     = $sp['done'];
            $okr['story_pct']      = $sp['total'] > 0 ? (int) round($sp['done'] / $sp['total'] * 100) : 0;
            $okr['kr_hypothesis']  = $krHypothesisData[$key] ?? [];
            $okr['structured_krs'] = $structuredKrsByOkrTitle[$key] ?? [];
        }
        unset($okr);

        $this->response->render('executive-project', [
            'user'          => $user,
            'active_page'   => 'executive',
            'project'       => $project,
            'projects'      => $projects,
            'okr_items'     => $okrItems,
            'health_counts' => $healthCounts,
            'flash_message' => $_SESSION['flash_message'] ?? null,
            'flash_error'   => $_SESSION['flash_error']   ?? null,
        ], 'app');

        unset($_SESSION['flash_message'], $_SESSION['flash_error']);
    }
}
