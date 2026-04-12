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
    <span class="exec-page-timestamp">As of <?= date('d M Y, H:i') ?></span>
</div>

<!-- ===========================
     Status Bar — 3 cards
     =========================== -->
<?php
    $totalKrs    = array_sum($okr_health);
    $totalOkrs   = count($okr_items ?? []);
    $okrTone    = $okr_health['off_track'] > 0 ? 'danger' : ($okr_health['at_risk'] > 0 ? 'warning' : ($totalOkrs > 0 ? 'success' : 'muted'));
    $riskTone   = $risk_summary['high'] > 0 ? 'danger' : ($risk_summary['medium'] > 0 ? 'warning' : 'success');
    $govTone    = ($governance_queue > 0 || $drift_alerts['critical'] > 0) ? 'warning' : 'success';
    $needsAttention = $governance_queue + $drift_alerts['critical'];
?>
<div class="exec-status-bar">

    <!-- OKR Health -->
    <div class="exec-status-card exec-status-card--<?= htmlspecialchars($okrTone, ENT_QUOTES, 'UTF-8') ?>">
        <div class="exec-status-label">OKR Health</div>
        <div class="exec-status-value exec-status-value--<?= htmlspecialchars($okrTone, ENT_QUOTES, 'UTF-8') ?>">
            <?php if ($totalOkrs === 0): ?>—<?php else: ?>
                <?= $totalOkrs ?>
            <?php endif; ?>
        </div>
        <div class="exec-status-sub">
            <?php if ($totalOkrs === 0): ?>
                <span class="exec-status-note exec-status-note--muted">No OKRs defined yet</span>
            <?php elseif ($totalKrs > 0): ?>
                <span class="exec-status-note exec-status-note--success"><?= $okr_health['on_track'] ?> on track</span>
                <?php if ($okr_health['at_risk'] > 0): ?>
                    &middot; <span class="exec-status-note exec-status-note--warning"><?= $okr_health['at_risk'] ?> at risk</span>
                <?php endif; ?>
                <?php if ($okr_health['off_track'] > 0): ?>
                    &middot; <span class="exec-status-note exec-status-note--danger"><?= $okr_health['off_track'] ?> off track</span>
                <?php endif; ?>
            <?php else: ?>
                <span class="exec-status-note exec-status-note--success"><?= $totalOkrs ?> objective<?= $totalOkrs !== 1 ? 's' : '' ?> set</span>
            <?php endif; ?>
        </div>
    </div>

    <!-- Risk Register — clickable link to risk section below -->
    <a href="#risk-register" class="exec-status-card exec-status-card--<?= htmlspecialchars($riskTone, ENT_QUOTES, 'UTF-8') ?> exec-status-card--link">
        <div class="exec-status-label">Open Risks</div>
        <div class="exec-status-value exec-status-value--<?= htmlspecialchars($riskTone, ENT_QUOTES, 'UTF-8') ?>"><?= (int) $risk_summary['total'] ?></div>
        <div class="exec-status-sub">
            <?php if ($risk_summary['total'] === 0): ?>
                <span class="exec-status-note exec-status-note--success">None open</span>
            <?php else: ?>
                <?php if ($risk_summary['high'] > 0): ?><span class="exec-status-note exec-status-note--danger"><?= $risk_summary['high'] ?> high</span><?php endif; ?>
                <?php if ($risk_summary['medium'] > 0): ?>&middot; <span class="exec-status-note exec-status-note--warning"><?= $risk_summary['medium'] ?> medium</span><?php endif; ?>
                <?php if ($risk_summary['low'] > 0): ?>&middot; <span class="exec-status-note exec-status-note--neutral"><?= $risk_summary['low'] ?> low</span><?php endif; ?>
            <?php endif; ?>
            <div class="exec-status-link-hint">View details &darr;</div>
        </div>
    </a>

    <!-- Needs Attention — clickable link to attention section below -->
    <a href="#needs-attention" class="exec-status-card exec-status-card--<?= htmlspecialchars($govTone, ENT_QUOTES, 'UTF-8') ?> exec-status-card--link">
        <div class="exec-status-label">Needs Attention</div>
        <div class="exec-status-value exec-status-value--<?= htmlspecialchars($govTone, ENT_QUOTES, 'UTF-8') ?>"><?= $needsAttention ?></div>
        <div class="exec-status-sub">
            <?php if ($needsAttention === 0): ?>
                <span class="exec-status-note exec-status-note--success">All clear</span>
            <?php else: ?>
                <?php if ($drift_alerts['critical'] > 0): ?><span class="exec-status-note exec-status-note--danger"><?= $drift_alerts['critical'] ?> critical alerts</span><?php endif; ?>
                <?php if ($governance_queue > 0): ?><?php if ($drift_alerts['critical'] > 0): ?> &middot; <?php endif; ?><span class="exec-status-note exec-status-note--amber"><?= $governance_queue ?> pending review<?= $governance_queue !== 1 ? 's' : '' ?></span><?php endif; ?>
                <div class="exec-status-link-hint">View details &darr;</div>
            <?php endif; ?>
        </div>
    </a>

