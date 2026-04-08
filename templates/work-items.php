<?php
/**
 * Work Items Template
 *
 * Displays the prioritised backlog of High-Level Work Items (HLWIs)
 * with drag-and-drop reordering, inline edit modal, AI generation,
 * and CSV/JSON export.
 *
 * Variables: $project (array), $work_items (array), $diagram (?array),
 *            $csrf_token (string)
 */
?>

<!-- ===========================
     Page Header
     =========================== -->
<div class="page-header flex justify-between items-center mb-6">
    <h1 class="page-title"><?= htmlspecialchars($project['name']) ?> &mdash; Work Items</h1>
    <div class="flex items-center gap-2">
        <?php include __DIR__ . '/partials/sounding-board-button.php'; ?>
        <a href="/app/diagram?project_id=<?= (int) $project['id'] ?>" class="btn btn-secondary btn-sm">Back to Diagram</a>
    </div>
</div>

<!-- ===========================
     Page Description
     =========================== -->
<div class="page-description">
    High-level work items represent approximately one month of effort for a Scrum team. Drag to reorder priorities, edit details, or generate AI descriptions.
</div>

<!-- ===========================
     Mini Diagram Thumbnail
     =========================== -->
<?php if (!empty($diagram)): ?>
<div class="diagram-thumbnail">
    <div class="diagram-thumbnail-inner" id="mermaid-thumb-output"></div>
    <textarea id="mermaid-thumb-code" style="display:none;"><?= htmlspecialchars($diagram['mermaid_code']) ?></textarea>
</div>
<?php endif; ?>

<!-- ===========================
     Generate Work Items
     =========================== -->
<div class="card mb-6">
    <div class="card-body flex items-center justify-between" style="flex-wrap: wrap; gap: 1rem;">
        <div>
            <strong>AI Work Item Generation</strong>
            <p class="text-muted" style="margin:0.25rem 0 0; font-size:0.875rem;">
                <?php if (!empty($work_items)): ?>
                    Warning: This will replace all <?= count($work_items) ?> existing work items.
                <?php else: ?>
                    Generate a prioritised backlog from your strategy diagram and OKRs.
                <?php endif; ?>
            </p>
        </div>
        <div class="flex items-center gap-2" style="flex-wrap: wrap;">
            <form method="POST" action="/app/work-items/generate">
                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
                <button type="submit" class="btn btn-primary"
                        <?php if (!empty($work_items)): ?>
                        onclick="return confirm('This will replace all existing work items. Continue?')"
                        <?php endif; ?>>
                    <?= empty($work_items) ? 'Generate Work Items' : 'Regenerate Work Items' ?>
                </button>
            </form>
            <?php if (!empty($work_items)): ?>
            <form method="POST" action="/app/work-items/regenerate-sizing">
                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
                <button type="submit" class="btn btn-secondary"
                        onclick="return confirm('Re-estimate sprint sizing for all work items using AI?')">
                    Regenerate Sizing
                </button>
            </form>
            <button
                type="button"
                class="btn btn-secondary"
                onclick="openWorkItemModal(null)"
            >Add Work Item</button>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ===========================
     Work Items List
     =========================== -->
<?php if (!empty($work_items)): ?>
<div class="card mb-6">
    <div class="card-header">
        <h3>Work Items (<?= count($work_items) ?>)</h3>
    </div>
    <div class="card-body" style="padding:0;">
        <div id="work-items-list">
            <?php foreach ($work_items as $item): ?>
                <?php require __DIR__ . '/partials/work-item-row.php'; ?>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- ===========================
     Export Section
     =========================== -->
<div class="card mb-6">
    <div class="card-body export-section">
        <strong>Export Work Items</strong>
        <div class="flex items-center gap-2 mt-2">
            <a href="/app/work-items/export?project_id=<?= (int) $project['id'] ?>&format=csv" class="btn btn-secondary btn-sm">
                Export CSV
            </a>
            <a href="/app/work-items/export?project_id=<?= (int) $project['id'] ?>&format=json" class="btn btn-secondary btn-sm">
                Export JSON
            </a>
        </div>
    </div>
</div>

<!-- ===========================
     Edit Modal
     =========================== -->
<?php require __DIR__ . '/partials/work-item-modal.php'; ?>

<?php else: ?>
<div class="card">
    <div class="card-body text-center" style="padding:3rem;">
        <p class="text-muted" style="font-size:1.125rem;">No work items yet. Generate them from your strategy diagram above.</p>
    </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/partials/workflow-nav.php'; ?>

<!-- SortableJS CDN -->
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>

<!-- Mermaid.js CDN for thumbnail -->
<?php if (!empty($diagram)): ?>
<script src="https://cdn.jsdelivr.net/npm/mermaid@11/dist/mermaid.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    if (typeof mermaid !== 'undefined') {
        mermaid.initialize({ startOnLoad: false, theme: 'default', securityLevel: 'loose' });
        var codeEl   = document.getElementById('mermaid-thumb-code');
        var outputEl = document.getElementById('mermaid-thumb-output');
        if (codeEl && outputEl) {
            var code = codeEl.value.trim();
            if (code) {
                mermaid.render('mermaid-thumb-' + Date.now(), code).then(function(result) {
                    outputEl.innerHTML = result.svg;
                }).catch(function() {
                    outputEl.innerHTML = '<p class="text-muted" style="font-size:0.75rem;">Diagram preview unavailable</p>';
                });
            }
        }
    }
});
</script>
<?php endif; ?>
