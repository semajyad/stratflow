<?php
/**
 * Work Item Row Partial
 *
 * A single draggable row in the work items list. Used inside a loop
 * in work-items.php. Expects $item (array) and $csrf_token (string),
 * $project (array) from the parent scope.
 */
?>
<details class="story-row-details">
<summary class="work-item-row" data-id="<?= (int) $item['id'] ?>"
         data-title="<?= htmlspecialchars($item['title']) ?>"
         data-description="<?= htmlspecialchars($item['description'] ?? '') ?>"
         data-okr-title="<?= htmlspecialchars($item['okr_title'] ?? '') ?>"
         data-okr-desc="<?= htmlspecialchars($item['okr_description'] ?? '') ?>"
         data-owner="<?= htmlspecialchars($item['owner'] ?? '') ?>"
         data-team-assigned="<?= htmlspecialchars($item['team_assigned'] ?? '') ?>"
         data-estimated-sprints="<?= (int) ($item['estimated_sprints'] ?? 2) ?>"
         data-strategic-context="<?= htmlspecialchars($item['strategic_context'] ?? '') ?>"
         data-acceptance-criteria="<?= htmlspecialchars($item['acceptance_criteria'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
         data-kr-hypothesis="<?= htmlspecialchars($item['kr_hypothesis'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
    <span class="drag-handle" title="Drag to reorder">&#x2807;</span>
    <span class="priority-number"><?= (int) $item['priority_number'] ?></span>
    <div class="work-item-info">
        <strong><?= htmlspecialchars($item['title']) ?></strong>
        <?php if ($item['requires_review'] ?? false): ?>
            <span class="badge badge-warning">Requires Review</span>
        <?php endif; ?>
        <?php if (!empty($item['jira_url'])): ?>
            <a href="<?= htmlspecialchars($item['jira_url']) ?>" target="_blank" rel="noopener"
               title="Synced to Jira: <?= htmlspecialchars($item['jira_key'] ?? '') ?>"
               class="badge badge-info" style="text-decoration: none; font-size: 0.75rem;">
                Jira: <?= htmlspecialchars($item['jira_key'] ?? '') ?>
            </a>
        <?php endif; ?>
        <?php
            $statusLabels = ['in_progress' => 'In progress', 'in_review' => 'In review', 'done' => 'Done'];
            $statusBadges = ['in_progress' => 'badge-info', 'in_review' => 'badge-warning', 'done' => 'badge-success'];
            $itemStatus   = $item['status'] ?? 'backlog';
            if ($itemStatus !== 'backlog' && isset($statusLabels[$itemStatus])):
        ?>
            <span class="badge <?= $statusBadges[$itemStatus] ?>"><?= $statusLabels[$itemStatus] ?></span>
        <?php endif; ?>
        <?php if (!empty($item['dependencies'])): ?>
            <span class="badge badge-info" title="Depends on: <?= htmlspecialchars($item['dependency_titles']) ?>">
                &#x2190; Depends on <?= count($item['dependencies']) ?>
            </span>
        <?php endif; ?>
        <?php if (!empty($item['git_link_count'])): ?>
            <span class="badge badge-secondary git-link-badge">Git: <?= (int) $item['git_link_count'] ?></span>
        <?php endif; ?>
        <p class="work-item-desc-preview"><?= htmlspecialchars(substr($item['description'] ?? '', 0, 120)) ?><?= strlen($item['description'] ?? '') > 120 ? '...' : '' ?></p>
    </div>
    <span class="badge badge-primary"><?= (int) $item['estimated_sprints'] ?> sprint<?= $item['estimated_sprints'] != 1 ? 's' : '' ?></span>
    <?php if ($item['quality_score'] !== null): ?>
    <?php $qs = (int) $item['quality_score']; $qc = $qs >= 80 ? '#10b981' : ($qs >= 50 ? '#f59e0b' : '#ef4444'); ?>
    <span class="quality-pill" style="background:<?= $qc ?>;" title="Quality score: <?= $qs ?>/100"><?= $qs ?></span>
    <?php endif; ?>
    <span class="work-item-owner"><?= htmlspecialchars($item['owner'] ?? 'Unassigned') ?></span>
    <?php
        $row_edit_class     = 'edit-item-btn';
        $row_id             = (int) $item['id'];
        $row_delete_action  = '/app/work-items/' . (int) $item['id'] . '/delete';
        $row_delete_confirm = 'Delete this work item?';
        include __DIR__ . '/row-actions-menu.php';
    ?>
