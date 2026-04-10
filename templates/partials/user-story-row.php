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
         data-kr-hypothesis="<?= htmlspecialchars($story['kr_hypothesis'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
    <span class="drag-handle" title="Drag to reorder">&#x2807;</span>
    <span class="priority-number"><?= (int) $story['priority_number'] ?></span>
    <div class="story-info">
        <strong><?= htmlspecialchars($story['title']) ?></strong>
        <span class="badge badge-secondary"><?= htmlspecialchars($story['parent_title'] ?? 'Unlinked') ?></span>
        <?php if (!empty($story['jira_url'])): ?>
            <a href="<?= htmlspecialchars($story['jira_url']) ?>" target="_blank" rel="noopener"
               title="Synced to Jira: <?= htmlspecialchars($story['jira_key'] ?? '') ?>"
               class="badge badge-info" style="text-decoration: none; font-size: 0.75rem;">
                Jira: <?= htmlspecialchars($story['jira_key'] ?? '') ?>
            </a>
        <?php endif; ?>
        <?php
            $statusLabels = ['in_progress' => 'In progress', 'in_review' => 'In review', 'done' => 'Done'];
            $statusBadges = ['in_progress' => 'badge-info', 'in_review' => 'badge-warning', 'done' => 'badge-success'];
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
    <span class="story-team"><?= htmlspecialchars($story['team_assigned'] ?? 'Unassigned') ?></span>
    <?php
        $row_edit_class     = 'edit-story-btn';
        $row_id             = (int) $story['id'];
        $row_delete_action  = '/app/user-stories/' . (int) $story['id'] . '/delete';
        $row_delete_confirm = 'Delete this user story?';
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
</div>
</details>
