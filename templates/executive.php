<?php
/**
 * Executive Dashboard Template
 *
 * Exec-focused view: OKR health, risk register, governance.
 * Removed: backlog counts, sprint velocity, integration health, audit log.
 *
 * Variables: $user, $portfolio, $risk_summary, $governance_queue,
 *            $drift_alerts, $okr_health, $okr_items,
 *            $top_risks, $critical_alerts, $flash_message, $flash_error
 */
?>

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
        <p class="page-subtitle">Organisation-wide view &mdash; <?= (int) $portfolio['total'] ?> project<?= $portfolio['total'] !== 1 ? 's' : '' ?></p>
    </div>
    <span style="font-size: 13px; color: #64748b;">As of <?= date('d M Y, H:i') ?></span>
</div>

<!-- ===========================
     Status Bar — 3 cards
     =========================== -->
<?php
    $totalKrs    = array_sum($okr_health);
    $totalOkrs   = count($okr_items ?? []);
    $atRiskKrs   = $okr_health['at_risk'] + $okr_health['off_track'];
    $okrBorder   = $okr_health['off_track'] > 0 ? '#ef4444' : ($okr_health['at_risk'] > 0 ? '#f59e0b' : ($totalOkrs > 0 ? '#10b981' : '#9ca3af'));
    $riskBorder = $risk_summary['high'] > 0 ? '#ef4444' : ($risk_summary['medium'] > 0 ? '#f59e0b' : '#10b981');
    $govBorder  = ($governance_queue > 0 || $drift_alerts['critical'] > 0) ? '#f59e0b' : '#10b981';
    $needsAttention = $governance_queue + $drift_alerts['critical'];
?>
<div class="exec-status-bar">

    <!-- OKR Health -->
    <div class="exec-status-card" style="border-top: 3px solid <?= $okrBorder ?>;">
        <div class="exec-status-label">OKR Health</div>
        <div class="exec-status-value" style="color: <?= $okrBorder ?>">
            <?php if ($totalOkrs === 0): ?>—<?php else: ?>
                <?= $totalOkrs ?>
            <?php endif; ?>
        </div>
        <div class="exec-status-sub">
            <?php if ($totalOkrs === 0): ?>
                <span style="color:#9ca3af; font-size:12px;">No OKRs defined yet</span>
            <?php elseif ($totalKrs > 0): ?>
                <span style="color:#10b981; font-weight:600;"><?= $okr_health['on_track'] ?> on track</span>
                <?php if ($okr_health['at_risk'] > 0): ?>
                    &middot; <span style="color:#f59e0b; font-weight:600;"><?= $okr_health['at_risk'] ?> at risk</span>
                <?php endif; ?>
                <?php if ($okr_health['off_track'] > 0): ?>
                    &middot; <span style="color:#ef4444; font-weight:600;"><?= $okr_health['off_track'] ?> off track</span>
                <?php endif; ?>
            <?php else: ?>
                <span style="color:#10b981; font-weight:600;"><?= $totalOkrs ?> objective<?= $totalOkrs !== 1 ? 's' : '' ?> set</span>
            <?php endif; ?>
        </div>
    </div>

    <!-- Risk Register -->
    <div class="exec-status-card" style="border-top: 3px solid <?= $riskBorder ?>;">
        <div class="exec-status-label">Open Risks</div>
        <div class="exec-status-value" style="color: <?= $riskBorder ?>"><?= (int) $risk_summary['total'] ?></div>
        <div class="exec-status-sub">
            <?php if ($risk_summary['total'] === 0): ?>
                <span style="color:#10b981;">None open</span>
            <?php else: ?>
                <?php if ($risk_summary['high'] > 0): ?><span style="color:#ef4444; font-weight:600;"><?= $risk_summary['high'] ?> high</span><?php endif; ?>
                <?php if ($risk_summary['medium'] > 0): ?>&middot; <span style="color:#f59e0b; font-weight:600;"><?= $risk_summary['medium'] ?> medium</span><?php endif; ?>
                <?php if ($risk_summary['low'] > 0): ?>&middot; <span style="color:#64748b;"><?= $risk_summary['low'] ?> low</span><?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Needs Attention -->
    <div class="exec-status-card" style="border-top: 3px solid <?= $govBorder ?>;">
        <div class="exec-status-label">Needs Attention</div>
        <div class="exec-status-value" style="color: <?= $govBorder ?>"><?= $needsAttention ?></div>
        <div class="exec-status-sub">
            <?php if ($needsAttention === 0): ?>
                <span style="color:#10b981;">All clear</span>
            <?php else: ?>
                <?php if ($drift_alerts['critical'] > 0): ?><span style="color:#ef4444; font-weight:600;"><?= $drift_alerts['critical'] ?> critical alerts</span><?php endif; ?>
                <?php if ($governance_queue > 0): ?><?php if ($drift_alerts['critical'] > 0): ?> &middot; <?php endif; ?><span style="color:#d97706; font-weight:600;"><?= $governance_queue ?> pending review<?= $governance_queue !== 1 ? 's' : '' ?></span><?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

