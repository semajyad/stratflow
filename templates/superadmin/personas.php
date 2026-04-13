<?php
/**
 * Superadmin Default Personas Template
 *
 * Accordion-based editor for:
 *   1. Workflow Personas  — 6 roles that drive the AI pipeline
 *   2. Sounding Panel Personas — Executive & Product Management review boards
 *
 * Critical review modals let superadmins run Devil's Advocate / Red Teaming /
 * Gordon Ramsay evaluations from the sounding panel section.
 *
 * Variables: $user (array), $panels (array), $panel_members (array),
 *            $workflow_personas (array), $csrf_token (string)
 */

$reviewScopeLabels = [
    'strategy_okrs'     => 'Strategy Roadmap & OKRs',
    'hl_items_stories'  => 'HL Work Items & User Stories',
];
?>

<!-- ===========================
     Page Header
     =========================== -->
<div class="page-header">
    <h1 class="page-title">Manage Default Personas</h1>
    <p class="page-subtitle">
        <a href="/superadmin">&larr; Back to Superadmin Dashboard</a>
    </p>
</div>

<!-- ===========================
     Flash Messages
     =========================== -->
<?php if (!empty($flash_message)): ?>
    <div class="alert alert-success"><?= htmlspecialchars($flash_message) ?></div>
<?php endif; ?>
<?php if (!empty($flash_error)): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($flash_error) ?></div>
<?php endif; ?>

<form method="POST" action="/superadmin/personas" id="persona-form">
    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

    <div class="settings-stack">

        <!-- ===========================
             1. Workflow Personas Accordion
             =========================== -->
        <div class="accordion-item">
            <button type="button" class="accordion-header js-accordion-toggle">
                <span class="accordion-title">&#9881; Workflow Personas</span>
                <span class="settings-accordion-meta">
                    6 AI personas that drive each stage of the StratFlow pipeline
                </span>
                <svg class="accordion-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <polyline points="6 9 12 15 18 9"/>
                </svg>
            </button>
            <div class="accordion-body">
                <p class="text-muted settings-intro">
                    Each workflow persona guides the AI at a specific pipeline stage. Edit the prompt to change how the AI behaves for that stage.
                </p>

                <?php foreach ($workflow_personas as $key => $persona): ?>
                    <div class="persona-card mb-4">
                        <div class="persona-card-header">
                            <span class="persona-card-title"><?= htmlspecialchars($persona['title']) ?></span>
                            <span class="badge badge-info persona-stage-badge"><?= htmlspecialchars($persona['stage']) ?></span>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="workflow_<?= htmlspecialchars($key) ?>">System prompt</label>
                            <textarea
                                name="workflow_<?= htmlspecialchars($key) ?>"
                                id="workflow_<?= htmlspecialchars($key) ?>"
                                class="form-control"
                                rows="3"
                            ><?= htmlspecialchars($persona['prompt'] ?? '') ?></textarea>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- ===========================
             2. Sounding Panel Personas Accordion
             =========================== -->
        <div class="accordion-item">
            <button type="button" class="accordion-header js-accordion-toggle">
                <span class="accordion-title">&#128483; Sounding Panel Personas</span>
                <span class="settings-accordion-meta">
                    Review boards that evaluate content with configurable critique levels
                </span>
                <svg class="accordion-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <polyline points="6 9 12 15 18 9"/>
                </svg>
            </button>
            <div class="accordion-body">
                <p class="text-muted settings-intro">
                    Each panel is a virtual review board. Edit member prompts to change how each persona evaluates content.
                    Use the <strong>Critical Review</strong> button to preview how the panel responds.
                </p>

                <?php foreach ($panels as $panel): ?>
                    <?php $members = $panel_members[(int) $panel['id']] ?? []; ?>
                    <?php $scope = $panel['review_scope'] ?? ''; ?>

                    <!-- Sub-accordion for each panel -->
                    <div class="accordion-item panel-sub-accordion mb-3">
                        <button type="button" class="accordion-header js-accordion-toggle">
                            <span class="accordion-title"><?= htmlspecialchars($panel['name']) ?></span>
                            <?php if (!empty($scope) && isset($reviewScopeLabels[$scope])): ?>
                                <span class="badge badge-secondary"><?= htmlspecialchars($reviewScopeLabels[$scope]) ?></span>
                            <?php endif; ?>
                            <svg class="accordion-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                <polyline points="6 9 12 15 18 9"/>
                            </svg>
                        </button>
                        <div class="accordion-body">
                            <?php if (empty($members)): ?>
                                <p class="text-muted">No members in this panel.</p>
                            <?php else: ?>
                                <?php foreach ($members as $member): ?>
                                    <div class="form-group mb-4">
                                        <label class="form-label" for="member_<?= (int) $member['id'] ?>">
                                            <?= htmlspecialchars($member['role_title']) ?>
                                        </label>
                                        <textarea
                                            name="member_<?= (int) $member['id'] ?>"
                                            id="member_<?= (int) $member['id'] ?>"
                                            class="form-control"
                                            rows="3"
                                        ><?= htmlspecialchars($member['prompt_description'] ?? '') ?></textarea>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>

                            <!-- Critical Review Button -->
                            <div class="persona-panel-actions">
                                <button type="button" class="btn btn-secondary btn-sm js-open-critical-review"
                                        data-panel-id="<?= (int) $panel['id'] ?>"
                                        data-panel-name="<?= htmlspecialchars($panel['name']) ?>">
                                    &#128270; Run Critical Review
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

    </div><!-- /.settings-stack -->

    <!-- ===========================
         Save Button
         =========================== -->
    <div class="mt-6 persona-actions-bar">
        <button type="submit" class="btn btn-primary">Save All Personas</button>
    </div>
