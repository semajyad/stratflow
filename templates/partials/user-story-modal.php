<?php
/**
 * User Story Add/Edit Modal Partial
 *
 * Modal overlay for adding or editing a user story. Populated via
 * JavaScript from data attributes on the row for edits, or cleared
 * for new story creation. Fields wrapped in .modal-field-wrap[data-field]
 * for admin-configurable ordering.
 *
 * Expects $csrf_token (string), $project (array), $work_items (array),
 * and $stories (array) from the parent scope.
 */
?>
<div class="modal-overlay hidden" id="story-modal">
    <div class="modal" style="max-width: 760px;">
        <div class="modal-header">
            <h3 id="story-modal-title">Add User Story</h3>
            <button class="modal-close" onclick="toggleStoryModal()">&times;</button>
        </div>
        <form method="POST" id="story-form" action="/app/user-stories/store">
            <div class="modal-body">
                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">

                <div class="modal-field-wrap" data-field="title">
                    <div class="form-group">
                        <label for="story-title">Title</label>
                        <input type="text" id="story-title" name="title" class="form-control" required
                               placeholder='As a [role], I want [action], so that [value]'>
                    </div>
                </div>

                <div class="modal-field-wrap" data-field="description">
                    <div class="form-group">
                        <label for="story-description">Description</label>
                        <textarea id="story-description" name="description" class="form-control" rows="3"
                                  placeholder="Technical description of what needs to be built"></textarea>
                    </div>
                </div>

                <div class="modal-field-wrap" data-field="parent_hl_item_id">
                    <div class="form-group">
                        <label for="story-parent">Parent Work Item</label>
                        <select id="story-parent" name="parent_hl_item_id" class="form-control">
                            <option value="">-- None --</option>
                            <?php foreach ($work_items as $wi): ?>
                                <option value="<?= (int) $wi['id'] ?>"><?= htmlspecialchars($wi['title']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="modal-field-wrap" data-field="team_assigned">
                    <div class="form-group">
                        <label for="story-team">Team Assigned</label>
                        <select id="story-team" name="team_assigned" class="form-control">
                            <option value="">-- Unassigned --</option>
                            <?php if (!empty($teams)): ?>
                                <?php foreach ($teams as $team): ?>
                                    <option value="<?= htmlspecialchars($team['name']) ?>"><?= htmlspecialchars($team['name']) ?></option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                </div>

                <div class="modal-field-wrap" data-field="assignee_user_id">
                    <div class="form-group">
                        <label for="story-assignee">Assignee</label>
                        <select id="story-assignee" name="assignee_user_id" class="form-control">
                            <option value="">-- Unassigned --</option>
                            <?php if (!empty($org_users)): ?>
                                <?php foreach ($org_users as $ou): ?>
                                    <option value="<?= (int) $ou['id'] ?>"><?= htmlspecialchars($ou['full_name'] ?? $ou['name'] ?? $ou['email']) ?></option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                </div>

                <div class="modal-field-wrap" data-field="size">
                    <div class="form-group">
                        <label for="story-size">Size (Story Points)</label>
                        <div style="display: flex; align-items: center; gap: 0.625rem;">
                            <select id="story-size" name="size" class="form-control" style="width: 130px; flex: 0 0 auto;">
                                <option value="">--</option>
                                <option value="1">1</option>
                                <option value="2">2</option>
                                <option value="3">3</option>
                                <option value="5">5</option>
                                <option value="8">8</option>
                                <option value="13">13</option>
                                <option value="20">20</option>
                            </select>
                            <button type="button" class="btn btn-sm btn-secondary" id="ai-size-btn">
                                AI Suggest Size
                            </button>
                        </div>
                    </div>
                    <div id="ai-size-reasoning" class="text-muted" style="font-size: 0.8125rem; margin-bottom: 1rem; display: none;"></div>
                </div>

                <div class="modal-field-wrap" data-field="acceptance_criteria">
                    <details id="story-ac-details" style="border:1px solid #d1fae5; border-radius:6px; margin-bottom:0.75rem;">
                        <summary style="padding:0.6rem 0.85rem; font-size:0.875rem; font-weight:600; color:#065f46; cursor:pointer; list-style:none; display:flex; justify-content:space-between; align-items:center; background:#ecfdf5; border-radius:6px; user-select:none;">
                            <span>Acceptance Criteria <span style="font-weight:400; color:#6b7280;">(AI-generated &middot; editable)</span></span>
                            <span style="font-size:0.75rem; color:#6b7280; font-weight:400;">&#9660;</span>
                        </summary>
                        <div style="padding:0.75rem 0.85rem 0.85rem;">
                            <textarea name="acceptance_criteria" id="story-acceptance-criteria"
                                      rows="4" style="width:100%; font-size:0.8125rem; font-family:inherit; border:1px solid #d1d5db; border-radius:4px; padding:0.5rem; resize:vertical;"
                                      placeholder="Given..&#10;When..&#10;Then.."></textarea>
                        </div>
                    </details>
                </div>

                <div class="modal-field-wrap" data-field="kr_hypothesis">
                    <details id="story-kr-details" style="border:1px solid #ede9fe; border-radius:6px; margin-bottom:0.75rem;">
                        <summary style="padding:0.6rem 0.85rem; font-size:0.875rem; font-weight:600; color:#5b21b6; cursor:pointer; list-style:none; display:flex; justify-content:space-between; align-items:center; background:#f5f3ff; border-radius:6px; user-select:none;">
                            <span>KR Hypothesis <span style="font-weight:400; color:#6b7280;">(predicted contribution &middot; editable)</span></span>
                            <span style="font-size:0.75rem; color:#6b7280; font-weight:400;">&#9660;</span>
                        </summary>
                        <div style="padding:0.75rem 0.85rem 0.85rem;">
                            <input type="text" name="kr_hypothesis" id="story-kr-hypothesis"
                                   style="width:100%; font-size:0.8125rem; border:1px solid #d1d5db; border-radius:4px; padding:0.5rem;"
                                   placeholder="e.g. Expected to reduce churn by 2pp"
                                   maxlength="500">
                        </div>
                    </details>
                </div>

                <div class="modal-field-wrap" data-field="blocked_by">
                    <div class="form-group">
                        <label for="story-blocked-by">Blocked By</label>
                        <select id="story-blocked-by" name="blocked_by" class="form-control">
                            <option value="">-- None --</option>
                            <?php foreach ($stories as $s): ?>
                                <option value="<?= (int) $s['id'] ?>">#<?= (int) $s['priority_number'] ?> <?= htmlspecialchars($s['title']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="modal-field-wrap" data-field="git_links">
                    <?php require __DIR__ . '/git-links-field.php'; ?>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="toggleStoryModal()">Cancel</button>
                <button type="submit" class="btn btn-primary" id="story-submit-btn">Save</button>
            </div>
        </form>
    </div>
</div>
