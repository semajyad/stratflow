<?php
/**
 * User Stories Template
 *
 * Displays user stories grouped under their parent work item (epic),
 * with per-epic AI generation, drag-and-drop reordering, edit modal,
 * dependency tracking, and CSV/JSON/Jira export.
 *
 * Variables: $project (array), $stories (array), $work_items (array),
 *            $csrf_token (string)
 */

// Group stories by parent work item id (0 = unlinked)
$storiesByItem = [];
foreach ($stories as $story) {
    $pid = (int) ($story['parent_hl_item_id'] ?? 0);
    $storiesByItem[$pid][] = $story;
}
$unlinkedStories = $storiesByItem[0] ?? [];
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
        <?php if (!empty($stories)): ?>
        <form method="POST" action="/app/user-stories/delete-all" class="inline-form">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
            <button type="submit" class="btn btn-sm btn-danger"
                    data-confirm="Delete all <?= count($stories) ?> stories for this project? This cannot be undone.">Delete All</button>
        </form>
        <?php endif; ?>
        <button type="button" class="btn btn-primary btn-sm js-toggle-story-modal">Add Story</button>
    </div>
</div>

<!-- ===========================
     HL Item Selector — Batch AI Decomposition
     =========================== -->
<?php if (!empty($work_items)): ?>
<div class="card mb-6">
    <div class="card-header">
        <h3>Split Work Items to User Stories (AI)</h3>
    </div>
    <div class="card-body">
        <form method="POST" action="/app/user-stories/generate"
              class="js-story-split-form"
              data-loading="Decomposing into user stories..."
              data-overlay="Decomposing work items into user stories. This may take 15-30 seconds.">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
            <div class="hl-selector">
                <label class="checkbox-label" style="font-weight:600; border-bottom:1px solid var(--border); padding-bottom:0.5rem; margin-bottom:0.5rem;">
                    <input type="checkbox" id="select-all-hl" class="js-select-all-hl">
                    Select All
                </label>
                <?php foreach ($work_items as $wi): ?>
                    <label class="checkbox-label">
                        <input type="checkbox" name="hl_item_ids[]" value="<?= (int) $wi['id'] ?>">
                        <?= htmlspecialchars($wi['title']) ?>
                    </label>
                <?php endforeach; ?>
            </div>
            <button type="submit" class="btn btn-ai btn-sm mt-2">
                Split to User Stories
            </button>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- ===========================
     Per-Epic Story Sections
     =========================== -->
<?php if (!empty($work_items)): ?>
    <?php foreach ($work_items as $epicIndex => $wi): ?>
        <?php
        $epicStories    = $storiesByItem[(int) $wi['id']] ?? [];
        $epicHasStories = !empty($epicStories);
        ?>
        <div class="card mb-4">
            <div class="card-header" style="display:flex; align-items:center; justify-content:space-between; gap:1rem; flex-wrap:wrap; background:var(--surface-2, #f1f5f9);">
                <div style="display:flex; align-items:center; gap:0.75rem;">
                    <span class="priority-number" style="flex-shrink:0;"><?= $epicIndex + 1 ?></span>
                    <div>
                        <strong style="font-size:0.9375rem;"><?= htmlspecialchars($wi['title']) ?></strong>
                        <div class="text-muted" style="font-size:0.8rem; margin-top:0.1rem;">
                            Owner: <?= htmlspecialchars($wi['owner'] ?? 'Unassigned') ?>
                        </div>
                    </div>
                </div>
                <form method="POST" action="/app/user-stories/generate"
                      data-loading="Generating user stories...">
                    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
                    <input type="hidden" name="hl_item_ids[]" value="<?= (int) $wi['id'] ?>">
                    <button type="submit" class="btn btn-ai btn-sm">
                        <?= $epicHasStories ? '✨ Generate More Stories' : '✨ Generate User Stories' ?>
                    </button>
                </form>
            </div>
            <?php if ($epicHasStories): ?>
            <div class="card-body" style="padding:0;">
                <div class="user-stories-list">
                    <?php foreach ($epicStories as $story): ?>
                        <?php require __DIR__ . '/partials/user-story-row.php'; ?>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php else: ?>
            <div class="card-body">
                <p style="color:var(--text-secondary); font-style:italic; margin:0; font-size:0.875rem;">
                    No User Stories generated yet. Click the button to decompose this epic.
                </p>
            </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<!-- ===========================
     Unlinked Stories (no parent work item)
     =========================== -->
<?php if (!empty($unlinkedStories)): ?>
<div class="card mb-4">
    <div class="card-header">
        <h3>Unlinked Stories</h3>
    </div>
    <div class="card-body" style="padding:0;">
        <div class="user-stories-list">
            <?php foreach ($unlinkedStories as $story): ?>
                <?php require __DIR__ . '/partials/user-story-row.php'; ?>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ===========================
     Empty state (no work items defined yet)
     =========================== -->
<?php if (empty($work_items) && empty($stories)): ?>
<div class="card mb-6">
    <div class="card-body text-center" style="padding:3rem;">
        <p class="text-muted" style="font-size:1.125rem;">
            No user stories yet. Select work items above to decompose with AI, or add stories manually.
        </p>
    </div>
</div>
<?php endif; ?>

<!-- ===========================
     Export + Regenerate Sizing Section
     =========================== -->
<?php if (!empty($stories)): ?>
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
                <button type="submit" class="btn btn-ai btn-sm"
                        data-confirm="Re-estimate story point sizes for all user stories using AI?">
                    Regenerate Sizing
                </button>
            </form>
        </div>
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
