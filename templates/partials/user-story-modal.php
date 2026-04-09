<?php
/**
 * User Story Add/Edit Modal Partial
 *
 * Modal overlay for adding or editing a user story. Populated via
 * JavaScript from data attributes on the row for edits, or cleared
 * for new story creation.
 *
 * Expects $csrf_token (string), $project (array), $work_items (array),
 * and $stories (array) from the parent scope.
 */
?>
<div class="modal-overlay hidden" id="story-modal">
    <div class="modal">
        <div class="modal-header">
            <h3 id="story-modal-title">Add User Story</h3>
            <button class="modal-close" onclick="toggleStoryModal()">&times;</button>
        </div>
        <form method="POST" id="story-form" action="/app/user-stories/store">
            <div class="modal-body">
                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">

                <div class="form-group">
                    <label for="story-title">Title</label>
                    <input type="text" id="story-title" name="title" class="form-control" required
                           placeholder='As a [role], I want [action], so that [value]'>
                </div>

                <div class="form-group">
                    <label for="story-description">Description</label>
                    <textarea id="story-description" name="description" class="form-control" rows="3"
                              placeholder="Technical description of what needs to be built"></textarea>
                </div>

                <div class="form-group">
                    <label for="story-parent">Parent Work Item</label>
                    <select id="story-parent" name="parent_hl_item_id" class="form-control">
                        <option value="">-- None --</option>
                        <?php foreach ($work_items as $wi): ?>
                            <option value="<?= (int) $wi['id'] ?>"><?= htmlspecialchars($wi['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

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

                <div class="flex gap-4">
                    <div class="form-group" style="flex: 1;">
                        <label for="story-size">Size (Story Points)</label>
                        <select id="story-size" name="size" class="form-control">
                            <option value="">--</option>
                            <option value="1">1</option>
                            <option value="2">2</option>
                            <option value="3">3</option>
                            <option value="5">5</option>
                            <option value="8">8</option>
                            <option value="13">13</option>
                            <option value="20">20</option>
                        </select>
                    </div>
                    <div class="form-group" style="flex: 1; display: flex; align-items: flex-end;">
                        <button type="button" class="btn btn-sm btn-secondary" id="ai-size-btn"
                                style="margin-bottom: 1.25rem;">
                            AI Suggest Size
                        </button>
                    </div>
                </div>
                <div id="ai-size-reasoning" class="text-muted" style="font-size: 0.8125rem; margin-bottom: 1rem; display: none;"></div>

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
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="toggleStoryModal()">Cancel</button>
                <button type="submit" class="btn btn-primary" id="story-submit-btn">Save</button>
            </div>
        </form>
    </div>
</div>
