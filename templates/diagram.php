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

<div class="page-header flex justify-between items-center">
    <h1 class="page-title">
        <?= htmlspecialchars($project['name']) ?> &mdash; Strategy Roadmap
        <?php if ($hasDiagram): ?>
            <button type="button" class="page-info-btn" aria-label="About this page" aria-expanded="false" onclick="togglePageInfo(this)">i</button>
        <?php endif; ?>
    </h1>
    <div class="flex items-center gap-2">
        <?php if ($hasDiagram): ?>
            <div class="view-toggle" role="tablist" aria-label="Diagram view mode">
                <button type="button" class="view-toggle-btn is-active" data-view="diagram" onclick="setDiagramView('diagram')" role="tab" aria-selected="true">Diagram</button>
                <button type="button" class="view-toggle-btn" data-view="exec" onclick="setDiagramView('exec')" role="tab" aria-selected="false">Executive</button>
            </div>
            <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('code-editor-section').classList.toggle('hidden')">Edit Code</button>
            <button type="button" id="generate-diagram-btn" class="btn btn-secondary btn-sm" onclick="generateDiagramAjax()">Regenerate</button>
        <?php endif; ?>
    </div>
</div>

<?php if ($hasDiagram): ?>
<div class="page-info-panel hidden">
    Your visual strategy roadmap. Review initiatives and dependencies, set SMART OKRs for each node, then proceed to generate work items.
</div>
<?php endif; ?>

<!-- Status messages (AJAX + flash) -->
<div id="generate-status" style="display:none; margin-bottom:1rem; padding:0.75rem 1rem; border-radius:6px; font-size:0.9rem;"></div>

<?php if (!$hasDiagram): ?>
<!-- ===========================
     Empty State: No Diagram Yet
     =========================== -->
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
        <button type="button" id="generate-diagram-btn" class="btn btn-primary btn-lg" onclick="generateDiagramAjax()" style="padding: 0.75rem 2rem; font-size: 1rem;">
            Generate Roadmap
        </button>
        <div id="generate-status-empty" style="display:none; margin-top:1.25rem; padding:0.75rem; border-radius:6px; font-size:0.875rem;"></div>
    </div>
</section>

<?php else: ?>
<!-- ===========================
     Diagram View (Exists)
     =========================== -->

<!-- Visual Roadmap — Full Width -->
<section class="card mb-6" id="diagram-view">
    <div class="card-body" style="min-height: 300px; overflow: auto;">
        <div id="mermaid-output"></div>
    </div>
</section>

<!-- Executive View — Simplified card grid of strategic initiatives -->
<?php if ($hasNodes): ?>
<section class="card mb-6 hidden" id="exec-view">
    <div class="card-body">
        <div class="exec-grid">
            <?php foreach ($nodes as $node):
                $hasOkr = !empty($node['okr_title']);
            ?>
                <div class="exec-card <?= $hasOkr ? 'exec-card--has-okr' : '' ?>">
                    <div class="exec-card-head">
                        <span class="badge badge-primary"><?= htmlspecialchars($node['node_key']) ?></span>
                        <?php if ($hasOkr): ?>
                            <span class="badge badge-success">OKR set</span>
                        <?php else: ?>
                            <span class="badge badge-secondary">No OKR</span>
                        <?php endif; ?>
                    </div>
                    <h4 class="exec-card-title"><?= htmlspecialchars($node['label']) ?></h4>
                    <?php if ($hasOkr): ?>
                        <p class="exec-card-okr"><strong>Objective:</strong> <?= htmlspecialchars($node['okr_title']) ?></p>
                        <?php if (!empty($node['okr_description'])): ?>
                            <pre class="exec-card-kr"><?= htmlspecialchars($node['okr_description']) ?></pre>
                        <?php endif; ?>
                    <?php else: ?>
                        <p class="exec-card-placeholder text-muted">No objective set yet.</p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- Code Editor — Hidden by default -->
<section id="code-editor-section" class="card mb-6 hidden">
    <div class="card-header flex justify-between items-center">
        <h3 style="margin:0;">Mermaid Code</h3>
        <span class="text-muted" style="font-size:0.8rem;">
            Version <?= (int) $diagram['version'] ?> &middot; <?= date('j M Y, g:ia', strtotime($diagram['updated_at'])) ?>
        </span>
    </div>
    <div class="card-body">
        <form method="POST" action="/app/diagram/save" data-loading="Saving...">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
            <textarea name="mermaid_code" id="mermaid-code" rows="12" class="form-control" style="font-family: monospace; font-size: 0.85rem;"><?= htmlspecialchars($diagram['mermaid_code'] ?? '') ?></textarea>
            <div class="flex justify-between items-center mt-2">
                <small class="text-muted">Edit the Mermaid code and save. The diagram will re-render automatically.</small>
                <button type="submit" class="btn btn-primary btn-sm">Save Code</button>
            </div>
        </form>
    </div>
</section>

<!-- Hidden textarea for mermaid rendering when editor is closed -->
<?php if ($hasDiagram): ?>
<textarea id="mermaid-code" style="display:none;"><?= htmlspecialchars($diagram['mermaid_code'] ?? '') ?></textarea>
<?php endif; ?>

