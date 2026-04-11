<?php
/**
 * Row Actions Menu Partial
 *
 * A compact kebab (three-dot) menu containing Edit, optional Close, optional
 * extra items, and Delete actions for list rows.
 *
 * Expects (from parent scope):
 *   $row_edit_class       string  JS class on the Edit button (e.g. 'edit-item-btn')
 *   $row_id               int     The row's id (used for data-id)
 *   $row_delete_action    string  POST URL for delete
 *   $row_delete_confirm   string  Confirm message (defaults to "Delete this item?")
 *   $row_close_action     string  (optional) POST URL for close; omit to hide Close
 *   $row_extra_items_html string  (optional) Raw HTML injected before Delete (e.g. ROAM items)
 *   $csrf_token           string
 *   $project              array
 */
$confirmMsg = $row_delete_confirm ?? 'Delete this item?';
?>
<div class="row-actions-menu">
    <button type="button" class="row-actions-toggle js-row-actions-toggle" aria-label="Actions" aria-haspopup="menu" aria-expanded="false">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
            <circle cx="5" cy="12" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="19" cy="12" r="1.8"/>
        </svg>
    </button>
    <div class="row-actions-dropdown" role="menu">
        <button type="button" class="row-actions-item <?= htmlspecialchars($row_edit_class) ?>" data-id="<?= (int) $row_id ?>" role="menuitem">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
            </svg>
            Edit
        </button>
        <?php if (!empty($row_close_action)): ?>
        <form method="POST" action="<?= htmlspecialchars($row_close_action) ?>" class="row-actions-form">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
            <button type="submit" class="row-actions-item" role="menuitem">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <circle cx="12" cy="12" r="10"/>
                    <polyline points="8 12 12 16 16 12"/>
                    <line x1="12" y1="8" x2="12" y2="16"/>
                </svg>
                Close
            </button>
        </form>
        <?php endif; ?>
        <?php if (!empty($row_extra_items_html)) echo $row_extra_items_html; ?>
        <form method="POST" action="<?= htmlspecialchars($row_delete_action) ?>" class="row-actions-form">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
            <button type="submit" class="row-actions-item row-actions-item--danger" role="menuitem"
                    data-confirm="<?= htmlspecialchars($confirmMsg, ENT_QUOTES) ?>">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <polyline points="3 6 5 6 21 6"/>
                    <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
                    <path d="M10 11v6M14 11v6"/>
                    <path d="M9 6V4a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2"/>
                </svg>
                Delete
            </button>
        </form>
    </div>
</div>
