<?php
/**
 * Strategy Roadmap Template
 *
 * Progressive UX: shows the right thing at the right time.
 * - No diagram: single CTA to generate
 * - Diagram exists: visual roadmap + OKRs
 * - Code editor: hidden toggle for power users
 */
$hasDiagram = !empty($diagram);
$hasNodes   = !empty($nodes);
$hasSummary = !empty($document_summary);
?>

<div id="diagram-page"
     data-project-id="<?= (int) $project['id'] ?>"
     data-csrf-token="<?= htmlspecialchars($csrf_token) ?>"></div>

<div class="page-header flex justify-between items-center">
    <h1 class="page-title">
        <?= htmlspecialchars($project['name']) ?> &mdash; Strategy Roadmap
        <?php if ($hasDiagram): ?>
            <span class="page-info" tabindex="0" role="button" aria-label="About this page">
                <span class="page-info-btn" aria-hidden="true">i</span>
                <span class="page-info-popover" role="tooltip">Your visual strategy roadmap. Review initiatives and dependencies, set SMART OKRs for each node, then proceed to generate work items.</span>
            </span>
        <?php endif; ?>
    </h1>
    <div class="flex items-center gap-2">
        <?php if ($hasDiagram): ?>
            <button type="button" id="generate-diagram-btn" class="btn btn-ai btn-sm js-generate-diagram">Regenerate</button>
        <?php endif; ?>
        <?php $active_page = 'roadmap'; include __DIR__ . '/partials/sounding-board-button.php'; ?>
    </div>
</div>

                <div id="generate-status" class="generate-status-banner hidden"></div>

<?php if (!$hasDiagram): ?>
<section class="card diagram-empty-card">
    <div class="card-body diagram-empty-card__body">
        <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="1.5" class="diagram-empty-card__icon">
            <rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/>
            <rect x="8" y="14" width="8" height="7" rx="1"/><line x1="6.5" y1="10" x2="6.5" y2="14"/>
            <line x1="17.5" y1="10" x2="17.5" y2="14"/><line x1="6.5" y1="14" x2="12" y2="14"/>
            <line x1="17.5" y1="14" x2="12" y2="14"/>
        </svg>

        <h2 class="diagram-empty-card__title">Your strategy summary is ready</h2>
        <p class="text-muted diagram-empty-card__copy">
            AI will analyse your document and create a visual roadmap showing strategic initiatives, dependencies, and phases. This takes about 10-20 seconds.
        </p>
        <button type="button" id="generate-diagram-btn" class="btn btn-ai btn-lg js-generate-diagram diagram-empty-card__button">
            Generate Roadmap
        </button>
                <div id="generate-status-empty" class="generate-status-banner generate-status-banner--compact hidden"></div>
    </div>
</section>

<?php else: ?>
<section class="card mb-6" id="diagram-view">
    <div class="card-body diagram-view__body">
        <div id="mermaid-output"></div>
    </div>
</section>

<textarea id="mermaid-code" class="hidden"><?= htmlspecialchars($diagram['mermaid_code'] ?? '') ?></textarea>

