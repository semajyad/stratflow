<?php
/**
 * Strategy Diagram Template
 *
 * Two-column layout: Mermaid diagram render (left) and code editor (right).
 * Below the columns, a list of parsed nodes with OKR input fields.
 *
 * Variables: $project (array), $diagram (?array), $nodes (array),
 *            $document_summary (?string), $csrf_token (string)
 */
?>

<!-- ===========================
     Page Header
     =========================== -->
<div class="page-header flex justify-between items-center">
    <h1 class="page-title"><?= htmlspecialchars($project['name']) ?> &mdash; Strategy Diagram</h1>
    <a href="/app/upload?project_id=<?= (int) $project['id'] ?>" class="btn btn-secondary btn-sm">Back to Upload</a>
</div>

<!-- ===========================
     Page Description
     =========================== -->
<div class="page-description">
    Your strategy diagram visually maps the initiatives and dependencies from your uploaded documents. Click nodes to add OKRs, edit the Mermaid code directly, or regenerate from your summary.
</div>

<!-- ===========================
     Document Summary Context
     =========================== -->
<?php if (!empty($document_summary)): ?>
    <div class="info-box mb-6">
        <strong>AI Summary:</strong>
        <p><?= htmlspecialchars($document_summary) ?></p>
    </div>
<?php endif; ?>

<!-- ===========================
     Diagram + Editor (Two Columns)
     =========================== -->
<div class="diagram-container mb-6">
    <!-- Left: Rendered Diagram -->
    <div class="diagram-render card">
        <div class="card-header">
            <h3>Diagram Preview</h3>
        </div>
        <div class="card-body">
            <div id="mermaid-output">
                <?php if (empty($diagram)): ?>
                    <p class="text-muted">No diagram generated yet. Use the controls on the right to generate one.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Right: Code Editor -->
    <div class="diagram-editor card">
        <div class="card-header">
            <h3>Mermaid Code</h3>
        </div>
        <div class="card-body">
            <!-- Save Code Form -->
            <form method="POST" action="/app/diagram/save">
                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">

                <div class="form-group">
                    <textarea
                        name="mermaid_code"
                        id="mermaid-code"
                        rows="14"
                        placeholder="graph TD&#10;    A[Phase 1] --> B[Phase 2]&#10;    B --> C[Phase 3]"
                    ><?= htmlspecialchars($diagram['mermaid_code'] ?? '') ?></textarea>
                </div>

                <div class="flex gap-2">
                    <?php if (!empty($diagram)): ?>
                        <button type="submit" class="btn btn-primary">Save Code</button>
                    <?php endif; ?>
                </div>
            </form>

            <!-- Regenerate Form -->
            <form method="POST" action="/app/diagram/generate" class="mt-4">
                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
                <button type="submit" class="btn btn-secondary btn-block">
                    <?= empty($diagram) ? 'Generate from Summary' : 'Regenerate from Summary' ?>
                </button>
            </form>

            <?php if (!empty($diagram)): ?>
                <p class="text-muted mt-4" style="font-size: 0.8rem;">
                    Version <?= (int) $diagram['version'] ?>
                    &middot; Updated <?= date('j M Y, g:ia', strtotime($diagram['updated_at'])) ?>
                </p>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ===========================
     Node OKRs
     =========================== -->
<?php if (!empty($nodes)): ?>
<section class="card mb-6">
    <div class="card-header">
        <h3>Node OKRs</h3>
    </div>
    <div class="card-body">
        <div class="node-okr-list">
            <?php foreach ($nodes as $node): ?>
                <div class="node-okr-item" data-node-id="<?= (int) $node['id'] ?>">
                    <div class="node-okr-header">
                        <span class="badge badge-primary"><?= htmlspecialchars($node['node_key']) ?></span>
                        <strong><?= htmlspecialchars($node['label']) ?></strong>
                    </div>
                    <div class="node-okr-fields">
                        <div class="form-group">
                            <label>OKR Title</label>
                            <input
                                type="text"
                                class="okr-title"
                                value="<?= htmlspecialchars($node['okr_title'] ?? '') ?>"
                                placeholder="e.g. Increase market penetration by 20%"
                            >
                        </div>
                        <div class="form-group">
                            <label>OKR Description</label>
                            <textarea
                                class="okr-description"
                                rows="2"
                                placeholder="Describe the objective and key results..."
                            ><?= htmlspecialchars($node['okr_description'] ?? '') ?></textarea>
                        </div>
                        <button type="button" class="btn btn-sm btn-secondary save-okr-btn">Save OKR</button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ===========================
     Next Step
     =========================== -->
<div class="flex justify-between items-center">
    <a href="/app/work-items?project_id=<?= (int) $project['id'] ?>" class="btn btn-primary btn-lg">
        Work Items
    </a>
</div>
<?php endif; ?>

<?php require __DIR__ . '/partials/workflow-nav.php'; ?>

<!-- Mermaid.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/mermaid@11/dist/mermaid.min.js"></script>