</form>

<!-- ===========================
     Critical Review Modal
     =========================== -->
<div class="modal-overlay" id="critical-review-modal" style="display:none;">
    <div class="modal-content modal-lg">
        <div class="modal-header">
            <h2 class="modal-title" id="cr-modal-title">Critical Review</h2>
            <button type="button" class="modal-close js-close-modal" data-modal="critical-review-modal">&times;</button>
        </div>
        <div class="modal-body">
            <!-- Evaluation Level Selector -->
            <div class="cr-level-selector mb-4">
                <label class="form-label">Select evaluation level:</label>
                <div class="cr-level-options">
                    <label class="cr-level-option">
                        <input type="radio" name="cr_level" value="devils_advocate" checked>
                        <span class="cr-level-card">
                            <span class="cr-level-icon">&#128520;</span>
                            <span class="cr-level-name">Devil's Advocate</span>
                            <span class="cr-level-desc">Challenge with flaws, counterarguments, and missing evidence</span>
                        </span>
                    </label>
                    <label class="cr-level-option">
                        <input type="radio" name="cr_level" value="red_teaming">
                        <span class="cr-level-card">
                            <span class="cr-level-icon">&#127919;</span>
                            <span class="cr-level-name">Red Teaming</span>
                            <span class="cr-level-desc">Hunt for holes, loopholes, and weaknesses</span>
                        </span>
                    </label>
                    <label class="cr-level-option">
                        <input type="radio" name="cr_level" value="gordon_ramsay">
                        <span class="cr-level-card">
                            <span class="cr-level-icon">&#128298;</span>
                            <span class="cr-level-name">Gordon Ramsay</span>
                            <span class="cr-level-desc">Surgical, specific, actionable critique</span>
                        </span>
                    </label>
                </div>
            </div>

            <!-- Content to Review -->
            <div class="form-group mb-4">
                <label class="form-label" for="cr-content">Content to review:</label>
                <textarea id="cr-content" class="form-control" rows="5" placeholder="Paste the strategy, OKRs, work items, or stories you want the panel to evaluate..."></textarea>
            </div>

            <!-- Run Button -->
            <button type="button" class="btn btn-primary" id="cr-run-btn">
                &#9654; Run Review
            </button>

            <!-- Results Area -->
            <div id="cr-results" class="cr-results mt-4" style="display:none;">
                <h3 class="cr-results-title">Panel Responses</h3>
                <div id="cr-results-list" class="cr-results-list"></div>
            </div>

            <!-- Loading Indicator -->
            <div id="cr-loading" class="cr-loading" style="display:none;">
                <div class="spinner"></div>
                <p>Running evaluation — this may take 30-60 seconds…</p>
            </div>
        </div>
    </div>
