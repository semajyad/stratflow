<?php
/**
 * Work Item Edit Modal Partial
 *
 * Modal overlay for editing a single work item. Populated via JavaScript
 * from data attributes on the row. Supports AI description generation.
 *
 * Expects $csrf_token (string) and $project (array) from the parent scope.
 */
?>
<div class="modal-overlay hidden" id="edit-modal">
    <div class="modal">
        <div class="modal-header">
            <h3>Edit Work Item</h3>
            <button class="modal-close" onclick="closeEditModal()">&times;</button>
        </div>
        <form method="POST" id="edit-form" action="/app/work-items/store">
            <div class="modal-body">
                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">

                <div class="form-group">
                    <label>Priority</label>
                    <input type="text" id="modal-priority" disabled>
                </div>

                <div class="form-group">
                    <label>Title</label>
                    <input type="text" name="title" id="modal-title" required>
                </div>

                <div class="form-group">
                    <label>OKR Title</label>
                    <input type="text" name="okr_title" id="modal-okr-title">
                </div>

                <div class="form-group">
                    <label>OKR Description</label>
                    <textarea name="okr_description" id="modal-okr-desc" rows="2"></textarea>
                </div>

                <div class="form-group">
                    <label>Owner</label>
                    <input type="text" name="owner" id="modal-owner" placeholder="e.g. Team Alpha">
                </div>

                <div class="form-group">
                    <label>Estimated Sprints</label>
                    <input type="number" name="estimated_sprints" id="modal-estimated-sprints" min="1" max="12" placeholder="2">
                </div>

                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" id="modal-description" rows="6"></textarea>
                    <button type="button" class="btn btn-sm btn-secondary mt-2" id="generate-desc-btn">
                        Generate Description (AI)
                    </button>
                </div>

                <?php require __DIR__ . '/git-links-field.php'; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeEditModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Update</button>
            </div>
        </form>
    </div>
</div>
