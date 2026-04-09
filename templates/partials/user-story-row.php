<?php
/**
 * User Story Row Partial
 *
 * A single draggable row in the user stories list. Used inside a loop
 * in user-stories.php. Expects $story (array), $csrf_token (string),
 * and $project (array) from the parent scope.
 */
?>
<div class="story-row" data-id="<?= (int) $story['id'] ?>"
     data-title="<?= htmlspecialchars($story['title']) ?>"
     data-description="<?= htmlspecialchars($story['description'] ?? '') ?>"
     data-team="<?= htmlspecialchars($story['team_assigned'] ?? '') ?>"
     data-size="<?= htmlspecialchars((string) ($story['size'] ?? '')) ?>"
     data-blocked-by="<?= htmlspecialchars((string) ($story['blocked_by'] ?? '')) ?>"
     data-parent-id="<?= htmlspecialchars((string) ($story['parent_hl_item_id'] ?? '')) ?>">
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
        <?php if ($story['requires_review'] ?? false): ?>
            <span class="badge badge-warning">Requires Review</span>
        <?php endif; ?>
        <?php if ($story['blocked_by']): ?>
            <span class="badge badge-warning">Blocked</span>
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
</div>
