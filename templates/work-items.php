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
<div class="page-header flex justify-between items-center">
    <h1 class="page-title">
        <?= htmlspecialchars($project['name'], ENT_QUOTES, 'UTF-8') ?> &mdash; Work Items
        <span class="page-title-count"><?= count($work_items) ?></span>
        <span class="page-info" tabindex="0" role="button" aria-label="About this page">
            <span class="page-info-btn" aria-hidden="true">i</span>
            <span class="page-info-popover" role="tooltip">Work items represent approximately one month of effort for a Scrum team — these become Epics when synced to Jira. Drag to reorder, edit details, or generate AI descriptions.</span>
        </span>
    </h1>
    <div class="flex items-center gap-2">
        <?php $sync_type = 'work_items'; include __DIR__ . '/partials/jira-sync-button.php'; ?>
        <?php include __DIR__ . '/partials/sounding-board-button.php'; ?>
    </div>
</div>

<!-- ===========================
     Mini Diagram Thumbnail
     =========================== -->
<?php if (!empty($diagram)): ?>
<div class="diagram-thumbnail">
    <div class="diagram-thumbnail-inner" id="mermaid-thumb-output"></div>
    <textarea id="mermaid-thumb-code" class="visually-hidden"><?= htmlspecialchars($diagram['mermaid_code'], ENT_QUOTES, 'UTF-8') ?></textarea>
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
        <div class="flex items-center gap-2" style="flex-wrap: wrap; margin-left: auto;">
            <form method="POST" action="/app/work-items/generate"
                  data-loading="Generating work items..."
                  data-overlay="Generating work items from diagram. This may take 15-30 seconds.">
                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
                <button type="submit" class="btn btn-ai"
                        <?php if (!empty($work_items)): ?>
                        data-confirm="This will replace all existing work items. Continue?"
                        <?php endif; ?>>
                    <?= empty($work_items) ? 'Generate Work Items' : 'Regenerate Work Items' ?>
                </button>
            </form>
            <?php if (!empty($work_items)): ?>
            <form method="POST" action="/app/work-items/regenerate-sizing"
                  data-loading="Regenerating sizing estimates..."
                  data-overlay="Re-estimating sprint sizing for all work items using AI.">
                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
                <button type="submit" class="btn btn-ai"
                        data-confirm="Re-estimate sprint sizing for all work items using AI?">
                    Regenerate Sizing
                </button>
            </form>
            <button
                type="button"
                class="btn btn-secondary js-open-work-item-modal"
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

<!-- ===========================
     KR Editor Stash (hidden)
     Pre-rendered per item; JS moves the matching one into the modal
     when it opens, and returns it here when it closes.
     =========================== -->
<div id="kr-editor-stash" class="hidden" aria-hidden="true">
    <?php foreach ($work_items as $item): ?>
        <div class="kr-editor-wrapper" data-item-id="<?= (int) $item['id'] ?>">
            <?php
                $work_item   = $item;
                $key_results = $krs_by_item_id[(int) $item['id']] ?? [];
                include __DIR__ . '/partials/kr-editor.php';
            ?>
        </div>
    <?php endforeach; ?>
</div>

<?php else: ?>
<div class="card">
    <div class="card-body text-center" style="padding:3rem;">
        <p class="text-muted" style="font-size:1.125rem;">No work items yet. Generate them from your strategy diagram above.</p>
    </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/partials/workflow-nav.php'; ?>

<!-- SortableJS CDN -->
<script defer src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>

<!-- Mermaid.js CDN for thumbnail -->
<?php if (!empty($diagram)): ?>
<script defer src="https://cdn.jsdelivr.net/npm/mermaid@11/dist/mermaid.min.js"></script>
<?php endif; ?>
