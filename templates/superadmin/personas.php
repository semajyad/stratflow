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
                                 <button type="button" class="btn btn-secondary btn-sm js-open-level-modal">
                                     &#9881; Manage Level Prompts
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

    <!-- ===========================
         Critical Review Modal (Editable Defaults)
         =========================== -->
    <div class="modal-overlay" id="level-prompts-modal" style="display:none;">
        <div class="modal-content modal-lg">
            <div class="modal-header">
                <h2 class="modal-title">Manage Critical Review Levels</h2>
                <button type="button" class="modal-close js-close-modal" data-modal="level-prompts-modal">&times;</button>
            </div>
            <div class="modal-body">
                <p class="text-muted mb-4" style="font-size: 0.9rem;">
                    These are the default system-wide prompts used for the three criticism levels across all sounding panels.
                </p>

                <div class="form-group mb-4">
                    <label class="form-label" style="display: flex; align-items: center; gap: 0.5rem;">
                        <span style="font-size: 1.2rem;">&#128520;</span> 1. Devil's Advocate
                    </label>
                    <textarea name="level_devils_advocate" class="form-control" rows="3"><?= htmlspecialchars($evaluation_levels['devils_advocate'] ?? '') ?></textarea>
                    <small class="text-muted">Challenge the idea by pointing out flaws, counterarguments, and missing evidence.</small>
                </div>

                <div class="form-group mb-4">
                    <label class="form-label" style="display: flex; align-items: center; gap: 0.5rem;">
                        <span style="font-size: 1.2rem;">&#127919;</span> 2. Red Teaming
                    </label>
                    <textarea name="level_red_teaming" class="form-control" rows="3"><?= htmlspecialchars($evaluation_levels['red_teaming'] ?? '') ?></textarea>
                    <small class="text-muted">Find and poke holes — expose flaws, loopholes, and weaknesses.</small>
                </div>

                <div class="form-group mb-4">
                    <label class="form-label" style="display: flex; align-items: center; gap: 0.5rem;">
                        <span style="font-size: 1.2rem;">&#128298;</span> 3. The Gordon Ramsay Treatment
                    </label>
                    <textarea name="level_gordon_ramsay" class="form-control" rows="3"><?= htmlspecialchars($evaluation_levels['gordon_ramsay'] ?? '') ?></textarea>
                    <small class="text-muted">Surgical critique. What's wrong and what needs to be completely redone?</small>
                </div>

                <div class="modal-actions mt-4 text-right">
                    <button type="button" class="btn btn-primary js-close-modal" data-modal="level-prompts-modal">Done</button>
                </div>
            </div>
        </div>
    </div>
</form>

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
    const levelModal = document.getElementById('level-prompts-modal');
    
    // Open level modal
    document.querySelectorAll('.js-open-level-modal').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            levelModal.style.display = 'flex';
        });
    });

    // Close modal
    document.querySelectorAll('.js-close-modal').forEach(btn => {
        btn.addEventListener('click', function() {
            const modalId = this.getAttribute('data-modal') || 'level-prompts-modal';
            const target = document.getElementById(modalId);
            if (target) target.style.display = 'none';
        });
    });

    // Close on overlay click
    levelModal.addEventListener('click', (e) => {
        if (e.target === levelModal) levelModal.style.display = 'none';
    });

    // Close on escape
    window.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') levelModal.style.display = 'none';
    });

    // Helper for accordions
    document.querySelectorAll('.js-accordion-toggle').forEach(btn => {
        btn.addEventListener('click', () => {
            btn.parentElement.classList.toggle('accordion-item--open');
        });
    });
})();
</script>
