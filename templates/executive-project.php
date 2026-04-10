<?php
// stratflow/templates/executive-project.php
// Variables: $user, $project, $projects, $okr_items, $krs_by_item_id,
//            $risks_by_item, $deps_by_item, $health_counts,
//            $flash_message, $flash_error
?>

<?php if (!empty($flash_message)): ?>
    <div class="flash-success"><?= htmlspecialchars($flash_message, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>
<?php if (!empty($flash_error)): ?>
    <div class="flash-error"><?= htmlspecialchars($flash_error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<!-- Page Header -->
<div class="page-header flex justify-between items-center" style="flex-wrap: wrap; gap: 1rem;">
    <div>
        <h1 class="page-title"><?= htmlspecialchars($project['name'], ENT_QUOTES, 'UTF-8') ?> &mdash; OKR Progress</h1>
        <p class="page-subtitle" style="color: #64748b; font-size: 0.875rem;">
            <?= (int) $health_counts['on_track'] ?> on track &middot;
            <?= (int) $health_counts['at_risk'] ?> at risk &middot;
            <?= (int) $health_counts['off_track'] ?> off track
        </p>
    </div>
    <div style="display:flex; align-items:center; gap: 0.75rem;">
        <select onchange="window.location='/app/projects/' + this.value + '/executive'"
                style="border:1px solid #d1d5db; border-radius:6px; padding: 6px 10px; font-size: 0.875rem;">
            <?php foreach ($projects as $p): ?>
                <option value="<?= (int) $p['id'] ?>" <?= (int) $p['id'] === (int) $project['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($p['name'], ENT_QUOTES, 'UTF-8') ?>
                </option>
            <?php endforeach; ?>
        </select>
        <span style="font-size: 12px; color: #94a3b8;">
            Updated <?= htmlspecialchars($project['updated_at'] ?? '', ENT_QUOTES, 'UTF-8') ?>
        </span>
    </div>
</div>

<?php if (empty($okr_items)): ?>
    <div class="card mt-6" style="text-align:center; padding:2rem; color:#6b7280;">
        <p>No OKRs defined for this project yet.</p>
        <p style="font-size:0.875rem;">Add OKR titles to work items on the <a href="/app/work-items" style="color:#6366f1;">Work Items</a> page.</p>
    </div>
<?php endif; ?>

<!-- OKR Cards -->
<?php foreach ($okr_items as $item):
    $itemId   = (int) $item['id'];
    $krs      = $krs_by_item_id[$itemId] ?? [];
    $risks    = $risks_by_item[$itemId]  ?? [];
    $blockedBy = $deps_by_item[$itemId]['blocked_by'] ?? [];
    $blocks    = $deps_by_item[$itemId]['blocks']     ?? [];

    // Determine worst KR status for OKR badge
    $statusOrder  = ['off_track' => 0, 'at_risk' => 1, 'not_started' => 2, 'on_track' => 3, 'achieved' => 4];
    $worstStatus  = 'not_started';
    foreach ($krs as $kr) {
        if (($statusOrder[$kr['status']] ?? 99) < ($statusOrder[$worstStatus] ?? 99)) {
            $worstStatus = $kr['status'];
        }
    }
    $badgeColours = [
        'on_track'    => '#10b981',
        'at_risk'     => '#f59e0b',
        'off_track'   => '#ef4444',
        'not_started' => '#9ca3af',
        'achieved'    => '#6366f1',
    ];
    $badgeColour = $badgeColours[$worstStatus] ?? '#9ca3af';
?>
<div class="card mb-4" style="border-top: 3px solid <?= htmlspecialchars($badgeColour, ENT_QUOTES, 'UTF-8') ?>;">
    <div class="card-body">

        <!-- OKR header -->
        <div class="flex justify-between items-center" style="flex-wrap: wrap; gap: 0.5rem;">
            <div>
                <span style="display:inline-block; background:<?= htmlspecialchars($badgeColour, ENT_QUOTES, 'UTF-8') ?>; color:#fff; border-radius:999px; padding:2px 10px; font-size:0.7rem; text-transform:uppercase; font-weight:600; margin-right:0.5rem;">
                    <?= htmlspecialchars(str_replace('_', ' ', $worstStatus), ENT_QUOTES, 'UTF-8') ?>
                </span>
                <strong style="font-size: 1rem;"><?= htmlspecialchars($item['okr_title'], ENT_QUOTES, 'UTF-8') ?></strong>
            </div>
            <span style="font-size:0.75rem; color:#94a3b8;"><?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?></span>
        </div>

        <?php if (!empty($krs)): ?>
        <!-- KR rows -->
        <div style="margin-top: 1rem;">
            <div style="font-size:0.7rem; text-transform:uppercase; font-weight:600; color:#94a3b8; margin-bottom:0.5rem;">Key Results</div>
            <?php foreach ($krs as $kr):
                $baseline = (float) ($kr['baseline_value'] ?? 0);
                $target   = (float) ($kr['target_value']   ?? 0);
                $current  = (float) ($kr['current_value']  ?? 0);
                $unit     = htmlspecialchars((string) ($kr['unit'] ?? ''), ENT_QUOTES, 'UTF-8');

                $pct = 0;
                if ($target !== 0.0 && $target !== $baseline) {
                    $pct = max(0, min(100, (int) round(($current - $baseline) / ($target - $baseline) * 100)));
                }

                $krBadge = $badgeColours[$kr['status']] ?? '#9ca3af';
            ?>
            <div class="kr-progress-row" style="margin-bottom: 0.875rem; padding: 0.625rem 0.75rem; background: #f9fafb; border-radius: 6px;">
                <div class="flex justify-between items-center" style="margin-bottom:0.375rem;">
                    <span style="font-size:0.8rem; font-weight:500; color:#374151;">
                        <?= htmlspecialchars($kr['title'], ENT_QUOTES, 'UTF-8') ?>
                    </span>
                    <span style="display:inline-block; background:<?= htmlspecialchars($krBadge, ENT_QUOTES, 'UTF-8') ?>; color:#fff; border-radius:999px; padding:1px 8px; font-size:0.65rem; text-transform:uppercase; font-weight:600;">
                        <?= htmlspecialchars(str_replace('_', ' ', $kr['status']), ENT_QUOTES, 'UTF-8') ?>
                    </span>
                </div>

                <!-- Progress bar -->
                <div style="display:flex; align-items:center; gap:0.5rem;">
                    <div style="flex:1; background:#e5e7eb; border-radius:999px; height:8px; overflow:hidden;">
                        <div style="width:<?= (int) $pct ?>%; background:<?= htmlspecialchars($krBadge, ENT_QUOTES, 'UTF-8') ?>; height:100%; border-radius:999px; transition:width 0.3s;"></div>
                    </div>
                    <span style="font-size:0.75rem; color:#6b7280; white-space:nowrap;">
                        <?php if ($target > 0): ?>
                            <?= number_format($current, 2, '.', '') . $unit ?> &rarr; <?= number_format($target, 2, '.', '') . $unit ?>
                        <?php else: ?>
                            No target set
                        <?php endif; ?>
                    </span>
                </div>

                <?php if (!empty($kr['ai_momentum'])): ?>
                <p style="margin: 0.375rem 0 0; font-size: 0.75rem; color: #6b7280; font-style: italic;">
                    &ldquo;<?= htmlspecialchars($kr['ai_momentum'], ENT_QUOTES, 'UTF-8') ?>&rdquo;
                </p>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($risks) || !empty($blockedBy) || !empty($blocks)): ?>
        <!-- Risks + Dependencies footer -->
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:1rem; margin-top:1rem; padding-top:1rem; border-top:1px solid #f3f4f6;">
            <div>
                <?php if (!empty($risks)): ?>
                    <div style="font-size:0.7rem; text-transform:uppercase; font-weight:600; color:#94a3b8; margin-bottom:0.375rem;">Risks</div>
                    <?php foreach ($risks as $risk):
                        $p = (int) $risk['priority'];
                        $sev = $p >= 15 ? ['🔴', '#ef4444'] : ($p >= 5 ? ['🟡', '#f59e0b'] : ['🟢', '#10b981']);
                    ?>
                    <div style="font-size:0.8rem; color:#374151; margin-bottom:0.25rem;">
                        <?= $sev[0] ?> <?= htmlspecialchars($risk['title'], ENT_QUOTES, 'UTF-8') ?>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div>
                <?php if (!empty($blockedBy) || !empty($blocks)): ?>
                    <div style="font-size:0.7rem; text-transform:uppercase; font-weight:600; color:#94a3b8; margin-bottom:0.375rem;">Dependencies</div>
                    <?php foreach ($blockedBy as $dep): ?>
                    <div style="font-size:0.8rem; color:#374151; margin-bottom:0.25rem;">
                        &larr; Blocked by: <?= htmlspecialchars($dep['blocker_title'], ENT_QUOTES, 'UTF-8') ?>
                    </div>
                    <?php endforeach; ?>
                    <?php foreach ($blocks as $dep): ?>
                    <div style="font-size:0.8rem; color:#374151; margin-bottom:0.25rem;">
                        &rarr; Blocks: <?= htmlspecialchars($dep['blocked_title'], ENT_QUOTES, 'UTF-8') ?>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

    </div><!-- /.card-body -->
</div><!-- /.card -->
<?php endforeach; ?>