<?php if ($hasNodes): ?>
<section class="card mb-6">
    <div class="card-header flex justify-between items-center">
        <div>
            <h3 class="diagram-section-title">Objectives & Key Results</h3>
            <span class="text-muted diagram-section-subtitle"><?= count($nodes) ?> strategic initiatives</span>
        </div>
        <div class="flex items-center gap-2">
            <?php
            try {
                $jiraKey = $project['jira_project_key'] ?? '';
                if ($jiraKey !== '') {
                    $goalsIntegration = \StratFlow\Models\Integration::findByOrgAndProvider(
                        \StratFlow\Core\Database::getInstance(), (int) ($project['org_id'] ?? 0), 'jira'
                    );
                    if ($goalsIntegration && $goalsIntegration['status'] === 'active') {
            ?>
                <form method="POST" action="/app/jira/sync" class="inline-form"
                      data-loading="Syncing to Goals..." data-overlay="Pushing OKRs to Atlassian Goals.">
                    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
                    <input type="hidden" name="sync_type" value="work_items">
                    <button type="submit" class="btn btn-secondary btn-sm" data-confirm="Sync OKRs to Atlassian Goals?">Sync to Goals</button>
                </form>
            <?php } } } catch (\Throwable $e) {} ?>
            <button type="button" class="btn btn-secondary btn-sm js-open-okr-modal">
                + Add OKR
            </button>
            <form method="POST" action="/app/diagram/generate-okrs" class="inline-form"
                  data-loading="Generating OKRs..." data-overlay="AI is generating SMART objectives and key results. This may take 15-30 seconds.">
                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
                <button type="submit" class="btn btn-ai btn-sm" data-confirm="Generate SMART OKRs for all nodes? This will replace existing OKRs.">
                    Generate OKRs (AI)
                </button>
            </form>
        </div>
    </div>
    <div class="card-body diagram-okr-card__body">
        <form method="POST" action="/app/diagram/save-all-okrs" data-loading="Saving OKRs...">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
            <div class="accordion-list" id="okr-accordion-list">
            <?php foreach ($nodes as $idx => $node):
                $hasOkr = !empty($node['okr_title']);
                $isOpen = false;
            ?>
                <div class="accordion-item <?= $hasOkr ? 'accordion-item--complete' : '' ?> <?= $isOpen ? 'accordion-item--open' : '' ?>"
                     id="okr-node-<?= htmlspecialchars($node['node_key']) ?>"
                     data-node-key="<?= htmlspecialchars($node['node_key']) ?>">
                    <input type="hidden" name="nodes[<?= (int) $node['id'] ?>][id]" value="<?= (int) $node['id'] ?>">
                    <button type="button" class="accordion-header js-diagram-accordion-toggle">
                        <span class="badge badge-primary diagram-node-key-badge"><?= htmlspecialchars($node['node_key']) ?></span>
                        <span class="accordion-title"><?= htmlspecialchars($node['label']) ?></span>
                        <?php if ($hasOkr): ?>
                            <span class="badge badge-success diagram-node-status-badge">OKR set</span>
                        <?php else: ?>
                            <span class="badge badge-secondary diagram-node-status-badge">No OKR</span>
                        <?php endif; ?>
                        <svg class="accordion-chevron diagram-accordion-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                            <polyline points="6 9 12 15 18 9"/>
                        </svg>
                    </button>
                    <div class="accordion-body">
                        <div class="form-group diagram-form-group">
                            <label class="form-label diagram-form-label">Objective</label>
                            <input type="text"
                                   name="nodes[<?= (int) $node['id'] ?>][okr_title]"
                                   value="<?= htmlspecialchars($node['okr_title'] ?? '') ?>"
                                   class="form-control diagram-form-control"
                                   placeholder="e.g. Launch AU market presence with 3 pilots by Q3">
                        </div>
                        <div class="form-group diagram-form-group diagram-form-group--flush">
                            <label class="form-label diagram-form-label">Key Results</label>
                            <textarea name="nodes[<?= (int) $node['id'] ?>][okr_description]"
                                      class="form-control diagram-form-control diagram-form-control--textarea" rows="3"
                                      placeholder="KR1: Signed LOIs with 3 Tier-1 banks by end of Q1&#10;KR2: Pilot projects kicked off for 2 banks by mid-Q2&#10;KR3: $500k in committed pipeline by end of Q2"
                            ><?= htmlspecialchars($node['okr_description'] ?? '') ?></textarea>
                        </div>
                        <div class="diagram-okr-actions">
                            <form method="POST" action="/app/diagram/delete-okr" class="inline-form">
                                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
                                <input type="hidden" name="node_id" value="<?= (int) $node['id'] ?>">
                                <button type="submit" class="btn btn-danger btn-sm" data-confirm="Delete this OKR? This cannot be undone.">Delete OKR</button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
            <div class="diagram-okr-footer">
                <button type="submit" class="btn btn-primary">Save All OKRs</button>
            </div>
        </form>
    </div>
