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
            <form method="POST" action="/app/diagram/save"
                  data-loading="Saving diagram...">
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
            <form method="POST" action="/app/diagram/generate" class="mt-4"
                  data-loading="Generating strategy diagram..."
                  data-overlay="Generating strategy diagram from your summary. This may take 15-30 seconds.">
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
        <span class="text-muted" style="font-size: 0.8125rem;"><?= count($nodes) ?> nodes</span>
    </div>
    <div class="card-body">
        <form method="POST" action="/app/diagram/save-all-okrs" data-loading="Saving OKRs...">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
            <div class="node-okr-list">
                <?php foreach ($nodes as $node):
                    // Pre-fill title from node label if empty
                    $defaultTitle = $node['okr_title'] ?: ('Achieve: ' . $node['label']);
                    $defaultDesc  = $node['okr_description'] ?: ('Define key results for the "' . $node['label'] . '" initiative. What measurable outcomes indicate success?');
                ?>
                    <div class="node-okr-item">
                        <div class="node-okr-header">
                            <div>
                                <span class="badge badge-primary"><?= htmlspecialchars($node['node_key']) ?></span>
                                <strong><?= htmlspecialchars($node['label']) ?></strong>
                            </div>
                            <button type="button" class="btn btn-sm btn-danger" style="padding: 0.25rem 0.5rem; font-size: 0.75rem;"
                                    onclick="if(confirm('Remove this node OKR?')) { this.closest('.node-okr-item').remove(); }">
                                Remove
                            </button>
                        </div>
                        <div class="node-okr-fields">
                            <input type="hidden" name="nodes[<?= (int) $node['id'] ?>][id]" value="<?= (int) $node['id'] ?>">
                            <div class="form-group">
                                <label>OKR Title</label>
                                <input type="text"
                                       name="nodes[<?= (int) $node['id'] ?>][okr_title]"
                                       value="<?= htmlspecialchars($defaultTitle) ?>"
                                       class="form-control"
                                       placeholder="e.g. Increase market penetration by 20%">
                            </div>
                            <div class="form-group">
                                <label>OKR Description</label>
                                <textarea name="nodes[<?= (int) $node['id'] ?>][okr_description]"
                                          class="form-control"
                                          rows="2"
                                          placeholder="Describe the objective and key results..."
                                ><?= htmlspecialchars($defaultDesc) ?></textarea>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div style="margin-top: 1rem; display: flex; justify-content: flex-end;">
                <button type="submit" class="btn btn-primary">Save All OKRs</button>
            </div>
        </form>
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
<script defer src="https://cdn.jsdelivr.net/npm/mermaid@11/dist/mermaid.min.js"></script>
