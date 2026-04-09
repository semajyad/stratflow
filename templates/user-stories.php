<?php
/**
 * User Stories Template
 *
 * Displays user stories decomposed from HL work items, with AI generation,
 * drag-and-drop reordering, edit modal, dependency tracking, and
 * CSV/JSON/Jira export.
 *
 * Variables: $project (array), $stories (array), $work_items (array),
 *            $csrf_token (string)
 */
?>

<!-- ===========================
     Page Header
     =========================== -->
<div class="page-header flex justify-between items-center">
    <h1 class="page-title">
        <?= htmlspecialchars($project['name']) ?> &mdash; User Stories
        <span class="page-title-count"><?= count($stories) ?></span>
        <span class="page-info" tabindex="0" role="button" aria-label="About this page">
            <span class="page-info-btn" aria-hidden="true">i</span>
            <span class="page-info-popover" role="tooltip">User stories break down high-level work items into developer-ready tasks of approximately 3 days each. Select work items to decompose, manage dependencies, and export to your project management tool.</span>
        </span>
    </h1>
    <div class="flex items-center gap-2">
        <?php $sync_type = 'user_stories'; include __DIR__ . '/partials/jira-sync-button.php'; ?>
        <?php include __DIR__ . '/partials/sounding-board-button.php'; ?>
        <button type="button" class="btn btn-primary btn-sm" onclick="toggleStoryModal()">Add Story</button>
    </div>
</div>

<!-- ===========================
     HL Item Selector — AI Decomposition
     =========================== -->
<?php if (!empty($work_items)): ?>
<div class="card mb-6">
    <div class="card-header">
        <h3>Split Work Items to User Stories (AI)</h3>
    </div>
    <div class="card-body">
        <form method="POST" action="/app/user-stories/generate"
              data-loading="Decomposing into user stories..."
              data-overlay="Decomposing work items into user stories. This may take 15-30 seconds.">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
            <div class="hl-selector">
                <label class="checkbox-label" style="font-weight:600; border-bottom:1px solid var(--border); padding-bottom:0.5rem; margin-bottom:0.5rem;">
                    <input type="checkbox" id="select-all-hl" onchange="document.querySelectorAll('input[name=\'hl_item_ids[]\']').forEach(cb => cb.checked = this.checked)">
                    Select All
                </label>
                <?php foreach ($work_items as $wi): ?>
                    <label class="checkbox-label">
                        <input type="checkbox" name="hl_item_ids[]" value="<?= (int) $wi['id'] ?>">
                        <?= htmlspecialchars($wi['title']) ?>
                    </label>
                <?php endforeach; ?>
            </div>
            <button type="submit" class="btn btn-primary btn-sm mt-2" onclick="return document.querySelectorAll('input[name=\'hl_item_ids[]\']:checked').length > 0 || (alert('Select at least one work item.'), false)">
                Split to User Stories
            </button>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- ===========================
     User Stories List
     =========================== -->
<?php if (!empty($stories)): ?>
<div class="card mb-6">
    <div class="card-header">
        <h3>User Stories (<?= count($stories) ?>)</h3>
    </div>
    <div class="card-body" style="padding:0;">
        <div id="user-stories-list">
            <?php foreach ($stories as $story): ?>
                <?php require __DIR__ . '/partials/user-story-row.php'; ?>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- ===========================
     Export + Regenerate Sizing Section
     =========================== -->
<div class="card mb-6">
    <div class="card-body export-section">
        <div class="flex items-center justify-between" style="flex-wrap: wrap; gap: 1rem;">
            <div>
                <strong>Export User Stories</strong>
                <div class="flex items-center gap-2 mt-2">
                    <a href="/app/user-stories/export?project_id=<?= (int) $project['id'] ?>&format=csv" class="btn btn-secondary btn-sm">
                        Export CSV
                    </a>
                    <a href="/app/user-stories/export?project_id=<?= (int) $project['id'] ?>&format=json" class="btn btn-secondary btn-sm">
                        Export JSON
                    </a>
                    <a href="/app/user-stories/export?project_id=<?= (int) $project['id'] ?>&format=jira" class="btn btn-secondary btn-sm">
                        Export Jira CSV
                    </a>
                </div>
            </div>
            <form method="POST" action="/app/user-stories/regenerate-sizing"
                  data-loading="Re-estimating story sizes..."
                  data-overlay="Re-estimating story point sizes for all user stories using AI.">
                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
                <button type="submit" class="btn btn-secondary btn-sm"
                        onclick="return confirm('Re-estimate story point sizes for all user stories using AI?')">
                    Regenerate Sizing
                </button>
            </form>
        </div>
    </div>
</div>

<?php else: ?>
<div class="card mb-6">
    <div class="card-body text-center" style="padding:3rem;">
        <p class="text-muted" style="font-size:1.125rem;">
            No user stories yet. Select work items above to decompose with AI, or add stories manually.
        </p>
    </div>
</div>
<?php endif; ?>

<!-- ===========================
     Add/Edit Story Modal
     =========================== -->
<?php require __DIR__ . '/partials/user-story-modal.php'; ?>

<?php require __DIR__ . '/partials/workflow-nav.php'; ?>

<!-- SortableJS CDN -->
<script defer src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>
