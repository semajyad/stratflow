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
    <span class="work-item-owner"><?= htmlspecialchars($item['owner'] ?? 'Unassigned') ?></span>
    <?php
        $row_edit_class     = 'edit-item-btn';
        $row_id             = (int) $item['id'];
        $row_delete_action  = '/app/work-items/' . (int) $item['id'] . '/delete';
        $row_delete_confirm = 'Delete this work item?';
        include __DIR__ . '/row-actions-menu.php';
    ?>
</div>
