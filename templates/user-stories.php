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
<div class="page-header flex justify-between items-center mb-6">
    <div>
        <h1 class="page-title"><?= htmlspecialchars($project['name']) ?> &mdash; User Stories</h1>
        <p class="text-muted" style="margin: 0.25rem 0 0; font-size: 0.875rem;">
            <?= count($stories) ?> user stor<?= count($stories) === 1 ? 'y' : 'ies' ?>
        </p>
    </div>
    <div class="flex items-center gap-2">
        <?php include __DIR__ . '/partials/sounding-board-button.php'; ?>
        <a href="/app/risks?project_id=<?= (int) $project['id'] ?>" class="btn btn-secondary btn-sm">Back to Risk Modelling</a>
        <button type="button" class="btn btn-secondary btn-sm" onclick="toggleStoryModal()">Add Story Manually</button>
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
        <form method="POST" action="/app/user-stories/generate">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
            <div class="hl-selector">
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
     Export Section
     =========================== -->
<div class="card mb-6">
    <div class="card-body export-section">
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

<!-- ===========================
     Navigation
     =========================== -->
<div class="flex items-center justify-between mb-6" style="flex-wrap: wrap; gap: 1rem;">
    <a href="/app/risks?project_id=<?= (int) $project['id'] ?>" class="btn btn-secondary">
        &larr; Back to Risk Modelling
    </a>
    <a href="#" class="btn btn-secondary disabled" title="Coming Soon">
        Allocate to Sprints &rarr;
    </a>
</div>

<!-- SortableJS CDN -->
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>
