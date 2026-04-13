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
         data-kr-hypothesis="<?= htmlspecialchars($item['kr_hypothesis'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
         data-closed="<?= ($item['status'] ?? '') === 'closed' ? '1' : '0' ?>">
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
               class="badge badge-info gen-style-616405">
                Jira: <?= htmlspecialchars($item['jira_key'] ?? '') ?>
            </a>
        <?php endif; ?>
        <?php
            $statusLabels = ['in_progress' => 'In progress', 'in_review' => 'In review', 'done' => 'Done', 'closed' => 'Closed'];
            $statusBadges = ['in_progress' => 'badge-info', 'in_review' => 'badge-warning', 'done' => 'badge-success', 'closed' => 'badge-secondary'];
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
    <?php if (($showQuality ?? false)): ?>
    <?php $qStatus = $item['quality_status'] ?? 'pending'; ?>
    <?php if ($qStatus === 'scored' && $item['quality_score'] !== null): ?>
    <?php $qs = (int) $item['quality_score']; $qc = $qs >= 80 ? '#10b981' : ($qs >= 50 ? '#f59e0b' : '#ef4444'); ?>
    <span class="quality-pill" data-style-background="<?= $qc ?>" title="Quality score: <?= $qs ?>/100"><?= $qs ?></span>
    <?php elseif ($qStatus === 'failed'): ?>
    <span class="quality-pill quality-pill--pending" title="<?= htmlspecialchars('Scoring failed: ' . ($item['quality_error'] ?? 'retrying…'), ENT_QUOTES, 'UTF-8') ?>"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></span>
    <?php elseif ($qStatus === 'skipped'): ?>
    <?php /* skipped = quality disabled for this org; render nothing */ ?>
    <?php else: ?>
    <span class="quality-pill quality-pill--pending js-quality-score-placeholder" data-task-id="<?= (int) $item['id'] ?>" data-task-type="work-item" title="Quality scoring in progress…"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></span>
    <?php endif; ?>
    <?php endif; ?>
    <span class="work-item-owner"><?= htmlspecialchars($item['owner'] ?? 'Unassigned') ?></span>
    <?php
        $row_edit_class       = 'edit-item-btn';
        $row_id               = (int) $item['id'];
        $row_delete_action    = '/app/work-items/' . (int) $item['id'] . '/delete';
        $row_delete_confirm   = 'Delete this work item?';
        $row_close_action     = '/app/work-items/' . (int) $item['id'] . '/close';
        $row_extra_items_html = null;
        if (($item['quality_status'] ?? null) === 'scored' && (int)($item['quality_score'] ?? 100) < 80) {
            ob_start();
            ?>
            <form method="POST" action="/app/work-items/<?= (int) $item['id'] ?>/refine-quality" class="row-actions-form">
                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
                <button type="submit" class="row-actions-item" role="menuitem">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/>
                    </svg>
                    Refine quality
                </button>
            </form>
            <?php
            $row_extra_items_html = ob_get_clean();
        }
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
    <div class="js-quality-breakdown-container" data-id="<?= (int) $item['id'] ?>" data-type="work-item">
    <?php
    if (($showQuality ?? false) && $wiBreakdown !== null) {
        $breakdownData = $wiBreakdown;
        $itemId        = (int) $item['id'];
        $itemType      = 'work-item';
        $csrf_token    = $csrf_token ?? '';
        require __DIR__ . '/quality-breakdown.php';
    }
    ?>
    </div>
</div>
</details>