</div>

<!-- ===========================
     OKR Progress
     =========================== -->
<div class="card mt-6">
    <div class="card-header flex justify-between items-center">
        <h2 class="card-title">OKR &amp; Key Results Progress</h2>
        <?php if (!empty($okr_items)): ?>
            <span style="font-size:12px; color:#64748b;"><?= count($okr_items) ?> objective<?= count($okr_items) !== 1 ? 's' : '' ?> across <?= count(array_unique(array_column($okr_items, 'project_id'))) ?> project<?= count(array_unique(array_column($okr_items, 'project_id'))) !== 1 ? 's' : '' ?></span>
        <?php endif; ?>
    </div>
    <div style="padding: 0 1.25rem 1.25rem;">

    <?php if (empty($okr_items)): ?>
        <p style="color:#9ca3af; font-size:0.875rem; padding: 1rem 0;">
            No OKRs defined yet. Set OKRs on strategy roadmap nodes on the
            <a href="/app/diagram" style="color:#6366f1;">Strategy Roadmap</a> page.
        </p>
    <?php else: ?>

    <?php
        // Group OKRs by project
        $byProject = [];
        foreach ($okr_items as $okr) {
            $byProject[(int) $okr['project_id']][] = $okr;
        }
        $statusColours = [
            'on_track'    => '#10b981',
            'at_risk'     => '#f59e0b',
            'off_track'   => '#ef4444',
            'not_started' => '#9ca3af',
            'achieved'    => '#6366f1',
        ];
    ?>

    <?php foreach ($byProject as $projectId => $projectOkrs): ?>
    <?php $projectName = htmlspecialchars($projectOkrs[0]['project_name'], ENT_QUOTES, 'UTF-8'); ?>

    <!-- Project group header -->
    <div style="margin-top: 1.25rem; margin-bottom: 0.5rem; display:flex; justify-content:space-between; align-items:center;">
        <span style="font-size:0.7rem; text-transform:uppercase; font-weight:700; letter-spacing:.06em; color:#94a3b8;"><?= $projectName ?></span>
        <a href="/app/projects/<?= (int) $projectId ?>/executive"
           style="font-size:0.75rem; color:#6366f1; text-decoration:none;">Full detail &rarr;</a>
    </div>

    <?php foreach ($projectOkrs as $okr):
        $krLines  = $okr['kr_lines'] ?? [];
        $krCount  = count($krLines);
        $hasKrs   = $krCount > 0;
        $sp       = ($story_progress ?? [])[(int) $okr['project_id']] ?? ['total' => 0, 'done' => 0, 'pct' => 0];
        $pct      = (int) $sp['pct'];
        $barColour = $pct >= 80 ? '#10b981' : ($pct >= 40 ? '#f59e0b' : '#6366f1');
    ?>
    <?php if ($hasKrs): ?>
    <details class="okr-details">
        <summary class="okr-row" style="cursor:pointer; list-style:none; display:flex; align-items:center;">
            <div class="okr-row-left">
                <span class="okr-expand-icon">&#9654;</span>
                <span class="okr-status-pill" style="background:#6366f1;">OKR Set</span>
                <span class="okr-title"><?= htmlspecialchars($okr['okr_title'], ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <div class="okr-row-right" style="display:flex; align-items:center; gap:0.5rem;">
                <?php if ($sp['total'] > 0): ?>
                <div style="display:flex; align-items:center; gap:0.35rem;">
                    <div style="width:60px; background:#e5e7eb; border-radius:999px; height:6px; overflow:hidden;">
                        <div style="width:<?= $pct ?>%; background:<?= $barColour ?>; height:100%; border-radius:999px;"></div>
                    </div>
                    <span style="font-size:0.7rem; color:<?= $barColour ?>; font-weight:700;"><?= $pct ?>%</span>
                </div>
                <?php endif; ?>
                <span style="font-size:0.75rem; color:#6b7280;"><?= $krCount ?> KR<?= $krCount !== 1 ? 's' : '' ?></span>
            </div>
        </summary>
        <div class="okr-kr-list">
            <?php foreach ($krLines as $j => $krLine):
                $displayLine = preg_replace('/^\s*KR\d*\s*[:.\-]\s*/i', '', $krLine);
            ?>
            <div class="okr-kr-item">
                <span class="okr-kr-num"><?= (int) ($j + 1) ?>.</span>
                <span class="okr-kr-text"><?= htmlspecialchars($displayLine, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <?php endforeach; ?>
            <?php if ($sp['total'] > 0): ?>
            <div style="margin-top:0.5rem; padding:0.4rem 0.6rem; background:#f1f5f9; border-radius:4px; font-size:0.75rem; color:#6b7280; display:flex; align-items:center; gap:0.75rem;">
                <span><?= $sp['done'] ?>/<?= $sp['total'] ?> stories done</span>
                <?php $mergedPrs = ($merged_prs_by_project ?? [])[(int) $okr['project_id']] ?? 0;
                if ($mergedPrs > 0): ?>
                <span>&middot; <?= $mergedPrs ?> merged PR<?= $mergedPrs !== 1 ? 's' : '' ?></span>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </details>
    <?php else: ?>
    <div class="okr-row">
        <div class="okr-row-left">
            <span style="display:inline-block; width:10px;"></span>
            <span class="okr-status-pill" style="background:#6366f1;">OKR Set</span>
            <span class="okr-title"><?= htmlspecialchars($okr['okr_title'], ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <div class="okr-row-right" style="display:flex; align-items:center; gap:0.5rem;">
            <?php if ($sp['total'] > 0): ?>
            <div style="display:flex; align-items:center; gap:0.35rem;">
                <div style="width:60px; background:#e5e7eb; border-radius:999px; height:6px; overflow:hidden;">
                    <div style="width:<?= $pct ?>%; background:<?= $barColour ?>; height:100%; border-radius:999px;"></div>
                </div>
                <span style="font-size:0.7rem; color:<?= $barColour ?>; font-weight:700;"><?= $pct ?>%</span>
            </div>
            <?php endif; ?>
            <span style="font-size:0.75rem; color:#9ca3af;">No KRs</span>
        </div>
    </div>
    <?php endif; ?>
    <?php endforeach; ?>

    <?php endforeach; ?>
    <?php endif; ?>

    </div>
</div>

<!-- ===========================
     Risk Register
     =========================== -->
<?php if (!empty($top_risks)): ?>
<div class="card mt-6">
    <div class="card-header">
        <h2 class="card-title">Risk Register <span style="font-size:0.8rem; font-weight:400; color:#64748b;">— top <?= count($top_risks) ?> by priority</span></h2>
    </div>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Risk</th>
                    <th>Project</th>
                    <th style="text-align:center;">L</th>
                    <th style="text-align:center;">I</th>
                    <th style="text-align:center;">Score</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($top_risks as $r):
                    $pri = (int) $r['priority'];
                    $band = $pri >= 15 ? ['#ef4444','#fee2e2'] : ($pri >= 5 ? ['#f59e0b','#fef3c7'] : ['#10b981','#f0fdf4']);
                ?>
                <tr>
                    <td><?= htmlspecialchars($r['title'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td style="color:#64748b; font-size:0.85rem;"><?= htmlspecialchars($r['project_name'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td style="text-align:center; color:#64748b;"><?= (int) $r['likelihood'] ?></td>
                    <td style="text-align:center; color:#64748b;"><?= (int) $r['impact'] ?></td>
                    <td style="text-align:center;">
                        <span style="display:inline-block; background:<?= $band[1] ?>; color:<?= $band[0] ?>; font-weight:700; font-size:0.8rem; padding:2px 8px; border-radius:999px;"><?= $pri ?></span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- ===========================
     Critical Alerts + Governance (only if there's something to show)
     =========================== -->
<?php if (!empty($critical_alerts) || $governance_queue > 0): ?>
<div class="card mt-6" style="border-left: 4px solid #ef4444; margin-bottom: 2rem;">
    <div class="card-header">
        <h2 class="card-title" style="color:#dc2626;">Needs Attention</h2>
    </div>
    <?php if ($governance_queue > 0): ?>
    <div style="padding: 0.75rem 1.25rem; border-bottom: 1px solid #f3f4f6; display:flex; align-items:center; gap:0.75rem;">
        <span style="background:#fef3c7; color:#d97706; font-weight:700; font-size:0.85rem; padding:3px 10px; border-radius:999px;"><?= $governance_queue ?></span>
        <span style="font-size:0.875rem;">change<?= $governance_queue !== 1 ? 's' : '' ?> awaiting governance review</span>
    </div>
    <?php endif; ?>
    <?php if (!empty($critical_alerts)): ?>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr><th>Alert</th><th>Project</th><th>Details</th><th>When</th></tr>
            </thead>
            <tbody>
                <?php foreach ($critical_alerts as $alert):
                    $details = json_decode($alert['details_json'] ?? '{}', true);
                ?>
                <tr>
                    <td><span class="badge badge-danger"><?= htmlspecialchars($alert['alert_type'], ENT_QUOTES, 'UTF-8') ?></span></td>
                    <td style="color:#64748b;"><?= htmlspecialchars($alert['project_name'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($details['message'] ?? $alert['alert_type'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td style="font-size:12px; color:#94a3b8; white-space:nowrap;"><?= htmlspecialchars($alert['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<style>
/* Status bar */
.exec-status-bar {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1rem;
    margin-bottom: 0;
}
.exec-status-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 1.25rem 1.25rem 1rem;
    box-shadow: 0 1px 3px rgba(0,0,0,.06);
}
.exec-status-label {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: #94a3b8;
    margin-bottom: 6px;
    font-weight: 600;
}
.exec-status-value {
    font-size: 2.5rem;
    font-weight: 800;
    line-height: 1;
    margin-bottom: 6px;
}
.exec-status-sub {
    font-size: 0.8rem;
    color: #64748b;
}

/* OKR rows */
.okr-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.6rem 0.75rem;
    border-radius: 6px;
    margin-bottom: 0.375rem;
    background: #f9fafb;
    border: 1px solid #f1f5f9;
    gap: 1rem;
}
.okr-row:hover { background: #f1f5f9; }
.okr-row-left {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    flex: 1;
    min-width: 0;
}
.okr-row-right {
    display: flex;
    align-items: center;
    gap: 3px;
    flex-shrink: 0;
}
.okr-status-pill {
    display: inline-block;
    color: #fff;
    font-size: 0.65rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .04em;
    padding: 2px 8px;
    border-radius: 999px;
    white-space: nowrap;
    flex-shrink: 0;
}
.okr-title {
    font-size: 0.875rem;
    font-weight: 500;
    color: #1e293b;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.kr-pip {
    display: inline-block;
    width: 10px;
    height: 10px;
    border-radius: 50%;
}
.mt-6 { margin-top: 1.5rem; }

/* Expandable OKR rows */
.okr-details { margin-bottom: 0.375rem; }
.okr-details > summary { display: flex; }
.okr-details > summary::-webkit-details-marker { display: none; }
.okr-details[open] > summary .okr-expand-icon { transform: rotate(90deg); }
.okr-expand-icon {
    font-size: 0.6rem;
    color: #94a3b8;
    flex-shrink: 0;
    transition: transform 0.15s ease;
    margin-right: 2px;
    line-height: 1.6;
}
.okr-kr-list {
    margin: 0.25rem 0 0.5rem 2.5rem;
    border-left: 2px solid #e0e7ff;
    padding-left: 0.75rem;
}
.okr-kr-item {
    display: flex;
    gap: 0.4rem;
    align-items: flex-start;
    padding: 0.3rem 0.5rem;
    margin-bottom: 0.25rem;
    background: #fafbff;
    border-radius: 4px;
    font-size: 0.8rem;
}
.okr-kr-num {
    color: #6366f1;
    font-weight: 700;
    white-space: nowrap;
    min-width: 1.2rem;
    font-size: 0.75rem;
}
.okr-kr-text {
    color: #374151;
    line-height: 1.4;
}
</style>
