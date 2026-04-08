<?php
/**
 * Work Item Row Partial
 *
 * A single draggable row in the work items list. Used inside a loop
 * in work-items.php. Expects $item (array) and $csrf_token (string),
 * $project (array) from the parent scope.
 */
?>
<div class="work-item-row" data-id="<?= (int) $item['id'] ?>"
     data-title="<?= htmlspecialchars($item['title']) ?>"
     data-description="<?= htmlspecialchars($item['description'] ?? '') ?>"
     data-okr-title="<?= htmlspecialchars($item['okr_title'] ?? '') ?>"
     data-okr-desc="<?= htmlspecialchars($item['okr_description'] ?? '') ?>"
     data-owner="<?= htmlspecialchars($item['owner'] ?? '') ?>"
     data-strategic-context="<?= htmlspecialchars($item['strategic_context'] ?? '') ?>">
    <span class="drag-handle" title="Drag to reorder">&#x2807;</span>
    <span class="priority-number"><?= (int) $item['priority_number'] ?></span>
    <div class="work-item-info">
        <strong><?= htmlspecialchars($item['title']) ?></strong>
        <?php if ($item['requires_review'] ?? false): ?>
            <span class="badge badge-warning">Requires Review</span>
        <?php endif; ?>
        <p class="work-item-desc-preview"><?= htmlspecialchars(substr($item['description'] ?? '', 0, 120)) ?><?= strlen($item['description'] ?? '') > 120 ? '...' : '' ?></p>
    </div>
    <span class="badge badge-primary"><?= (int) $item['estimated_sprints'] ?> sprint<?= $item['estimated_sprints'] != 1 ? 's' : '' ?></span>
    <span class="work-item-owner"><?= htmlspecialchars($item['owner'] ?? 'Unassigned') ?></span>
    <div class="work-item-actions">
        <button class="btn btn-sm btn-secondary edit-item-btn" data-id="<?= (int) $item['id'] ?>">Edit</button>
        <form method="POST" action="/app/work-items/<?= (int) $item['id'] ?>/delete" class="inline-form">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
            <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Delete this item?')">Delete</button>
        </form>
    </div>
</div>
