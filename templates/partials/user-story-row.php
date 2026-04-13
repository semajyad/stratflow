<?php
/**
 * User Story Row Partial
 *
 * A single draggable row in the user stories list. Used inside a loop
 * in user-stories.php. Expects $story (array), $csrf_token (string),
 * and $project (array) from the parent scope.
 */
?>
<details class="story-row-details">
<summary class="story-row" data-id="<?= (int) $story['id'] ?>"
         data-title="<?= htmlspecialchars($story['title']) ?>"
         data-description="<?= htmlspecialchars($story['description'] ?? '') ?>"
         data-team="<?= htmlspecialchars($story['team_assigned'] ?? '') ?>"
         data-size="<?= htmlspecialchars((string) ($story['size'] ?? '')) ?>"
         data-blocked-by="<?= htmlspecialchars((string) ($story['blocked_by'] ?? '')) ?>"
         data-parent-id="<?= htmlspecialchars((string) ($story['parent_hl_item_id'] ?? '')) ?>"
         data-acceptance-criteria="<?= htmlspecialchars($story['acceptance_criteria'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
         data-kr-hypothesis="<?= htmlspecialchars($story['kr_hypothesis'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
         data-assignee-user-id="<?= htmlspecialchars((string) ($story['assignee_user_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
         data-closed="<?= ($story['status'] ?? '') === 'closed' ? '1' : '0' ?>">
    <span class="drag-handle" title="Drag to reorder">&#x2807;</span>
    <span class="priority-number"><?= (int) $story['priority_number'] ?></span>
    <div class="story-info">
        <strong><?= htmlspecialchars($story['title']) ?></strong>
        <span class="badge badge-secondary"><?= htmlspecialchars($story['parent_title'] ?? 'Unlinked') ?></span>
        <?php if (!empty($story['jira_url'])): ?>
            <a href="<?= htmlspecialchars($story['jira_url']) ?>" target="_blank" rel="noopener"
               title="Synced to Jira: <?= htmlspecialchars($story['jira_key'] ?? '') ?>"
               class="badge badge-info gen-style-616405">
                Jira: <?= htmlspecialchars($story['jira_key'] ?? '') ?>
            </a>
        <?php endif; ?>
        <?php
            $statusLabels = ['in_progress' => 'In progress', 'in_review' => 'In review', 'done' => 'Done', 'closed' => 'Closed'];
            $statusBadges = ['in_progress' => 'badge-info', 'in_review' => 'badge-warning', 'done' => 'badge-success', 'closed' => 'badge-secondary'];
            $storyStatus  = $story['status'] ?? 'backlog';
            if ($storyStatus !== 'backlog' && isset($statusLabels[$storyStatus])):
        ?>
            <span class="badge <?= $statusBadges[$storyStatus] ?>"><?= $statusLabels[$storyStatus] ?></span>
        <?php endif; ?>
        <?php if ($story['requires_review'] ?? false): ?>
            <span class="badge badge-warning">Requires Review</span>
        <?php endif; ?>
        <?php if ($story['blocked_by']): ?>
            <span class="badge badge-warning">Blocked</span>
        <?php endif; ?>
        <?php if (!empty($story['git_link_count'])): ?>
            <span class="badge badge-secondary git-link-badge">Git: <?= (int) $story['git_link_count'] ?></span>
        <?php endif; ?>
    </div>
    <span class="story-size"><?= $story['size'] !== null ? (int) $story['size'] . ' pts' : '- pts' ?></span>
    <?php if (($showQuality ?? false)): ?>
    <?php $qStatus = $story['quality_status'] ?? 'pending'; ?>
    <?php if ($qStatus === 'scored' && $story['quality_score'] !== null): ?>
    <?php $qs = (int) $story['quality_score']; $qc = $qs >= 80 ? '#10b981' : ($qs >= 50 ? '#f59e0b' : '#ef4444'); ?>
    <span class="quality-pill" data-style-background="<?= $qc ?>" title="Quality score: <?= $qs ?>/100"><?= $qs ?></span>
    <?php elseif ($qStatus === 'failed'): ?>
    <span class="quality-pill quality-pill--pending" title="<?= htmlspecialchars('Scoring failed: ' . ($story['quality_error'] ?? 'retrying…'), ENT_QUOTES, 'UTF-8') ?>"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></span>
    <?php elseif ($qStatus === 'skipped'): ?>
    <?php /* skipped = quality disabled for this org; render nothing */ ?>
    <?php else: ?>
    <span class="quality-pill quality-pill--pending js-quality-score-placeholder" data-task-id="<?= (int) $story['id'] ?>" data-task-type="story" title="Quality scoring in progress…"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></span>
    <?php endif; ?>
    <?php endif; ?>
    <span class="story-team"><?= htmlspecialchars($story['team_assigned'] ?? 'Unassigned') ?></span>
    <?php
        $row_edit_class     = 'edit-story-btn';
        $row_id             = (int) $story['id'];
        $row_delete_action  = '/app/user-stories/' . (int) $story['id'] . '/delete';
        $row_delete_confirm = 'Delete this user story?';
        $row_close_action   = '/app/user-stories/' . (int) $story['id'] . '/close';
        $row_extra_items_html = null;
        if (($story['quality_status'] ?? null) === 'scored' && (int)($story['quality_score'] ?? 100) < 80) {
            ob_start();
            ?>
            <form method="POST" action="/app/user-stories/<?= (int) $story['id'] ?>/refine-quality" class="row-actions-form">
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
    <?php if (!empty($story['acceptance_criteria'])): ?>
    <div class="story-expand-section">
        <span class="story-expand-label">Acceptance Criteria</span>
        <ul class="story-ac-list">
            <?php foreach (array_filter(array_map('trim', explode("\n", $story['acceptance_criteria']))) as $ac): ?>
                <li><?= htmlspecialchars($ac, ENT_QUOTES, 'UTF-8') ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>
    <div class="story-expand-meta">
        <div class="story-expand-meta-item">
            <span class="story-expand-label">Size</span>
            <span><?= $story['size'] !== null ? (int) $story['size'] . ' story points' : 'Not estimated' ?></span>
        </div>
        <?php if (!empty($story['kr_hypothesis'])): ?>
        <div class="story-expand-meta-item">
            <span class="story-expand-label">KR Hypothesis</span>
            <span><?= htmlspecialchars($story['kr_hypothesis'], ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <?php endif; ?>
        <?php if (!empty($story['team_assigned'])): ?>
        <div class="story-expand-meta-item">
            <span class="story-expand-label">Team</span>
            <span><?= htmlspecialchars($story['team_assigned'], ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <?php endif; ?>
    </div>
    <?php
    $storyBreakdown = null;
    if (!empty($story['quality_breakdown'])) {
        $storyBreakdown = json_decode($story['quality_breakdown'], true);
    }
    ?>
    <div class="js-quality-breakdown-container" data-id="<?= (int) $story['id'] ?>" data-type="story">
    <?php
    if (($showQuality ?? false) && $storyBreakdown !== null) {
        $breakdownData = $storyBreakdown;
        $itemId        = (int) $story['id'];
        $itemType      = 'story';
        $csrf_token    = $csrf_token ?? '';
        require __DIR__ . '/quality-breakdown.php';
    }
    ?>
    </div>
</div>
</details>