</div>

<!-- ===========================
     Critical Alerts + Governance (only if there's something to show)
     =========================== -->
<?php if (!empty($critical_alerts) || $governance_queue > 0): ?>
<?php
$changeTypeLabels = [
    'new_story'          => 'New Story',
    'scope_change'       => 'Scope Change',
    'size_change'        => 'Size Change',
    'dependency_change'  => 'Dependency Change',
];
?>
<div class="card card--attention mt-6" id="needs-attention">
    <div class="card-header">
        <h2 class="card-title card-title--danger">Needs Attention</h2>
    </div>

    <?php if (!empty($critical_alerts)): ?>
    <div class="exec-section-eyebrow">Critical Alerts &mdash; Action Required</div>
    <div class="exec-stack">
        <?php foreach ($critical_alerts as $alert):
            $alertDetails = json_decode($alert['details_json'] ?? '{}', true);
            $alertMsg     = $alertDetails['message'] ?? $alertDetails['description'] ?? null;
            $alertType    = htmlspecialchars(ucwords(str_replace('_', ' ', $alert['alert_type'])), ENT_QUOTES, 'UTF-8');
            $alertAge     = htmlspecialchars($alert['created_at'], ENT_QUOTES, 'UTF-8');
        ?>
        <div class="exec-action-card exec-action-card--danger">
            <div class="exec-action-header">
                <div>
                    <span class="exec-action-type-pill exec-action-type-pill--danger"><?= $alertType ?></span>
                    <span class="exec-action-project"><?= htmlspecialchars($alert['project_name'], ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <span class="exec-action-age"><?= $alertAge ?></span>
            </div>
            <?php if ($alertMsg): ?>
            <p class="exec-action-message"><?= htmlspecialchars($alertMsg, ENT_QUOTES, 'UTF-8') ?></p>
            <?php endif; ?>
            <div class="exec-action-footer">
                <span class="exec-action-ask">What's needed: <strong>Acknowledge this alert</strong></span>
                <form method="POST" action="/app/governance/alerts/<?= (int) $alert['id'] ?>" class="inline-form">
                    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="acknowledge">
                    <input type="hidden" name="redirect_to" value="/app/executive">
                    <button type="submit" class="btn btn-sm btn-danger">Acknowledge</button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($governance_items)): ?>
    <div class="exec-section-eyebrow<?= !empty($critical_alerts) ? ' exec-section-eyebrow--bordered' : '' ?>">Pending Approvals &mdash; Your Decision Required</div>
    <div class="exec-stack exec-stack--spacious">
        <?php foreach ($governance_items as $gi):
            $giData  = json_decode($gi['proposed_change_json'] ?? '{}', true);
            $giLabel = $changeTypeLabels[$gi['change_type']] ?? ucwords(str_replace('_', ' ', $gi['change_type']));
            $giTitle = $giData['title'] ?? $giData['story_title'] ?? $giData['item_title'] ?? '';

            // Build a human-readable summary of what changed
            $giChangeSummary = '';
            if ($gi['change_type'] === 'new_story' && $giTitle) {
                $giChangeSummary = 'A new story has been proposed: "' . htmlspecialchars($giTitle, ENT_QUOTES, 'UTF-8') . '"';
            } elseif ($gi['change_type'] === 'scope_change') {
                $giChangeSummary = 'Scope change requested' . ($giTitle ? ' on "' . htmlspecialchars($giTitle, ENT_QUOTES, 'UTF-8') . '"' : '');
                if (!empty($giData['old_value']) && !empty($giData['new_value'])) {
                    $giChangeSummary .= ': ' . htmlspecialchars((string) $giData['old_value'], ENT_QUOTES, 'UTF-8')
                        . ' &rarr; ' . htmlspecialchars((string) $giData['new_value'], ENT_QUOTES, 'UTF-8');
                }
            } elseif ($gi['change_type'] === 'size_change') {
                $giChangeSummary = 'Size change requested' . ($giTitle ? ' on "' . htmlspecialchars($giTitle, ENT_QUOTES, 'UTF-8') . '"' : '');
                if (!empty($giData['old_size']) && !empty($giData['new_size'])) {
                    $giChangeSummary .= ': ' . htmlspecialchars((string) $giData['old_size'], ENT_QUOTES, 'UTF-8')
                        . ' &rarr; ' . htmlspecialchars((string) $giData['new_size'], ENT_QUOTES, 'UTF-8');
                }
            } elseif ($giTitle) {
                $giChangeSummary = htmlspecialchars($giTitle, ENT_QUOTES, 'UTF-8');
            }
        ?>
        <div class="exec-action-card exec-action-card--warning">
            <div class="exec-action-header">
                <div>
                    <span class="exec-action-type-pill exec-action-type-pill--warning"><?= htmlspecialchars($giLabel, ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="exec-action-project"><?= htmlspecialchars($gi['project_name'], ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <span class="exec-action-age"><?= htmlspecialchars($gi['created_at'], ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <?php if ($giChangeSummary): ?>
            <p class="exec-action-message"><?= $giChangeSummary ?></p>
            <?php endif; ?>
            <div class="exec-action-footer">
                <span class="exec-action-ask">What's needed: <strong>Approve or reject this change</strong></span>
                <div class="exec-inline-actions">
                    <form method="POST" action="/app/governance/queue/<?= (int) $gi['id'] ?>" class="inline-form">
                        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="approve">
                        <input type="hidden" name="redirect_to" value="/app/executive">
                        <button type="submit" class="btn btn-sm btn-primary">Approve</button>
                    </form>
                    <form method="POST" action="/app/governance/queue/<?= (int) $gi['id'] ?>" class="inline-form">
                        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="reject">
                        <input type="hidden" name="redirect_to" value="/app/executive">
                        <button type="submit" class="btn btn-sm btn-secondary">Reject</button>
                    </form>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- ===========================
     OKR Progress
     =========================== -->
<div class="card mt-6">
    <div class="card-header flex justify-between items-center">
        <h2 class="card-title">OKR &amp; Key Results Progress</h2>
        <?php if (!empty($okr_items)): ?>
            <span class="exec-okr-meta"><?= count($okr_items) ?> objective<?= count($okr_items) !== 1 ? 's' : '' ?> across <?= count(array_unique(array_column($okr_items, 'project_id'))) ?> project<?= count(array_unique(array_column($okr_items, 'project_id'))) !== 1 ? 's' : '' ?></span>
        <?php endif; ?>
    </div>
    <div class="exec-card-body">

    <?php if (empty($okr_items)): ?>
        <p class="exec-empty-copy">
            No OKRs defined yet. Set OKRs on strategy roadmap nodes on the
            <a href="/app/diagram" class="exec-link-inline">Strategy Roadmap</a> page.
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
    <div class="exec-project-heading">
        <span class="exec-project-heading__name"><?= $projectName ?></span>
        <a href="/app/projects/<?= (int) $projectId ?>/executive"
           class="exec-project-link">Full detail &rarr;</a>
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
    <?php
        $nodeKey    = htmlspecialchars($okr['node_key'] ?? '', ENT_QUOTES, 'UTF-8');
        $roadmapUrl = '/app/diagram?project_id=' . (int) $okr['project_id'] . '&node=' . urlencode($okr['node_key'] ?? '');
    ?>
    <details class="okr-details">
        <summary class="okr-row" style="cursor:pointer; list-style:none; display:flex; align-items:center;">
            <div class="okr-row-left">
                <span class="okr-expand-icon">&#9654;</span>
                <span class="okr-status-pill" style="background:#6366f1;">OKR Set</span>
                <a href="<?= $roadmapUrl ?>" class="okr-title js-stop-propagation" title="Open on roadmap"><?= htmlspecialchars($okr['okr_title'], ENT_QUOTES, 'UTF-8') ?></a>
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
            <?php
            $structuredKrs = $okr['structured_krs'] ?? [];
            $krStatusColours = [
                'on_track'    => ['#10b981', '#d1fae5'],
                'at_risk'     => ['#f59e0b', '#fef3c7'],
                'off_track'   => ['#ef4444', '#fee2e2'],
                'not_started' => ['#9ca3af', '#f3f4f6'],
                'achieved'    => ['#6366f1', '#e0e7ff'],
            ];
            $krStatusLabels = [
                'on_track'    => 'On Track',
                'at_risk'     => 'At Risk',
                'off_track'   => 'Off Track',
                'not_started' => 'Not Started',
                'achieved'    => 'Achieved',
            ];
            ?>
            <?php if (!empty($structuredKrs)): ?>
                <?php foreach ($structuredKrs as $skrIdx => $skr):
                    $skrLabel = $nodeKey . '-KR' . ($skrIdx + 1);
                    $baseline = (float) ($skr['baseline_value'] ?? 0);
                    $target   = (float) ($skr['target_value']   ?? 0);
                    $current  = (float) ($skr['current_value']  ?? 0);
                    $range    = $target - $baseline;
                    $krPct    = $range > 0 ? max(0, min(100, (int) round(($current - $baseline) / $range * 100))) : 0;
                    $krStatus = $skr['kr_status'] ?? 'not_started';
                    [$krColor, $krBg] = $krStatusColours[$krStatus] ?? ['#9ca3af', '#f3f4f6'];
                    $unit     = htmlspecialchars($skr['unit'] ?? '', ENT_QUOTES, 'UTF-8');
                ?>
                <div class="okr-kr-item okr-kr-item--structured">
                    <div class="okr-kr-item-header">
                        <span class="okr-kr-text"><span class="okr-kr-num"><?= htmlspecialchars($skrLabel, ENT_QUOTES, 'UTF-8') ?></span> <?= htmlspecialchars($skr['kr_title'], ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="okr-kr-status-badge" style="background:<?= $krBg ?>; color:<?= $krColor ?>;"><?= $krStatusLabels[$krStatus] ?? ucwords(str_replace('_', ' ', $krStatus)) ?></span>
                    </div>
                    <div class="okr-kr-progress-row">
                        <div class="okr-kr-bar-track">
                            <div class="okr-kr-bar-fill" style="width:<?= $krPct ?>%; background:<?= $krColor ?>;"></div>
                        </div>
                        <span class="okr-kr-progress-text" style="color:<?= $krColor ?>;"><?= $krPct ?>%</span>
                        <?php if ($target > 0): ?>
                        <span class="okr-kr-values"><?= number_format($current, 0) ?><?= $unit ? ' '.$unit : '' ?> / <?= number_format($target, 0) ?><?= $unit ? ' '.$unit : '' ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <?php foreach ($krLines as $j => $krLine):
                    // Extract KR number from line prefix (e.g. "KR2: ..." → 2); fallback to j+1
                    preg_match('/^\s*KR(\d+)/i', $krLine, $krNumMatch);
                    $krNum       = isset($krNumMatch[1]) ? (int) $krNumMatch[1] : ($j + 1);
                    $displayLine = preg_replace('/^\s*KR\d*\s*[:.\-]\s*/i', '', $krLine);
                    $krLabel     = $nodeKey . '-KR' . $krNum;
                ?>
                <div class="okr-kr-item">
                    <span class="okr-kr-num"><?= htmlspecialchars($krLabel, ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="okr-kr-text"><?= htmlspecialchars($displayLine, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
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
            <a href="<?= $roadmapUrl ?>" class="okr-title" title="Open on roadmap"><?= htmlspecialchars($okr['okr_title'], ENT_QUOTES, 'UTF-8') ?></a>
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
<div class="card mt-6" id="risk-register">
    <div class="card-header">
        <h2 class="card-title">Risk Register <span style="font-size:0.8rem; font-weight:400; color:#64748b;">— top <?= count($top_risks) ?> by priority</span></h2>
    </div>
    <div style="padding: 0 1.25rem 1.25rem;">
        <?php foreach ($top_risks as $r):
            $pri     = (int) $r['priority'];
            $band    = $pri >= 15 ? ['#ef4444','#fee2e2'] : ($pri >= 5 ? ['#f59e0b','#fef3c7'] : ['#10b981','#f0fdf4']);
            $hasDetail = !empty($r['description']) || !empty($r['mitigation']);
        ?>
        <?php
            $riskRef = 'RP' . (int) $r['id'];
        ?>
        <?php if ($hasDetail): ?>
        <details class="exec-risk-item">
            <summary class="exec-risk-summary">
                <span class="exec-risk-expand-icon">&#9654;</span>
                <span class="exec-risk-ref" style="font-size:0.75rem; font-weight:600; color:#6366f1; flex-shrink:0; min-width:3.5rem;"><?= htmlspecialchars($riskRef) ?></span>
                <span class="exec-risk-title"><?= htmlspecialchars($r['title'], ENT_QUOTES, 'UTF-8') ?></span>
                <span class="exec-risk-project"><?= htmlspecialchars($r['project_name'], ENT_QUOTES, 'UTF-8') ?></span>
                <span class="exec-risk-scores">
                    <span style="color:#64748b; font-size:0.8rem;">L:<?= (int) $r['likelihood'] ?> &middot; I:<?= (int) $r['impact'] ?></span>
                    <span style="background:<?= $band[1] ?>; color:<?= $band[0] ?>; font-weight:700; font-size:0.8rem; padding:2px 8px; border-radius:999px; white-space:nowrap;"><?= $pri ?></span>
                </span>
            </summary>
            <div class="exec-risk-detail">
                <?php if (!empty($r['description'])): ?>
                    <p style="margin: 0 0 0.4rem; color:#374151;"><?= htmlspecialchars($r['description'], ENT_QUOTES, 'UTF-8') ?></p>
                <?php endif; ?>
                <?php if (!empty($r['mitigation'])): ?>
                    <div style="background:#f0fdf4; border-left:3px solid #10b981; padding:0.4rem 0.6rem; border-radius:0 4px 4px 0; font-size:0.8rem; color:#065f46;">
                        <strong style="text-transform:uppercase; font-size:0.65rem; letter-spacing:.04em; color:#16a34a;">Mitigation:</strong>
                        <span style="margin-left:0.3rem;"><?= htmlspecialchars($r['mitigation'], ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                <?php endif; ?>
            </div>
        </details>
        <?php else: ?>
        <div class="exec-risk-item exec-risk-item--plain">
            <span style="display:inline-block; width:14px; flex-shrink:0;"></span>
            <span class="exec-risk-ref" style="font-size:0.75rem; font-weight:600; color:#6366f1; flex-shrink:0; min-width:3.5rem;"><?= htmlspecialchars($riskRef) ?></span>
            <span class="exec-risk-title"><?= htmlspecialchars($r['title'], ENT_QUOTES, 'UTF-8') ?></span>
            <span class="exec-risk-project"><?= htmlspecialchars($r['project_name'], ENT_QUOTES, 'UTF-8') ?></span>
            <span class="exec-risk-scores">
                <span style="color:#64748b; font-size:0.8rem;">L:<?= (int) $r['likelihood'] ?> &middot; I:<?= (int) $r['impact'] ?></span>
                <span style="background:<?= $band[1] ?>; color:<?= $band[0] ?>; font-weight:700; font-size:0.8rem; padding:2px 8px; border-radius:999px; white-space:nowrap;"><?= $pri ?></span>
            </span>
        </div>
        <?php endif; ?>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>
