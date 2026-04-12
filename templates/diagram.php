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
    </div>
</div>

<div id="generate-status" style="display:none; margin-bottom:1rem; padding:0.75rem 1rem; border-radius:6px; font-size:0.9rem;"></div>

<?php if (!$hasDiagram): ?>
<section class="card" style="max-width: 640px; margin: 3rem auto;">
    <div class="card-body" style="text-align: center; padding: 3rem 2rem;">
        <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="1.5" style="margin-bottom: 1.5rem; opacity: 0.7;">
            <rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/>
            <rect x="8" y="14" width="8" height="7" rx="1"/><line x1="6.5" y1="10" x2="6.5" y2="14"/>
            <line x1="17.5" y1="10" x2="17.5" y2="14"/><line x1="6.5" y1="14" x2="12" y2="14"/>
            <line x1="17.5" y1="14" x2="12" y2="14"/>
        </svg>

        <h2 style="margin: 0 0 0.75rem; font-size: 1.25rem;">Your strategy summary is ready</h2>
        <p class="text-muted" style="margin-bottom: 1.5rem; max-width: 460px; margin-left: auto; margin-right: auto;">
            AI will analyse your document and create a visual roadmap showing strategic initiatives, dependencies, and phases. This takes about 10-20 seconds.
        </p>
        <button type="button" id="generate-diagram-btn" class="btn btn-ai btn-lg js-generate-diagram" style="padding: 0.75rem 2rem; font-size: 1rem;">
            Generate Roadmap
        </button>
        <div id="generate-status-empty" style="display:none; margin-top:1.25rem; padding:0.75rem; border-radius:6px; font-size:0.875rem;"></div>
    </div>
</section>

<?php else: ?>
<section class="card mb-6" id="diagram-view">
    <div class="card-body" style="min-height: 300px; overflow: auto;">
        <div id="mermaid-output"></div>
    </div>
</section>

<textarea id="mermaid-code" style="display:none;"><?= htmlspecialchars($diagram['mermaid_code'] ?? '') ?></textarea>