</summary>
<div class="story-row-expand">
    <?php if (!empty($item['acceptance_criteria'])): ?>
    <div class="story-expand-section">
        <span class="story-expand-label">Acceptance Criteria</span>
        <ul class="story-ac-list">
            <?php foreach (array_filter(array_map('trim', explode("\n", $item['acceptance_criteria']))) as $ac): ?>
                <li><?= htmlspecialchars($ac, ENT_QUOTES, 'UTF-8') ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>
    <div class="story-expand-meta">
        <div class="story-expand-meta-item">
            <span class="story-expand-label">Sprints</span>
            <span><?= (int) $item['estimated_sprints'] ?> sprint<?= $item['estimated_sprints'] != 1 ? 's' : '' ?> estimated</span>
        </div>
        <?php if (!empty($item['kr_hypothesis'])): ?>
        <div class="story-expand-meta-item">
            <span class="story-expand-label">KR Hypothesis</span>
            <span><?= htmlspecialchars($item['kr_hypothesis'], ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <?php endif; ?>
        <?php if (!empty($item['owner'])): ?>
        <div class="story-expand-meta-item">
            <span class="story-expand-label">Owner</span>
            <span><?= htmlspecialchars($item['owner'], ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <?php endif; ?>
    </div>
    <?php
    $wiBreakdown = null;
    if (!empty($item['quality_breakdown'])) {
        $wiBreakdown = json_decode($item['quality_breakdown'], true);
    }
    ?>
    <?php if ($wiBreakdown !== null): ?>
    <div class="story-expand-section">
        <span class="story-expand-label">Quality Breakdown</span>
        <div class="quality-breakdown">
            <?php
            $dimLabels = [
                'invest'              => 'INVEST',
                'acceptance_criteria' => 'Acceptance Criteria',
                'value'               => 'Value',
                'kr_linkage'          => 'KR Linkage',
                'smart'               => 'SMART',
                'splitting'           => 'Splitting',
            ];
            foreach ($dimLabels as $dimKey => $dimLabel):
                if (!isset($wiBreakdown[$dimKey])) continue;
                $dim      = $wiBreakdown[$dimKey];
                $dimScore = (int) ($dim['score'] ?? 0);
                $dimMax   = (int) ($dim['max'] ?? 1);
                $dimPct   = $dimMax > 0 ? (int) round($dimScore / $dimMax * 100) : 0;
                $dimColor = $dimPct >= 80 ? '#10b981' : ($dimPct >= 50 ? '#f59e0b' : '#ef4444');
            ?>
            <div class="quality-dim">
                <div class="quality-dim-header">
                    <span class="quality-dim-label"><?= htmlspecialchars($dimLabel, ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="quality-dim-score" style="color:<?= $dimColor ?>;"><?= $dimScore ?>/<?= $dimMax ?></span>
                </div>
                <div class="quality-dim-bar-track">
                    <div class="quality-dim-bar-fill" style="width:<?= $dimPct ?>%; background:<?= $dimColor ?>;"></div>
                </div>
                <?php foreach ($dim['issues'] ?? [] as $issue): ?>
                <div class="quality-dim-issue">&#8627; <?= htmlspecialchars((string) $issue, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <form method="POST" action="/app/work-items/<?= (int) $item['id'] ?>/improve"
              class="quality-improve-form" data-loading="Improving with AI…"
              onsubmit="return confirm('Improve this item with AI? The description, acceptance criteria, and KR hypothesis may be rewritten based on the quality score.')">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
            <button type="submit" class="btn btn-ai btn-sm">Improve with AI</button>
        </form>
    </div>
    <?php endif; ?>
</div>
</details>
