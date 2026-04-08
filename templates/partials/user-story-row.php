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
        <?php if ($story['blocked_by']): ?>
            <span class="badge badge-warning">Blocked</span>
        <?php endif; ?>
    </div>
    <span class="story-size"><?= $story['size'] !== null ? (int) $story['size'] . ' pts' : '- pts' ?></span>
    <span class="story-team"><?= htmlspecialchars($story['team_assigned'] ?? 'Unassigned') ?></span>
    <div class="story-actions">
        <button class="btn btn-sm btn-secondary edit-story-btn" data-id="<?= (int) $story['id'] ?>">Edit</button>
        <form method="POST" action="/app/user-stories/<?= (int) $story['id'] ?>/delete" class="inline-form">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
            <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Delete?')">Delete</button>
        </form>
    </div>
</div>