<?php if ($hasNodes): ?>
<section class="card mb-6">
    <div class="card-header flex justify-between items-center">
        <div>
            <h3 style="margin:0;">Objectives & Key Results</h3>
            <span class="text-muted" style="font-size: 0.8125rem;"><?= count($nodes) ?> strategic initiatives</span>
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
    <div class="card-body" style="padding: 0;">
        <form method="POST" action="/app/diagram/save-all-okrs" data-loading="Saving OKRs...">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
            <div class="accordion-list" id="okr-accordion-list">
            <?php foreach ($nodes as $idx => $node):
                $hasOkr = !empty($node['okr_title']);
                $isOpen = ($idx === 0);
            ?>
                <div class="accordion-item <?= $hasOkr ? 'accordion-item--complete' : '' ?> <?= $isOpen ? 'accordion-item--open' : '' ?>"
                     id="okr-node-<?= htmlspecialchars($node['node_key']) ?>"
                     data-node-key="<?= htmlspecialchars($node['node_key']) ?>">
                    <input type="hidden" name="nodes[<?= (int) $node['id'] ?>][id]" value="<?= (int) $node['id'] ?>">
                    <button type="button" class="accordion-header js-diagram-accordion-toggle">
                        <span class="badge badge-primary" style="flex-shrink:0;"><?= htmlspecialchars($node['node_key']) ?></span>
                        <span class="accordion-title"><?= htmlspecialchars($node['label']) ?></span>
                        <?php if ($hasOkr): ?>
                            <span class="badge badge-success" style="font-size:0.7rem; flex-shrink:0;">OKR set</span>
                        <?php else: ?>
                            <span class="badge badge-secondary" style="font-size:0.7rem; flex-shrink:0;">No OKR</span>
                        <?php endif; ?>
                        <svg class="accordion-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="flex-shrink:0; margin-left:auto;">
                            <polyline points="6 9 12 15 18 9"/>
                        </svg>
                    </button>
                    <div class="accordion-body">
                        <div class="form-group" style="margin-bottom:0.75rem;">
                            <label class="form-label" style="font-size:0.75rem;">Objective</label>
                            <input type="text"
                                   name="nodes[<?= (int) $node['id'] ?>][okr_title]"
                                   value="<?= htmlspecialchars($node['okr_title'] ?? '') ?>"
                                   class="form-control" style="font-size:0.875rem;"
                                   placeholder="e.g. Launch AU market presence with 3 pilots by Q3">
                        </div>
                        <div class="form-group" style="margin-bottom:0;">
                            <label class="form-label" style="font-size:0.75rem;">Key Results</label>
                            <textarea name="nodes[<?= (int) $node['id'] ?>][okr_description]"
                                      class="form-control" style="font-size:0.85rem;" rows="3"
                                      placeholder="KR1: Signed LOIs with 3 Tier-1 banks by end of Q1&#10;KR2: Pilot projects kicked off for 2 banks by mid-Q2&#10;KR3: $500k in committed pipeline by end of Q2"
                            ><?= htmlspecialchars($node['okr_description'] ?? '') ?></textarea>
                        </div>
                        <div style="display:flex; justify-content:flex-end; margin-top:0.75rem;">
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
            <div style="padding: 1rem 1.5rem; display: flex; justify-content: flex-end;">
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
            <div class="modal-body" style="display:flex;flex-direction:column;gap:1rem;">
                <div class="form-group" style="margin:0;">
                    <label class="form-label">Strategic Initiative <span style="color:#ef4444;">*</span></label>
                    <select name="node_id" class="form-control" required>
                        <option value="">-- Select a strategic initiative --</option>
                        <?php foreach ($nodes as $n): ?>
                        <option value="<?= (int) $n['id'] ?>">
                            <?= htmlspecialchars($n['node_key'] . ': ' . $n['label'], ENT_QUOTES, 'UTF-8') ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin:0;">
                    <label class="form-label">Objective</label>
                    <input type="text" name="okr_title" class="form-control" maxlength="500"
                           placeholder="e.g. Establish presence in AU market by Q3 2026">
                </div>
                <div class="form-group" style="margin:0;">
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
<div id="node-okr-panel" style="
    position: fixed; top: 0; right: -380px; width: 360px; height: 100vh;
    background: #fff; box-shadow: -4px 0 24px rgba(0,0,0,0.12);
    z-index: 1000; transition: right 0.25s ease; display: flex; flex-direction: column;
    border-left: 1px solid var(--border, #e5e7eb);
">
    <div style="padding: 1.25rem 1.25rem 1rem; border-bottom: 1px solid var(--border, #e5e7eb); display:flex; align-items:flex-start; justify-content:space-between; gap:0.5rem;">
        <div>
            <div style="font-size:0.75rem; font-weight:600; color:var(--primary, #4f46e5); text-transform:uppercase; letter-spacing:0.05em; margin-bottom:0.25rem;">OKRs for:</div>
            <h3 id="node-okr-title" style="margin:0; font-size:1.0625rem; font-weight:700; color:var(--text, #111827); line-height:1.3;"></h3>
        </div>
        <button type="button" class="js-close-node-okr-panel" style="background:none; border:none; cursor:pointer; font-size:1.375rem; color:var(--text-secondary, #6b7280); line-height:1; padding:0; flex-shrink:0;">&times;</button>
    </div>
    <div style="padding: 1.25rem; flex:1; overflow-y:auto; display:flex; flex-direction:column; gap:1rem;">
        <input type="hidden" id="node-okr-node-id">
        <div class="form-group" style="margin:0;">
            <label for="node-okr-objective" style="font-weight:600;">Objective:</label>
            <input type="text" id="node-okr-objective" class="form-control"
                   placeholder="e.g. Launch AU market presence with 3 pilots by Q3">
        </div>
        <div class="form-group" style="margin:0; flex:1; display:flex; flex-direction:column;">
            <label for="node-okr-keyresults" style="font-weight:600;">Key Results:</label>
            <textarea id="node-okr-keyresults" class="form-control" style="flex:1; min-height:200px; resize:vertical;"
                      placeholder="KR1: Signed LOIs with 3 Tier-1 banks by end of Q1&#10;KR2: Pilot projects kicked off for 2 banks by mid-Q2"></textarea>
        </div>
    </div>
    <div style="padding: 1rem 1.25rem; border-top: 1px solid var(--border, #e5e7eb);">
        <span id="node-okr-save-status" style="font-size:0.8125rem; display:block; margin-bottom:0.5rem; min-height:1.2em;"></span>
        <button type="button" id="node-okr-save-btn" class="btn btn-primary js-save-node-okr" style="width:100%;">Save OKRs to Node</button>
    </div>
</div>
<?php endif; ?>

<script defer src="https://cdn.jsdelivr.net/npm/mermaid@11/dist/mermaid.min.js"></script>
