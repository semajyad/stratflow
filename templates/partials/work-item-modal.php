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
                    <input type="text" name="okr_title" id="modal-okr-title"
                           list="okr-title-suggestions"
                           placeholder="e.g. Increase conversion rate from 2% → 3.5%">
                    <datalist id="okr-title-suggestions">
                        <?php foreach ($distinct_okr_titles ?? [] as $t): ?>
                            <option value="<?= htmlspecialchars($t, ENT_QUOTES, 'UTF-8') ?>">
                        <?php endforeach; ?>
                    </datalist>
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

                <!-- Acceptance Criteria — collapsible, AI-generated + editable -->
                <details id="wi-ac-details" style="border:1px solid #d1fae5; border-radius:6px; margin-bottom:0.75rem;">
                    <summary style="padding:0.6rem 0.85rem; font-size:0.875rem; font-weight:600; color:#065f46; cursor:pointer; list-style:none; display:flex; justify-content:space-between; align-items:center; background:#ecfdf5; border-radius:6px; user-select:none;">
                        <span>Acceptance Criteria <span style="font-weight:400; color:#6b7280;">(AI-generated &middot; editable)</span></span>
                        <span style="font-size:0.75rem; color:#6b7280; font-weight:400;">&#9660;</span>
                    </summary>
                    <div style="padding:0.75rem 0.85rem 0.85rem;">
                        <textarea name="acceptance_criteria" id="modal-acceptance-criteria"
                                  rows="4" style="width:100%; font-size:0.8125rem; font-family:inherit; border:1px solid #d1d5db; border-radius:4px; padding:0.5rem; resize:vertical;"
                                  placeholder="Given..&#10;When..&#10;Then.."></textarea>
                    </div>
                </details>

                <!-- KR Hypothesis — collapsible, AI-generated + editable -->
                <details id="wi-kr-details" style="border:1px solid #ede9fe; border-radius:6px; margin-bottom:0.75rem;">
                    <summary style="padding:0.6rem 0.85rem; font-size:0.875rem; font-weight:600; color:#5b21b6; cursor:pointer; list-style:none; display:flex; justify-content:space-between; align-items:center; background:#f5f3ff; border-radius:6px; user-select:none;">
                        <span>KR Hypothesis <span style="font-weight:400; color:#6b7280;">(predicted contribution &middot; editable)</span></span>
                        <span style="font-size:0.75rem; color:#6b7280; font-weight:400;">&#9660;</span>
                    </summary>
                    <div style="padding:0.75rem 0.85rem 0.85rem;">
                        <input type="text" name="kr_hypothesis" id="modal-kr-hypothesis"
                               style="width:100%; font-size:0.8125rem; border:1px solid #d1d5db; border-radius:4px; padding:0.5rem;"
                               placeholder="e.g. Expected to increase conversion rate from 2.1% &rarr; 3.5%"
                               maxlength="500">
                    </div>
                </details>

                <!-- KR editor is injected here by openWorkItemModal() -->
                <div id="kr-editor-mount"></div>

                <?php require __DIR__ . '/git-links-field.php'; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeEditModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Update</button>
            </div>
        </form>
    </div>
</div>
