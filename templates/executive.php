<?php
/**
 * Executive Dashboard Template
 *
 * Org-wide portfolio health at a glance: portfolio status, backlog throughput,
 * sprint velocity, risks, drift alerts, governance queue, integration health,
 * and recent activity. Gated by ExecutiveMiddleware (has_executive_access flag
 * or superadmin role).
 *
 * Variables: $user, $portfolio, $backlog, $top_items, $velocity,
 *            $active_sprints, $risk_summary, $drift_alerts,
 *            $governance_queue, $integrations, $subscription,
 *            $seats_used, $seat_limit, $top_risks, $critical_alerts,
 *            $recent_audit, $flash_message, $flash_error
 */
?>

<!-- ===========================
     Flash Messages
     =========================== -->
<?php if (!empty($flash_message)): ?>
    <div class="flash-success"><?= htmlspecialchars($flash_message, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>
<?php if (!empty($flash_error)): ?>
    <div class="flash-error"><?= htmlspecialchars($flash_error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<!-- ===========================
     Page Header
     =========================== -->
<div class="page-header flex justify-between items-center">
    <div>
        <h1 class="page-title">Executive Dashboard</h1>
        <p class="page-subtitle">Organisation-wide view &mdash; all projects</p>
    </div>
    <span style="font-size: 13px; color: #64748b;">As of <?= date('d M Y, H:i') ?></span>
</div>

<!-- ===========================
     Subscription Banner
     =========================== -->
<?php if (!empty($subscription)): ?>
<?php
    $subStatus  = $subscription['status'] ?? 'unknown';
    $subPlan    = $subscription['plan_type'] ?? '—';
    $subExpires = $subscription['expires_at'] ?? null;
    $seatPct    = $seat_limit > 0 ? round(($seats_used / $seat_limit) * 100) : 0;
    $seatClass  = $seatPct >= 90 ? 'color:#dc2626' : ($seatPct >= 70 ? 'color:#d97706' : 'color:#16a34a');
?>
<div class="card" style="margin-bottom: 1.5rem; border-left: 4px solid #6366f1;">
    <div class="card-body" style="display:flex; gap:2rem; align-items:center; flex-wrap:wrap; padding: 0.75rem 1.25rem;">
        <div><span style="font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:.05em;">Plan</span><br>
            <strong><?= htmlspecialchars($subPlan, ENT_QUOTES, 'UTF-8') ?></strong>
            <span class="badge <?= $subStatus === 'active' ? 'badge-success' : 'badge-warning' ?>" style="margin-left:6px;"><?= htmlspecialchars($subStatus, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <div><span style="font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:.05em;">Seats</span><br>
            <strong style="<?= $seatClass ?>"><?= $seats_used ?> / <?= $seat_limit > 0 ? $seat_limit : '&infin;' ?></strong>
        </div>
        <?php if ($subExpires): ?>
        <div><span style="font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:.05em;">Renews</span><br>
            <strong><?= htmlspecialchars(date('d M Y', strtotime($subExpires)), ENT_QUOTES, 'UTF-8') ?></strong>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- ===========================
     KPI Card Grid
     =========================== -->
<div class="exec-kpi-grid">

    <!-- Portfolio -->
    <div class="exec-kpi-card">
        <div class="exec-kpi-label">Projects</div>
        <div class="exec-kpi-value"><?= $portfolio['total'] ?></div>
        <div class="exec-kpi-breakdown">
            <span class="badge badge-secondary"><?= $portfolio['draft'] ?> draft</span>
            <span class="badge badge-primary"><?= $portfolio['active'] ?> active</span>
            <span class="badge badge-success"><?= $portfolio['completed'] ?> done</span>
        </div>
    </div>

    <!-- Backlog -->
    <div class="exec-kpi-card">
        <div class="exec-kpi-label">Backlog Items</div>
        <div class="exec-kpi-value"><?= $backlog['total'] ?></div>
        <div class="exec-kpi-breakdown">
            <span class="badge badge-secondary"><?= $backlog['backlog'] ?> queued</span>
            <span class="badge badge-info"><?= $backlog['in_progress'] + $backlog['in_review'] ?> active</span>
            <span class="badge badge-success"><?= $backlog['done'] ?> done</span>
        </div>
    </div>

    <!-- Sprint velocity (avg of last 4) -->
    <?php
        $velocityAvg = count($velocity) > 0
            ? round(array_sum(array_column($velocity, 'total_points')) / count($velocity), 1)
            : 0;
    ?>
    <div class="exec-kpi-card">
        <div class="exec-kpi-label">Avg Sprint Velocity</div>
        <div class="exec-kpi-value"><?= $velocityAvg ?></div>
        <div class="exec-kpi-breakdown" style="color:#64748b; font-size:12px;">points / sprint (last <?= count($velocity) ?>)</div>
    </div>

    <!-- Risk register -->
    <div class="exec-kpi-card">
        <div class="exec-kpi-label">Open Risks</div>
        <div class="exec-kpi-value" style="<?= $risk_summary['high'] > 0 ? 'color:#dc2626' : '' ?>"><?= $risk_summary['total'] ?></div>
        <div class="exec-kpi-breakdown">
            <span class="badge badge-danger"><?= $risk_summary['high'] ?> high</span>
            <span class="badge badge-warning"><?= $risk_summary['medium'] ?> med</span>
            <span class="badge badge-secondary"><?= $risk_summary['low'] ?> low</span>
        </div>
    </div>

    <!-- Drift alerts -->
    <div class="exec-kpi-card">
        <div class="exec-kpi-label">Active Drift Alerts</div>
        <div class="exec-kpi-value" style="<?= $drift_alerts['critical'] > 0 ? 'color:#dc2626' : '' ?>"><?= $drift_alerts['total'] ?></div>
        <div class="exec-kpi-breakdown">
            <span class="badge badge-danger"><?= $drift_alerts['critical'] ?> critical</span>
            <span class="badge badge-warning"><?= $drift_alerts['warning'] ?> warning</span>
            <span class="badge badge-secondary"><?= $drift_alerts['info'] ?> info</span>
        </div>
    </div>

    <!-- Governance queue -->
    <div class="exec-kpi-card">
        <div class="exec-kpi-label">Pending Reviews</div>
        <div class="exec-kpi-value" style="<?= $governance_queue > 0 ? 'color:#d97706' : '' ?>"><?= $governance_queue ?></div>
        <div class="exec-kpi-breakdown" style="color:#64748b; font-size:12px;">changes awaiting approval</div>
    </div>

</div>

<!-- ===========================
     Active Sprint Capacity
     =========================== -->
<?php if (!empty($active_sprints)): ?>
<div class="card mt-6">
    <div class="card-header">
        <h2 class="card-title">Active Sprints &mdash; Capacity</h2>
    </div>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Project</th>
                    <th>Sprint</th>
                    <th>Allocated</th>
                    <th>Capacity</th>
                    <th>Utilisation</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($active_sprints as $sp):
                    $cap   = (int) $sp['capacity'];
                    $alloc = (int) $sp['allocated_points'];
                    $pct   = $cap > 0 ? min(100, round(($alloc / $cap) * 100)) : 0;
                    $barClass = $pct >= 90 ? 'bg-danger' : ($pct >= 70 ? 'bg-warning' : 'bg-success');
                ?>
                <tr>
                    <td><?= htmlspecialchars($sp['project_name'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($sp['sprint_name'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= $alloc ?> pts</td>
                    <td><?= $cap ?> pts</td>
                    <td>
                        <div style="display:flex; align-items:center; gap:8px;">
                            <div style="flex:1; background:#e2e8f0; border-radius:4px; height:8px; overflow:hidden;">
                                <div style="width:<?= $pct ?>%; height:100%; background:<?= $pct>=90?'#dc2626':($pct>=70?'#d97706':'#16a34a') ?>;"></div>
                            </div>
                            <span style="font-size:12px; min-width:36px; text-align:right;"><?= $pct ?>%</span>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- ===========================
     Top 5 Priority Items
     =========================== -->
<?php if (!empty($top_items)): ?>
<div class="card mt-6">
    <div class="card-header">
        <h2 class="card-title">Top Priority Work Items</h2>
    </div>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Item</th>
                    <th>Project</th>
                    <th>Score</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($top_items as $i => $item): ?>
                <tr>
                    <td><?= (int) ($item['priority_number'] ?? $i + 1) ?></td>
                    <td><?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($item['project_name'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= round((float) ($item['final_score'] ?? 0), 1) ?></td>
                    <td>
                        <?php
                            $statusBadge = match($item['status']) {
                                'in_progress' => 'badge-info',
                                'in_review'   => 'badge-primary',
                                'done'        => 'badge-success',
                                default       => 'badge-secondary',
                            };
                        ?>
                        <span class="badge <?= $statusBadge ?>"><?= htmlspecialchars($item['status'], ENT_QUOTES, 'UTF-8') ?></span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- ===========================
     Top 10 Active Risks
     =========================== -->
<?php if (!empty($top_risks)): ?>
<div class="card mt-6">
    <div class="card-header">
        <h2 class="card-title">Top Active Risks</h2>
    </div>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Risk</th>
                    <th>Project</th>
                    <th>Likelihood</th>
                    <th>Impact</th>
                    <th>Priority Score</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($top_risks as $r):
                    $pri = (int) $r['priority'];
                    $priClass = $pri >= 15 ? 'color:#dc2626;font-weight:600' : ($pri >= 5 ? 'color:#d97706;font-weight:600' : '');
                ?>
                <tr>
                    <td><?= htmlspecialchars($r['title'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($r['project_name'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= (int) $r['likelihood'] ?></td>
                    <td><?= (int) $r['impact'] ?></td>
                    <td style="<?= $priClass ?>"><?= $pri ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- ===========================
     Critical Drift Alerts
     =========================== -->
<?php if (!empty($critical_alerts)): ?>
<div class="card mt-6">
    <div class="card-header" style="border-left: 4px solid #dc2626;">
        <h2 class="card-title" style="color:#dc2626;">Critical Drift Alerts</h2>
    </div>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Type</th>
                    <th>Project</th>
                    <th>Description</th>
                    <th>Detected</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($critical_alerts as $alert): ?>
                <tr>
                    <td><span class="badge badge-danger"><?= htmlspecialchars($alert['alert_type'], ENT_QUOTES, 'UTF-8') ?></span></td>
                    <td><?= htmlspecialchars($alert['project_name'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($alert['description'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td style="font-size:12px; color:#64748b;"><?= htmlspecialchars($alert['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- ===========================
     Integration Health
     =========================== -->
<?php if (!empty($integrations)): ?>
<div class="card mt-6">
    <div class="card-header">
        <h2 class="card-title">Integration Health</h2>
    </div>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Integration</th>
                    <th>Status</th>
                    <th>Last Sync</th>
                    <th>Errors</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($integrations as $int):
                    $intBadge = $int['status'] === 'active' ? 'badge-success' : ($int['status'] === 'error' ? 'badge-danger' : 'badge-secondary');
                ?>
                <tr>
                    <td><?= htmlspecialchars($int['name'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><span class="badge <?= $intBadge ?>"><?= htmlspecialchars($int['status'], ENT_QUOTES, 'UTF-8') ?></span></td>
                    <td style="font-size:12px; color:#64748b;"><?= $int['last_sync_at'] ? htmlspecialchars($int['last_sync_at'], ENT_QUOTES, 'UTF-8') : '—' ?></td>
                    <td>
                        <?php if ((int) $int['error_count'] > 0): ?>
                            <span style="color:#dc2626; font-weight:600;"><?= (int) $int['error_count'] ?></span>
                        <?php else: ?>
                            <span style="color:#16a34a;">0</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- ===========================
     Recent Audit Activity
     =========================== -->
<?php if (!empty($recent_audit)): ?>
<div class="card mt-6" style="margin-bottom: 2rem;">
    <div class="card-header">
        <h2 class="card-title">Recent Activity</h2>
    </div>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Time</th>
                    <th>Event</th>
                    <th>Actor</th>
                    <th>IP</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recent_audit as $log): ?>
                <tr>
                    <td style="font-size:12px; color:#64748b; white-space:nowrap;"><?= htmlspecialchars($log['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><code style="font-size:12px;"><?= htmlspecialchars($log['event_type'], ENT_QUOTES, 'UTF-8') ?></code></td>
                    <td><?= htmlspecialchars($log['actor_name'] ?? 'System', ENT_QUOTES, 'UTF-8') ?></td>
                    <td style="font-size:12px; color:#64748b;"><?= htmlspecialchars($log['ip_address'], ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<style>
.exec-kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    gap: 1rem;
    margin-bottom: 1.5rem;
}
.exec-kpi-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 1.25rem 1rem;
    box-shadow: 0 1px 3px rgba(0,0,0,.06);
}
.exec-kpi-label {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .05em;
    color: #64748b;
    margin-bottom: 4px;
}
.exec-kpi-value {
    font-size: 2.25rem;
    font-weight: 700;
    line-height: 1;
    margin-bottom: 8px;
    color: #0f172a;
}
.exec-kpi-breakdown {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
}
.mt-6 { margin-top: 1.5rem; }
</style>