<!-- ===========================
     OKRs Section
     =========================== -->
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
                    <button type="submit" class="btn btn-secondary btn-sm" onclick="return confirm('Sync OKRs to Atlassian Goals?')">Sync to Goals</button>
                </form>
            <?php } } } catch (\Throwable $e) {} ?>
            <form method="POST" action="/app/diagram/generate-okrs" class="inline-form"
                  data-loading="Generating OKRs..." data-overlay="AI is generating SMART objectives and key results. This may take 15-30 seconds.">
                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
                <button type="submit" class="btn btn-primary btn-sm"
                        onclick="return confirm('Generate SMART OKRs for all nodes? This will replace existing OKRs.')">
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
                // Expand first node by default, collapse others
                $isOpen = ($idx === 0);
            ?>
                <div class="accordion-item <?= $hasOkr ? 'accordion-item--complete' : '' ?>">
                    <input type="hidden" name="nodes[<?= (int) $node['id'] ?>][id]" value="<?= (int) $node['id'] ?>">
                    <button type="button" class="accordion-header" onclick="this.parentElement.classList.toggle('accordion-item--open');">
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
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
            <script>
                // Open first accordion item by default
                (function() {
                    var first = document.querySelector('#okr-accordion-list .accordion-item');
                    if (first) first.classList.add('accordion-item--open');
                })();
            </script>
            <div style="padding: 1rem 1.5rem; display: flex; justify-content: flex-end;">
                <button type="submit" class="btn btn-primary">Save All OKRs</button>
            </div>
        </form>
    </div>
</section>
<?php endif; ?>

<?php endif; /* end hasDiagram */ ?>

<?php require __DIR__ . '/partials/workflow-nav.php'; ?>

<script defer src="https://cdn.jsdelivr.net/npm/mermaid@11/dist/mermaid.min.js"></script>

<script>
function setDiagramView(view) {
    var diagramView = document.getElementById('diagram-view');
    var execView    = document.getElementById('exec-view');
    var btns        = document.querySelectorAll('.view-toggle-btn');
    btns.forEach(function(b) {
        var active = (b.dataset.view === view);
        b.classList.toggle('is-active', active);
        b.setAttribute('aria-selected', active ? 'true' : 'false');
    });
    if (view === 'exec') {
        if (diagramView) diagramView.classList.add('hidden');
        if (execView)    execView.classList.remove('hidden');
    } else {
        if (diagramView) diagramView.classList.remove('hidden');
        if (execView)    execView.classList.add('hidden');
    }
    try { localStorage.setItem('stratflow.diagramView', view); } catch (e) {}
}

// Restore last-used view
(function() {
    try {
        var saved = localStorage.getItem('stratflow.diagramView');
        if (saved === 'exec') {
            setTimeout(function() { setDiagramView('exec'); }, 50);
        }
    } catch (e) {}
})();

function generateDiagramAjax() {
    var btn = document.getElementById('generate-diagram-btn');
    // Use whichever status element exists
    var status = document.getElementById('generate-status');
    var statusEmpty = document.getElementById('generate-status-empty');
    var activeStatus = (status && status.offsetParent !== null) ? status : (statusEmpty || status);

    btn.disabled = true;
    var origText = btn.textContent;
    btn.textContent = 'Generating...';

    if (activeStatus) {
        activeStatus.style.display = 'block';
        activeStatus.style.background = '#e8f0fe';
        activeStatus.style.color = '#1a56db';
        activeStatus.innerHTML = 'AI is analysing your strategy and building a visual roadmap. This usually takes 10-20 seconds...';
    }

    var formData = new FormData();
    formData.append('_csrf_token', '<?= htmlspecialchars($csrf_token) ?>');
    formData.append('project_id', '<?= (int) $project['id'] ?>');

    fetch('/app/diagram/generate', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: formData
    })
    .then(function(r) { return r.json().then(function(d) { return { ok: r.ok, data: d }; }); })
    .then(function(res) {
        if (res.ok && res.data.success) {
            if (activeStatus) {
                activeStatus.style.background = '#d4edda';
                activeStatus.style.color = '#155724';
                activeStatus.innerHTML = 'Roadmap generated with ' + res.data.node_count + ' initiatives. Loading...';
            }
            setTimeout(function() { window.location.reload(); }, 1000);
        } else {
            if (activeStatus) {
                activeStatus.style.background = '#f8d7da';
                activeStatus.style.color = '#721c24';
                activeStatus.innerHTML = (res.data.error || 'Generation failed') + ' <button onclick="generateDiagramAjax()" class="btn btn-sm btn-primary" style="margin-left:0.5rem;">Try Again</button>';
            }
            btn.disabled = false;
            btn.textContent = 'Try Again';
        }
    })
    .catch(function(err) {
        if (activeStatus) {
            activeStatus.style.background = '#f8d7da';
            activeStatus.style.color = '#721c24';
            activeStatus.innerHTML = 'Connection error. <button onclick="generateDiagramAjax()" class="btn btn-sm btn-primary" style="margin-left:0.5rem;">Try Again</button>';
        }
        btn.disabled = false;
        btn.textContent = origText;
    });
}
</script>