</div>

<!-- ===========================
     Inline Styles for Persona Cards & Modal
     =========================== -->
<style>
/* Persona card layout */
.persona-card {
    background: var(--bg-card, #fff);
    border: 1px solid var(--border, #e5e7eb);
    border-radius: 8px;
    padding: 1rem;
}
.persona-card-header {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    margin-bottom: 0.75rem;
    flex-wrap: wrap;
}
.persona-card-title {
    font-weight: 600;
    font-size: 0.95rem;
    color: var(--text, #1f2937);
}
.persona-stage-badge {
    font-size: 0.72rem;
    font-weight: 500;
    max-width: 420px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.panel-sub-accordion {
    margin-left: 0;
    border-color: var(--border, #e5e7eb);
}
.persona-panel-actions {
    border-top: 1px solid var(--border, #e5e7eb);
    padding-top: 1rem;
    margin-top: 0.5rem;
}
.persona-actions-bar {
    display: flex;
    gap: 0.75rem;
    align-items: center;
}

/* Critical Review Modal */
.modal-overlay {
    position: fixed;
    inset: 0;
    z-index: 1000;
    background: rgba(0, 0, 0, 0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1rem;
    backdrop-filter: blur(2px);
}
.modal-content {
    background: var(--bg-card, #fff);
    border-radius: 12px;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.25);
    width: 100%;
}
.modal-lg { max-width: 720px; }
.modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid var(--border, #e5e7eb);
}
.modal-title {
    font-size: 1.15rem;
    font-weight: 700;
    margin: 0;
}
.modal-close {
    background: none;
    border: none;
    font-size: 1.5rem;
    cursor: pointer;
    color: var(--text-muted, #6b7280);
    line-height: 1;
}
.modal-close:hover { color: var(--text, #1f2937); }
.modal-body { padding: 1.5rem; }

/* Level Selector */
.cr-level-options {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 0.75rem;
    margin-top: 0.5rem;
}
.cr-level-option input { display: none; }
.cr-level-card {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    padding: 1rem 0.5rem;
    border: 2px solid var(--border, #e5e7eb);
    border-radius: 8px;
    cursor: pointer;
    transition: border-color 150ms ease, background 150ms ease;
}
.cr-level-option input:checked + .cr-level-card {
    border-color: var(--primary, #4f46e5);
    background: rgba(79, 70, 229, 0.05);
}
.cr-level-card:hover {
    border-color: var(--primary, #4f46e5);
}
.cr-level-icon { font-size: 1.5rem; margin-bottom: 0.4rem; }
.cr-level-name { font-weight: 600; font-size: 0.85rem; margin-bottom: 0.2rem; }
.cr-level-desc { font-size: 0.72rem; color: var(--text-muted, #6b7280); line-height: 1.3; }

/* Results */
.cr-results-title { font-size: 1rem; font-weight: 600; margin-bottom: 0.75rem; }
.cr-results-list { display: flex; flex-direction: column; gap: 0.75rem; }
.cr-result-card {
    border: 1px solid var(--border, #e5e7eb);
    border-radius: 8px;
    overflow: hidden;
}
.cr-result-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.75rem 1rem;
    background: var(--bg-surface, #f9fafb);
    cursor: pointer;
}
.cr-result-role { font-weight: 600; font-size: 0.9rem; }
.cr-result-risk { font-size: 0.75rem; font-weight: 500; }
.cr-result-body {
    padding: 1rem;
    font-size: 0.85rem;
    line-height: 1.6;
    white-space: pre-wrap;
}

/* Loading */
.cr-loading {
    text-align: center;
    padding: 2rem 1rem;
    color: var(--text-muted, #6b7280);
}
.cr-loading .spinner {
    width: 32px;
    height: 32px;
    border: 3px solid var(--border, #e5e7eb);
    border-top-color: var(--primary, #4f46e5);
    border-radius: 50%;
    margin: 0 auto 1rem;
    animation: spin 0.8s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }

@media (max-width: 600px) {
    .cr-level-options { grid-template-columns: 1fr; }
}
</style>

<!-- ===========================
     Critical Review JS
     =========================== -->
<script>
(function() {
    var modal      = document.getElementById('critical-review-modal');
    var titleEl    = document.getElementById('cr-modal-title');
    var runBtn     = document.getElementById('cr-run-btn');
    var resultsDiv = document.getElementById('cr-results');
    var resultsList= document.getElementById('cr-results-list');
    var loadingDiv = document.getElementById('cr-loading');
    var contentEl  = document.getElementById('cr-content');
    var panelId    = null;

    // Open modal
    document.querySelectorAll('.js-open-critical-review').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            panelId = this.getAttribute('data-panel-id');
            var panelName = this.getAttribute('data-panel-name');
            titleEl.textContent = 'Critical Review — ' + panelName;
            resultsDiv.style.display = 'none';
            resultsList.innerHTML = '';
            loadingDiv.style.display = 'none';
            contentEl.value = '';
            modal.style.display = 'flex';
        });
    });

    // Close modal
    document.querySelectorAll('.js-close-modal').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var target = document.getElementById(this.getAttribute('data-modal'));
            if (target) target.style.display = 'none';
        });
    });
    modal.addEventListener('click', function(e) {
        if (e.target === modal) modal.style.display = 'none';
    });

    // Run review
    runBtn.addEventListener('click', function() {
        var content = contentEl.value.trim();
        if (!content) {
            alert('Please paste some content to review.');
            return;
        }

        var level = document.querySelector('input[name="cr_level"]:checked');
        if (!level) return;

        runBtn.disabled = true;
        resultsDiv.style.display = 'none';
        resultsList.innerHTML = '';
        loadingDiv.style.display = 'block';

        var csrfToken = document.querySelector('input[name="_csrf_token"]');

        fetch('/superadmin/personas/evaluate', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken ? csrfToken.value : ''
            },
            body: JSON.stringify({
                panel_id:         parseInt(panelId, 10),
                evaluation_level: level.value,
                content:          content
            })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            loadingDiv.style.display = 'none';
            runBtn.disabled = false;

            if (data.status !== 'ok' || !data.results) {
                resultsList.innerHTML = '<p class="text-muted">No results received. ' + (data.message || '') + '</p>';
                resultsDiv.style.display = 'block';
                return;
            }

            data.results.forEach(function(r) {
                var card = document.createElement('div');
                card.className = 'cr-result-card';
                card.innerHTML =
                    '<div class="cr-result-header">' +
                        '<span class="cr-result-role">' + escapeHtml(r.role_title || 'Persona') + '</span>' +
                    '</div>' +
                    '<div class="cr-result-body">' + escapeHtml(r.response || '(no response)') + '</div>';
                resultsList.appendChild(card);
            });
            resultsDiv.style.display = 'block';
        })
        .catch(function(err) {
            loadingDiv.style.display = 'none';
            runBtn.disabled = false;
            resultsList.innerHTML = '<p class="text-danger">Error: ' + escapeHtml(err.message) + '</p>';
            resultsDiv.style.display = 'block';
        });
    });

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
})();
</script>