</section>
<?php endif; ?>

<?php endif; ?>

<div id="add-okr-modal" class="modal-overlay hidden">
    <div class="modal">
        <div class="modal-header">
            <h3>Add OKR Manually</h3>
            <button type="button" class="modal-close js-close-okr-modal">&times;</button>
        </div>
        <form method="POST" action="/app/diagram/add-okr">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
            <div class="modal-body diagram-modal-body">
                <div class="form-group diagram-modal-form-group">
                    <label class="form-label">Strategic Initiative <span class="diagram-required">*</span></label>
                    <select name="node_id" class="form-control" required>
                        <option value="">-- Select a strategic initiative --</option>
                        <?php foreach ($nodes as $n): ?>
                        <option value="<?= (int) $n['id'] ?>">
                            <?= htmlspecialchars($n['node_key'] . ': ' . $n['label'], ENT_QUOTES, 'UTF-8') ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group diagram-modal-form-group">
                    <label class="form-label">Objective</label>
                    <input type="text" name="okr_title" class="form-control" maxlength="500"
                           placeholder="e.g. Establish presence in AU market by Q3 2026">
                </div>
                <div class="form-group diagram-modal-form-group">
                    <label class="form-label">Key Results</label>
                    <textarea name="okr_description" class="form-control" rows="4"
                              placeholder="KR1: Sign 3 pilot agreements by Q2&#10;KR2: Generate $500k pipeline by Q3&#10;KR3: ..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm js-close-okr-modal">Cancel</button>
                <button type="submit" class="btn btn-primary btn-sm">Add OKR</button>
            </div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/partials/workflow-nav.php'; ?>

<?php if ($hasDiagram && $hasNodes): ?>
<textarea id="diagram-node-data" class="hidden"><?= htmlspecialchars(json_encode(array_values($nodes), JSON_HEX_TAG), ENT_NOQUOTES, 'UTF-8') ?></textarea>
<div id="node-okr-panel" class="diagram-node-panel">
    <div class="diagram-node-panel__header">
        <div>
            <div class="diagram-node-panel__eyebrow">OKRs for:</div>
            <h3 id="node-okr-title" class="diagram-node-panel__title"></h3>
        </div>
        <button type="button" class="js-close-node-okr-panel diagram-node-panel__close">&times;</button>
    </div>
    <div class="diagram-node-panel__body">
        <input type="hidden" id="node-okr-node-id">
        <div class="form-group diagram-modal-form-group">
            <label for="node-okr-objective" class="diagram-node-panel__label">Objective:</label>
            <input type="text" id="node-okr-objective" class="form-control"
                   placeholder="e.g. Launch AU market presence with 3 pilots by Q3">
        </div>
        <div class="form-group diagram-modal-form-group diagram-node-panel__field">
            <label for="node-okr-keyresults" class="diagram-node-panel__label">Key Results:</label>
            <textarea id="node-okr-keyresults" class="form-control diagram-node-panel__textarea"
                      placeholder="KR1: Signed LOIs with 3 Tier-1 banks by end of Q1&#10;KR2: Pilot projects kicked off for 2 banks by mid-Q2"></textarea>
        </div>
    </div>
    <div class="diagram-node-panel__footer">
        <span id="node-okr-save-status" class="diagram-node-panel__status"></span>
        <button type="button" id="node-okr-save-btn" class="btn btn-primary js-save-node-okr diagram-node-panel__save">Save OKRs to Node</button>
    </div>
</div>
<?php endif; ?>

<script defer src="https://cdn.jsdelivr.net/npm/mermaid@11.14.0/dist/mermaid.min.js" integrity="sha384-/wpvwpx/82U/rD5MBk1sSp5IpBRhvzoZNsocF4/AIyIn1G8kobtnIsjaqd06GUO8" crossorigin="anonymous"></script>
