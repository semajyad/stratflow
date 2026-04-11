// ===========================
// Sidebar Collapse / Expand
// ===========================
(function() {
    var sidebar = document.getElementById('sidebar');
    var btn = document.getElementById('sidebar-collapse-btn');
    if (!sidebar) return;

    // Apply saved state on load (complements the FOUC-preload inline script)
    var collapsed = false;
    try {
        collapsed = localStorage.getItem('stratflow.sidebarCollapsed') === '1';
    } catch (e) {}
    if (collapsed) {
        sidebar.classList.add('is-collapsed');
        if (btn) {
            btn.setAttribute('aria-pressed', 'true');
            btn.setAttribute('aria-label', 'Expand sidebar');
        }
    }
    // Preload class is no longer needed after hydration
    document.documentElement.classList.remove('sidebar-collapsed-preload');
})();

window.toggleSidebarCollapsed = function() {
    var sidebar = document.getElementById('sidebar');
    var btn = document.getElementById('sidebar-collapse-btn');
    if (!sidebar) return;
    var collapsed = sidebar.classList.toggle('is-collapsed');
    try {
        localStorage.setItem('stratflow.sidebarCollapsed', collapsed ? '1' : '0');
    } catch (e) {}
    if (btn) {
        btn.setAttribute('aria-pressed', collapsed ? 'true' : 'false');
        btn.setAttribute('aria-label', collapsed ? 'Expand sidebar' : 'Collapse sidebar');
    }
};

// ===========================
// Row Actions Kebab Menu
// ===========================
window.toggleRowActions = function(event, btn) {
    event.preventDefault();
    event.stopPropagation();
    var menu = btn.closest('.row-actions-menu');
    if (!menu) return;
    var isOpen = menu.classList.contains('row-actions-menu--open');
    // Close any other open menus
    document.querySelectorAll('.row-actions-menu--open').forEach(function(m) {
        m.classList.remove('row-actions-menu--open');
        var t = m.querySelector('.row-actions-toggle');
        if (t) t.setAttribute('aria-expanded', 'false');
    });
    if (!isOpen) {
        menu.classList.add('row-actions-menu--open');
        btn.setAttribute('aria-expanded', 'true');
    }
};

// Global click-outside / Escape handlers to dismiss open kebab menus.
document.addEventListener('click', function(e) {
    var rowActionsToggle = e.target.closest('.js-row-actions-toggle');
    if (rowActionsToggle) {
        window.toggleRowActions(e, rowActionsToggle);
        return;
    }

    var sidebarCollapseToggle = e.target.closest('.js-sidebar-collapse');
    if (sidebarCollapseToggle) {
        e.preventDefault();
        window.toggleSidebarCollapsed();
        return;
    }

    var sprintEditToggle = e.target.closest('.js-toggle-sprint-edit');
    if (sprintEditToggle) {
        e.preventDefault();
        var sprintId = parseInt(sprintEditToggle.dataset.sprintId || '', 10);
        if (!Number.isNaN(sprintId)) {
            toggleSprintEditForm(sprintId);
        }
        return;
    }

    if (e.target.closest('.js-close-edit-modal')) {
        e.preventDefault();
        closeEditModal();
        return;
    }

    if (e.target.closest('.js-toggle-story-modal')) {
        e.preventDefault();
        toggleStoryModal();
        return;
    }

    if (e.target.closest('.js-close-sounding-board')) {
        e.preventDefault();
        closeSoundingBoard();
        return;
    }

    if (e.target.closest('.js-run-sounding-board')) {
        e.preventDefault();
        runSoundingBoard();
        return;
    }

    var personaAction = e.target.closest('.js-persona-response');
    if (personaAction) {
        e.preventDefault();
        var evalId = parseInt(personaAction.dataset.evalId || '', 10);
        var memberIndex = parseInt(personaAction.dataset.memberIndex || '', 10);
        var action = personaAction.dataset.action || '';
        if (!Number.isNaN(evalId) && !Number.isNaN(memberIndex) && action !== '') {
            respondToPersona(evalId, memberIndex, action);
        }
        return;
    }

    if (e.target.closest('.js-onboarding-skip')) {
        e.preventDefault();
        dismissOnboarding();
        return;
    }

    if (e.target.closest('.js-onboarding-next')) {
        e.preventDefault();
        nextOnboardingStep();
        return;
    }

    if (!e.target.closest('.row-actions-menu')) {
        document.querySelectorAll('.row-actions-menu--open').forEach(function(m) {
            m.classList.remove('row-actions-menu--open');
            var t = m.querySelector('.row-actions-toggle');
            if (t) t.setAttribute('aria-expanded', 'false');
        });
    }
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.row-actions-menu--open').forEach(function(m) {
            m.classList.remove('row-actions-menu--open');
            var t = m.querySelector('.row-actions-toggle');
            if (t) t.setAttribute('aria-expanded', 'false');
        });
    }
});

document.addEventListener('change', function(e) {
    var projectSwitcher = e.target.closest('.js-project-switcher');
    if (!projectSwitcher || !projectSwitcher.value) {
        return;
    }

    var baseUrl = projectSwitcher.dataset.projectBaseUrl || '/app/home';
    window.location = baseUrl + '?project_id=' + encodeURIComponent(projectSwitcher.value);
});

document.addEventListener('submit', function(e) {
    var submitter = e.submitter;
    if (!submitter) {
        return;
    }

    var confirmMessage = submitter.dataset.confirm;
    if (confirmMessage && !window.confirm(confirmMessage)) {
        e.preventDefault();
    }
});

// ===========================
// Sidebar Toggle
// ===========================
document.addEventListener('DOMContentLoaded', function() {
    const toggle  = document.getElementById('sidebar-toggle');
    const sidebar = document.getElementById('sidebar');

    if (toggle && sidebar) {
        toggle.addEventListener('click', function(e) {
            e.stopPropagation();
            sidebar.classList.toggle('open');
            // Add/remove overlay
            let overlay = document.getElementById('sidebar-overlay');
            if (sidebar.classList.contains('open')) {
                if (!overlay) {
                    overlay = document.createElement('div');
                    overlay.id = 'sidebar-overlay';
                    overlay.className = 'sidebar-overlay';
                    overlay.addEventListener('click', function() {
                        sidebar.classList.remove('open');
                        overlay.remove();
                    });
                    document.body.appendChild(overlay);
                }
            } else if (overlay) {
                overlay.remove();
            }
        });
    }

    // ===========================
    // Flash Message Auto-Dismiss
    // ===========================
    document.querySelectorAll('.flash-message').forEach(function(el) {
        setTimeout(function() {
            el.style.opacity = '0';
            el.style.transition = 'opacity 0.3s ease';
            setTimeout(function() { el.remove(); }, 300);
        }, 5000);
    });

    // ===========================
    // Upload: Drag & Drop + Click-to-Browse
    // ===========================
    const dropZone    = document.getElementById('drop-zone');
    const fileInput   = document.getElementById('file-input');
    const selectedDiv = document.getElementById('selected-file');
    const selectedName = document.getElementById('selected-file-name');
    const clearBtn    = document.getElementById('clear-file');

    if (dropZone && fileInput) {
        // Click anywhere on drop zone to open file picker (excluding the hidden input itself
        // triggering a double-open — the hidden input already handles its own click)
        dropZone.addEventListener('click', function(e) {
            if (e.target !== fileInput) {
                fileInput.click();
            }
        });

        // Drag events
        dropZone.addEventListener('dragover', function(e) {
            e.preventDefault();
            dropZone.classList.add('drag-over');
        });

        dropZone.addEventListener('dragleave', function() {
            dropZone.classList.remove('drag-over');
        });

        dropZone.addEventListener('drop', function(e) {
            e.preventDefault();
            dropZone.classList.remove('drag-over');
            const files = e.dataTransfer.files;
            if (files.length > 0) {
                // Assign dropped file to the hidden input via DataTransfer
                const dt = new DataTransfer();
                dt.items.add(files[0]);
                fileInput.files = dt.files;
                showSelectedFile(files[0].name);
            }
        });

        // File picker selection
        fileInput.addEventListener('change', function() {
            if (fileInput.files.length > 0) {
                showSelectedFile(fileInput.files[0].name);
            }
        });

        // Clear selection
        if (clearBtn) {
            clearBtn.addEventListener('click', function() {
                fileInput.value = '';
                selectedDiv.style.display = 'none';
                dropZone.style.display    = '';
            });
        }
    }

    /**
     * Show the selected filename and hide the drop zone.
     *
     * @param {string} name Filename to display
     */
    function showSelectedFile(name) {
        if (selectedName) { selectedName.textContent = name; }
        if (selectedDiv)  { selectedDiv.style.display = 'flex'; }
        if (dropZone)     { dropZone.style.display    = 'none'; }
    }

    // ===========================
    // Mermaid Diagram Rendering
    // ===========================
    if (typeof mermaid !== 'undefined') {
        mermaid.initialize({ startOnLoad: false, theme: 'default', securityLevel: 'loose' });
    }

    /**
     * Render the Mermaid diagram from the code textarea into the output div.
     * Clears previous SVG, generates a new one, and displays syntax errors.
     */
    function renderDiagram() {
        var codeEl   = document.getElementById('mermaid-code');
        var outputEl = document.getElementById('mermaid-output');
        if (!codeEl || !outputEl || typeof mermaid === 'undefined') { return; }

        var code = codeEl.value.trim();
        if (!code) {
            outputEl.innerHTML = '<p class="text-muted">No diagram code yet.</p>';
            return;
        }

        outputEl.innerHTML = '';
        var id = 'mermaid-' + Date.now();
        mermaid.render(id, code).then(function(result) {
            outputEl.innerHTML = result.svg;
            attachDiagramNodeClicks(outputEl);
        }).catch(function(err) {
            outputEl.innerHTML = '<p class="error">Invalid Mermaid syntax: ' + escapeHtml(err.message) + '</p>';
        });
    }

    // Auto-render diagram on page load and debounce on textarea input
    var codeEl = document.getElementById('mermaid-code');
    if (codeEl) {
        renderDiagram();
        var diagramTimeout;
        codeEl.addEventListener('input', function() {
            clearTimeout(diagramTimeout);
            diagramTimeout = setTimeout(renderDiagram, 500);
        });
    }

    // ===========================
    // OKR Save (AJAX)
    // ===========================
    document.querySelectorAll('.save-okr-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var item        = btn.closest('.node-okr-item');
            var nodeId      = item.getAttribute('data-node-id');
            var okrTitle    = item.querySelector('.okr-title').value;
            var okrDesc     = item.querySelector('.okr-description').value;
            var csrfToken   = document.querySelector('input[name="_csrf_token"]').value;

            var formData = new FormData();
            formData.append('_csrf_token', csrfToken);
            formData.append('node_id', nodeId);
            formData.append('okr_title', okrTitle);
            formData.append('okr_description', okrDesc);

            fetch('/app/diagram/save-okr', {
                method: 'POST',
                body: formData,
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (data.status === 'ok') {
                    btn.textContent = 'Saved!';
                    setTimeout(function() { btn.textContent = 'Save OKR'; }, 1500);
                }
            })
            .catch(function() {
                btn.textContent = 'Error';
                setTimeout(function() { btn.textContent = 'Save OKR'; }, 1500);
            });
        });
    });

    // ===========================
    // Expandable rows: prevent drag-handle clicks from toggling <details>
    // ===========================
    // Prevent drag-handle clicks from toggling <details> open/close
    document.querySelectorAll('.story-row-details .drag-handle').forEach(function(handle) {
        handle.addEventListener('click', function(e) { e.preventDefault(); });
    });

    // ===========================
    // Work Items: SortableJS Drag & Drop
    // ===========================
    var workItemsList = document.getElementById('work-items-list');
    if (workItemsList && typeof Sortable !== 'undefined') {
        Sortable.create(workItemsList, {
            handle: '.drag-handle',
            animation: 150,
            ghostClass: 'sortable-ghost',
            onEnd: function() {
                var items = workItemsList.querySelectorAll('.work-item-row');
                var order = [];
                items.forEach(function(el, index) {
                    order.push({ id: parseInt(el.dataset.id), position: index + 1 });
                    el.querySelector('.priority-number').textContent = index + 1;
                });

                var csrfToken = document.querySelector('input[name="_csrf_token"]');
                fetch('/app/work-items/reorder', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({
                        order: order,
                        _csrf_token: csrfToken ? csrfToken.value : ''
                    })
                });
            }
        });
    }

    // ===========================
    // Work Items: Edit Modal
    // ===========================
    var currentEditId = null;

    document.querySelectorAll('.edit-item-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var row = btn.closest('.work-item-row');
            currentEditId = row.dataset.id;

            document.getElementById('modal-priority').value             = row.querySelector('.priority-number').textContent;
            document.getElementById('modal-title').value                = row.dataset.title || '';
            document.getElementById('modal-description').value          = row.dataset.description || '';
            document.getElementById('modal-okr-title').value            = row.dataset.okrTitle || '';
            document.getElementById('modal-okr-desc').value             = row.dataset.okrDesc || '';
            document.getElementById('modal-owner').value                = row.dataset.owner || '';
            var teamEl = document.getElementById('modal-team-assigned');
            if (teamEl) { teamEl.value = row.dataset.teamAssigned || ''; }
            document.getElementById('modal-acceptance-criteria').value  = row.dataset.acceptanceCriteria || '';
            document.getElementById('modal-kr-hypothesis').value        = row.dataset.krHypothesis || '';
            var sprintsEl = document.getElementById('modal-estimated-sprints');
            if (sprintsEl) { sprintsEl.value = row.dataset.estimatedSprints ?? ''; }

            document.getElementById('edit-form').action = '/app/work-items/' + currentEditId;
            document.getElementById('edit-modal').classList.remove('hidden');

            // Load git links for this work item
            if (typeof loadGitLinks === 'function') {
                loadGitLinks('hl_work_item', parseInt(currentEditId, 10));
            }
        });
    });

    // ===========================
    // Work Items: AI Description Generation
    // ===========================
    var descBtn = document.getElementById('generate-desc-btn');
    if (descBtn) {
        descBtn.addEventListener('click', function() {
            if (!currentEditId) { return; }

            descBtn.disabled    = true;
            descBtn.textContent = 'Generating...';

            fetch('/app/work-items/' + currentEditId + '/generate-description', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({
                    _csrf_token: getCsrfTokenValue()
                })
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (data.status === 'ok' && data.description) {
                    document.getElementById('modal-description').value = data.description;
                    descBtn.textContent = 'Generated!';
                } else {
                    descBtn.textContent = 'Error: ' + (data.message || 'Unknown');
                }
                setTimeout(function() {
                    descBtn.textContent = 'Generate Description (AI)';
                    descBtn.disabled    = false;
                }, 2000);
            })
            .catch(function() {
                descBtn.textContent = 'Error';
                setTimeout(function() {
                    descBtn.textContent = 'Generate Description (AI)';
                    descBtn.disabled    = false;
                }, 2000);
            });
        });
    }

    // ===========================
    // Prioritisation: Score Calculation + AJAX Save
    // ===========================
    var prioTable = document.getElementById('prioritisation-table');
    if (prioTable) {
        var framework = prioTable.dataset.framework;

        prioTable.querySelectorAll('.score-dropdown').forEach(function(dropdown) {
            dropdown.addEventListener('change', function() {
                var row    = this.closest('.prio-row');
                var itemId = row.dataset.id;
                calculateAndSaveScore(row, itemId, framework);
            });
        });
    }

    // ===========================
    // Risks: AI Mitigation Generation (AJAX)
    // ===========================
    document.querySelectorAll('.generate-mitigation-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var riskId = btn.getAttribute('data-id');
            btn.disabled    = true;
            btn.textContent = 'Generating...';

            fetch('/app/risks/' + riskId + '/mitigation', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({
                    _csrf_token: getCsrfTokenValue()
                })
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (data.status === 'ok' && data.mitigation) {
                    var container = btn.parentElement;
                    container.innerHTML = '<p class="mitigation-text">' + escapeHtml(data.mitigation) + '</p>';
                } else {
                    btn.textContent = 'Error: ' + (data.message || 'Unknown');
                    setTimeout(function() {
                        btn.textContent = 'Generate Mitigation (AI)';
                        btn.disabled    = false;
                    }, 2500);
                }
            })
            .catch(function() {
                btn.textContent = 'Error';
                setTimeout(function() {
                    btn.textContent = 'Generate Mitigation (AI)';
                    btn.disabled    = false;
                }, 2500);
            });
        });
    });

    // ===========================
    // Risks: Edit Modal Population
    // ===========================
    document.querySelectorAll('.edit-risk-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var row = btn.closest('.risk-row');
            var riskId = row.dataset.id;

            document.getElementById('risk-modal-title').textContent = 'Edit Risk';
            document.getElementById('risk-title').value       = row.dataset.title || '';
            document.getElementById('risk-description').value = row.dataset.description || '';
            document.getElementById('risk-likelihood').value  = row.dataset.likelihood || '3';
            document.getElementById('risk-impact').value      = row.dataset.impact || '3';
            document.getElementById('risk-form').action       = '/app/risks/' + riskId;
            var ownerSelect = document.getElementById('risk-owner');
            if (ownerSelect) { ownerSelect.value = row.dataset.ownerUserId || ''; }
            var roamSelect = document.getElementById('risk-roam');
            if (roamSelect) { roamSelect.value = row.dataset.roamStatus || ''; }

            // Update RPN preview
            updateRpnPreview();

            // Check linked work items
            var linkedIds = [];
            try { linkedIds = JSON.parse(row.dataset.linkedIds || '[]'); } catch(e) {}
            document.querySelectorAll('.work-item-checkbox').forEach(function(cb) {
                cb.checked = linkedIds.indexOf(parseInt(cb.value)) !== -1;
            });

            document.getElementById('risk-modal').classList.remove('hidden');
        });
    });

    // ===========================
    // Risks: RPN Preview on Likelihood/Impact Change
    // ===========================
    var riskLikelihood = document.getElementById('risk-likelihood');
    var riskImpact     = document.getElementById('risk-impact');
    if (riskLikelihood && riskImpact) {
        riskLikelihood.addEventListener('change', updateRpnPreview);
        riskImpact.addEventListener('change', updateRpnPreview);
    }

    // Heatmap row highlighting is handled inline in risks.php (filterHeatmapRisks / clearHeatmapFilter)

    // ===========================
    // User Stories: SortableJS Drag & Drop
    // Supports multiple .user-stories-list containers (one per epic).
    // onEnd collects all story rows across all lists in DOM order
    // to maintain a single global priority numbering.
    // ===========================
    var storyLists = document.querySelectorAll('.user-stories-list');
    if (storyLists.length > 0 && typeof Sortable !== 'undefined') {
        storyLists.forEach(function(list) {
            Sortable.create(list, {
                handle: '.drag-handle',
                animation: 150,
                ghostClass: 'sortable-ghost',
                onEnd: function() {
                    // Collect ALL story rows across ALL epic lists in DOM order
                    var allRows = document.querySelectorAll('.user-stories-list .story-row');
                    var order = [];
                    allRows.forEach(function(el, index) {
                        order.push({ id: parseInt(el.dataset.id), position: index + 1 });
                        el.querySelector('.priority-number').textContent = index + 1;
                    });

                    var csrfToken = document.querySelector('input[name="_csrf_token"]');
                    fetch('/app/user-stories/reorder', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: JSON.stringify({
                            order: order,
                            _csrf_token: csrfToken ? csrfToken.value : ''
                        })
                    });
                }
            });
        });
    }

    // ===========================
    // User Stories: Edit Modal Population
    // ===========================
    var currentStoryId = null;

    document.querySelectorAll('.edit-story-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var row = btn.closest('.story-row');
            currentStoryId = row.dataset.id;

            document.getElementById('story-modal-title').textContent = 'Edit User Story';
            document.getElementById('story-title').value                    = row.dataset.title || '';
            document.getElementById('story-description').value              = row.dataset.description || '';
            document.getElementById('story-team').value                     = row.dataset.team || '';
            var assigneeEl = document.getElementById('story-assignee');
            if (assigneeEl) { assigneeEl.value = row.dataset.assigneeUserId || ''; }
            document.getElementById('story-size').value                     = row.dataset.size || '';
            document.getElementById('story-blocked-by').value               = row.dataset.blockedBy || '';
            document.getElementById('story-parent').value                   = row.dataset.parentId || '';
            document.getElementById('story-acceptance-criteria').value      = row.dataset.acceptanceCriteria || '';
            document.getElementById('story-kr-hypothesis').value            = row.dataset.krHypothesis || '';
            document.getElementById('story-submit-btn').textContent = 'Update';
            document.getElementById('story-form').action = '/app/user-stories/' + currentStoryId;

            // Hide the current story from the blocked-by dropdown
            var blockedBySelect = document.getElementById('story-blocked-by');
            Array.from(blockedBySelect.options).forEach(function(opt) {
                opt.style.display = opt.value === currentStoryId ? 'none' : '';
            });

            document.getElementById('ai-size-reasoning').style.display = 'none';
            document.getElementById('story-modal').classList.remove('hidden');

            // Load git links for this story
            if (typeof loadGitLinks === 'function') {
                loadGitLinks('user_story', parseInt(currentStoryId, 10));
            }
        });
    });

    // ===========================
    // User Stories: AI Size Suggestion (AJAX)
    // ===========================
    var aiSizeBtn = document.getElementById('ai-size-btn');
    if (aiSizeBtn) {
        aiSizeBtn.addEventListener('click', function() {
            if (!currentStoryId) { return; }

            aiSizeBtn.disabled    = true;
            aiSizeBtn.textContent = 'Generating...';

            fetch('/app/user-stories/' + currentStoryId + '/suggest-size', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({
                    _csrf_token: getCsrfTokenValue()
                })
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (data.status === 'ok') {
                    document.getElementById('story-size').value = String(data.size);
                    if (data.reasoning) {
                        var reasoningEl = document.getElementById('ai-size-reasoning');
                        reasoningEl.textContent = 'AI reasoning: ' + data.reasoning;
                        reasoningEl.style.display = 'block';
                    }
                    aiSizeBtn.textContent = 'Suggested!';
                } else {
                    aiSizeBtn.textContent = 'Error: ' + (data.message || 'Unknown');
                }
                setTimeout(function() {
                    aiSizeBtn.textContent = 'AI Suggest Size';
                    aiSizeBtn.disabled    = false;
                }, 2500);
            })
            .catch(function() {
                aiSizeBtn.textContent = 'Error';
                setTimeout(function() {
                    aiSizeBtn.textContent = 'AI Suggest Size';
                    aiSizeBtn.disabled    = false;
                }, 2500);
            });
        });
    }

    // ===========================
    // Upload: Extracted Text Toggle
    // ===========================
    document.querySelectorAll('.toggle-text').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const docId   = btn.getAttribute('data-doc-id');
            const preview = document.getElementById('preview-' + docId);
            const full    = document.getElementById('full-' + docId);
            if (!full) { return; }

            const isHidden = full.style.display === 'none';
            full.style.display    = isHidden ? 'block' : 'none';
            if (preview) { preview.style.display = isHidden ? 'none' : 'block'; }
            btn.textContent = isHidden ? 'Hide Full Text' : 'View Full Text';
        });
    });
});

// ===========================
// Global: Prioritisation Score Calculation
// ===========================

/**
 * Calculate the score from a prioritisation row's dropdowns, update
 * the display, and AJAX-save to the server.
 *
 * @param {HTMLElement} row       The .prio-row table row
 * @param {string}      itemId   Work item ID
 * @param {string}      framework 'rice' or 'wsjf'
 */
function calculateAndSaveScore(row, itemId, framework) {
    var dropdowns = row.querySelectorAll('.score-dropdown');
    var values    = Array.from(dropdowns).map(function(d) { return parseInt(d.value) || 0; });

    var score;
    if (framework === 'rice') {
        score = values[3] > 0 ? (values[0] * values[1] * values[2]) / values[3] : 0;
    } else {
        score = values[3] > 0 ? (values[0] + values[1] + values[2]) / values[3] : 0;
    }

    row.querySelector('.final-score').textContent = score.toFixed(1);

    var scoreFields = framework === 'rice'
        ? ['rice_reach', 'rice_impact', 'rice_confidence', 'rice_effort']
        : ['wsjf_business_value', 'wsjf_time_criticality', 'wsjf_risk_reduction', 'wsjf_job_size'];

    var scores = {};
    scoreFields.forEach(function(field, i) { scores[field] = values[i]; });

    fetch('/app/prioritisation/scores', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify({
            item_id: parseInt(itemId),
            scores: scores,
            _csrf_token: getCsrfTokenValue()
        })
    });
}

/**
 * Toggle the framework info modal visibility.
 */
function toggleFrameworkInfo() {
    var modal = document.getElementById('framework-info-modal');
    if (modal) {
        modal.classList.toggle('hidden');
    }
}

/**
 * Request AI baseline scores from the server and populate dropdowns.
 */
function requestAiBaseline() {
    var btn       = document.getElementById('ai-suggest-btn');
    var prioTable = document.getElementById('prioritisation-table');
    if (!btn || !prioTable) { return; }

    var framework  = prioTable.dataset.framework;
    // Extract project_id from the page's hidden form
    var projectInput = document.querySelector('input[name="project_id"]');
    var projectId    = projectInput ? parseInt(projectInput.value) : 0;

    btn.disabled    = true;
    btn.textContent = 'Generating...';

    fetch('/app/prioritisation/ai-baseline', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify({
            project_id: projectId,
            _csrf_token: getCsrfTokenValue()
        })
    })
    .then(function(res) { return res.json(); })
    .then(function(data) {
        if (data.status === 'ok' && data.suggestions) {
            applyAiSuggestions(data.suggestions, framework);
            btn.textContent = 'Applied!';
        } else {
            btn.textContent = 'Error: ' + (data.message || 'Unknown');
        }
        setTimeout(function() {
            btn.textContent = 'AI Suggest Scores';
            btn.disabled    = false;
        }, 2500);
    })
    .catch(function() {
        btn.textContent = 'Error';
        setTimeout(function() {
            btn.textContent = 'AI Suggest Scores';
            btn.disabled    = false;
        }, 2500);
    });
}

/**
 * Apply AI-suggested scores to the prioritisation table dropdowns.
 *
 * @param {Array}  suggestions Array of {id, ...score_fields}
 * @param {string} framework   'rice' or 'wsjf'
 */
function applyAiSuggestions(suggestions, framework) {
    var scoreFields = framework === 'rice'
        ? ['reach', 'impact', 'confidence', 'effort']
        : ['business_value', 'time_criticality', 'risk_reduction', 'job_size'];

    var dbFields = framework === 'rice'
        ? ['rice_reach', 'rice_impact', 'rice_confidence', 'rice_effort']
        : ['wsjf_business_value', 'wsjf_time_criticality', 'wsjf_risk_reduction', 'wsjf_job_size'];

    suggestions.forEach(function(suggestion) {
        var row = document.querySelector('.prio-row[data-id="' + suggestion.id + '"]');
        if (!row) { return; }

            var dropdowns = row.querySelectorAll('.score-dropdown');
        var wsjfFib = [1, 2, 3, 5, 8, 13, 20];
        scoreFields.forEach(function(field, i) {
            var val = parseInt(suggestion[field]) || 0;
            if (val < 1 || !dropdowns[i]) { return; }
            if (framework === 'wsjf') {
                // Snap to nearest Fibonacci value in case AI returned a non-Fibonacci number
                val = wsjfFib.reduce(function(prev, curr) {
                    return Math.abs(curr - val) < Math.abs(prev - val) ? curr : prev;
                });
            }
            dropdowns[i].value = val;
        });

        calculateAndSaveScore(row, String(suggestion.id), framework);
    });
}

// ===========================
// Global: Close Edit Modal
// ===========================

/**
 * Close the work item edit modal overlay.
 * Declared globally so the inline onclick handlers can call it.
 */
function closeEditModal() {
    var modal = document.getElementById('edit-modal');
    if (modal) {
        // Return any mounted KR editor back to the stash
        var mount = document.getElementById('kr-editor-mount');
        var stash = document.getElementById('kr-editor-stash');
        if (mount && stash && mount.firstElementChild) {
            stash.appendChild(mount.firstElementChild);
        }
        modal.classList.add('hidden');
    }
}

/**
 * Open the work item modal in create or edit mode.
 *
 * Pass null to open in create mode (blank fields, POST to /store).
 * Pass an element or data object to open in edit mode.
 *
 * @param {HTMLElement|null} rowEl - The .work-item-row element, or null for create mode
 */
function openWorkItemModal(rowEl) {
    var modal = document.getElementById('edit-modal');
    if (!modal) { return; }

    var form      = document.getElementById('edit-form');
    var modalTitle = modal.querySelector('.modal-header h3');
    var submitBtn  = modal.querySelector('.modal-footer .btn-primary');
    var descBtn    = document.getElementById('generate-desc-btn');

    if (rowEl === null) {
        // Create mode — clear fields and point to store endpoint
        if (modalTitle) { modalTitle.textContent = 'Add Work Item'; }
        if (submitBtn)  { submitBtn.textContent = 'Create'; }
        if (descBtn)    { descBtn.style.display = 'none'; }
        document.getElementById('modal-priority').value    = '';
        document.getElementById('modal-title').value       = '';
        document.getElementById('modal-description').value = '';
        document.getElementById('modal-okr-title').value   = '';
        document.getElementById('modal-okr-desc').value    = '';
        var ownerEl = document.getElementById('modal-owner');
        if (ownerEl) {
            // Auto-select when only one real team option exists (index 0 is "-- Unassigned --")
            ownerEl.value = ownerEl.options.length === 2 ? ownerEl.options[1].value : '';
        }
        var sprintsEl = document.getElementById('modal-estimated-sprints');
        if (sprintsEl) { sprintsEl.value = sprintsEl.tagName === 'SELECT' ? sprintsEl.options[0].value : ''; }
        form.action = '/app/work-items/store';
    } else {
        // Edit mode — populate fields from data attributes on the row
        if (modalTitle) { modalTitle.textContent = 'Edit Work Item'; }
        if (submitBtn)  { submitBtn.textContent = 'Update'; }
        if (descBtn)    { descBtn.style.display = ''; }
        var currentEditId = rowEl.dataset.id;
        document.getElementById('modal-priority').value      = rowEl.querySelector('.priority-number') ? rowEl.querySelector('.priority-number').textContent : '';
        document.getElementById('modal-title').value         = rowEl.dataset.title || '';
        document.getElementById('modal-description').value   = rowEl.dataset.description || '';
        document.getElementById('modal-okr-title').value     = rowEl.dataset.okrTitle || '';
        document.getElementById('modal-okr-desc').value      = rowEl.dataset.okrDesc || '';
        var ownerSel = document.getElementById('modal-owner');
        if (ownerSel) { ownerSel.value = rowEl.dataset.owner || ''; }
        var sprintsSel = document.getElementById('modal-estimated-sprints');
        if (sprintsSel) { sprintsSel.value = rowEl.dataset.estimatedSprints ?? ''; }
        form.action = '/app/work-items/' + currentEditId;

        // Mount the KR editor for this item into the modal
        var mount = document.getElementById('kr-editor-mount');
        var stash = document.getElementById('kr-editor-stash');
        if (mount && stash) {
            // Return any previously mounted editor to the stash
            if (mount.firstElementChild) {
                stash.appendChild(mount.firstElementChild);
            }
            // Move this item's KR editor wrapper into the mount point
            var wrapper = stash.querySelector('.kr-editor-wrapper[data-item-id="' + currentEditId + '"]');
            if (wrapper) {
                mount.appendChild(wrapper);
                // Fire mount event so the inline script can initialise event listeners
                document.dispatchEvent(new CustomEvent('kr-editor-mounted', { detail: { itemId: Number(currentEditId) } }));
            }
        }
    }

    modal.classList.remove('hidden');
}

// ===========================
// Global: Risk Modal Toggle
// ===========================

/**
 * Toggle the risk add/edit modal. Resets to "Add" mode when opening fresh.
 */
function toggleRiskModal() {
    var modal = document.getElementById('risk-modal');
    if (!modal) { return; }

    if (modal.classList.contains('hidden')) {
        // Reset to "Add" mode
        document.getElementById('risk-modal-title').textContent = 'Add Risk';
        document.getElementById('risk-form').action = '/app/risks';
        document.getElementById('risk-title').value = '';
        document.getElementById('risk-description').value = '';
        document.getElementById('risk-likelihood').value = '3';
        document.getElementById('risk-impact').value = '3';
        updateRpnPreview();
        var ownerSelect = document.getElementById('risk-owner');
        if (ownerSelect) { ownerSelect.value = ''; }
        var roamSelect = document.getElementById('risk-roam');
        if (roamSelect) { roamSelect.value = ''; }
        document.querySelectorAll('.work-item-checkbox').forEach(function(cb) {
            cb.checked = false;
        });
        modal.classList.remove('hidden');
    } else {
        modal.classList.add('hidden');
    }
}

/**
 * Update the RPN preview value from current likelihood and impact selections.
 */
function updateRpnPreview() {
    var l = parseInt(document.getElementById('risk-likelihood').value) || 3;
    var i = parseInt(document.getElementById('risk-impact').value) || 3;
    var preview = document.getElementById('rpn-preview');
    if (preview) {
        preview.textContent = l * i;
    }
}

// ===========================
// Global: Story Modal Toggle
// ===========================

/**
 * Toggle the user story add/edit modal. Resets to "Add" mode when opening fresh.
 */
function toggleStoryModal() {
    var modal = document.getElementById('story-modal');
    if (!modal) { return; }

    if (modal.classList.contains('hidden')) {
        // Reset to "Add" mode
        document.getElementById('story-modal-title').textContent = 'Add User Story';
        document.getElementById('story-form').action = '/app/user-stories/store';
        document.getElementById('story-title').value = '';
        document.getElementById('story-description').value = '';
        document.getElementById('story-team').value = '';
        var newAssigneeEl = document.getElementById('story-assignee');
        if (newAssigneeEl) { newAssigneeEl.value = ''; }
        document.getElementById('story-size').value = '';
        document.getElementById('story-blocked-by').value = '';
        document.getElementById('story-parent').value = '';
        document.getElementById('story-acceptance-criteria').value = '';
        document.getElementById('story-kr-hypothesis').value = '';
        document.getElementById('story-submit-btn').textContent = 'Save';
        document.getElementById('ai-size-reasoning').style.display = 'none';

        // Show all options in blocked-by dropdown
        var blockedBySelect = document.getElementById('story-blocked-by');
        if (blockedBySelect) {
            Array.from(blockedBySelect.options).forEach(function(opt) {
                opt.style.display = '';
            });
        }

        modal.classList.remove('hidden');
    } else {
        modal.classList.add('hidden');
    }
}

/**
 * Escape HTML entities for safe insertion into the DOM.
 *
 * @param {string} text Raw text to escape
 * @return {string}     HTML-safe text
 */
function escapeHtml(text) {
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(text));
    return div.innerHTML;
}

// ===========================
// Sprint Allocation — Drag & Drop
// ===========================

document.addEventListener('DOMContentLoaded', function() {
    var backlog = document.getElementById('backlog-stories');
    if (backlog && typeof Sortable !== 'undefined') {
        Sortable.create(backlog, {
            group: 'sprint-allocation',
            animation: 150,
            ghostClass: 'sortable-ghost',
            chosenClass: 'sortable-chosen',
            onRemove: function(evt) {
                var storyId = evt.item.dataset.storyId;
                var targetSprintId = evt.to.dataset.sprintId;
                if (targetSprintId) {
                    assignStoryToSprint(targetSprintId, storyId);
                }
            }
        });

        // Each sprint bucket
        document.querySelectorAll('.sprint-stories').forEach(function(bucket) {
            Sortable.create(bucket, {
                group: 'sprint-allocation',
                animation: 150,
                ghostClass: 'sortable-ghost',
                chosenClass: 'sortable-chosen',
                onAdd: function(evt) {
                    var storyId = evt.item.dataset.storyId;
                    var sprintId = bucket.dataset.sprintId;
                    // Only call assign if the item came from backlog or another sprint
                    // (onRemove on the source handles unassign from old sprint)
                    assignStoryToSprint(sprintId, storyId);
                    updateSprintCapacityBar(sprintId);
                },
                onRemove: function(evt) {
                    var sprintId = bucket.dataset.sprintId;
                    var storyId = evt.item.dataset.storyId;
                    var targetSprintId = evt.to.dataset.sprintId;
                    // If moving to backlog (no sprint ID), unassign
                    if (!targetSprintId) {
                        unassignStoryFromSprint(sprintId, storyId);
                    }
                    updateSprintCapacityBar(sprintId);
                }
            });
        });
    }
});

/**
 * Assign a story to a sprint via AJAX POST.
 *
 * @param {string|number} sprintId Sprint ID
 * @param {string|number} storyId  Story ID
 */
function assignStoryToSprint(sprintId, storyId) {
    fetch('/app/sprints/assign', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({
            sprint_id: parseInt(sprintId),
            story_id: parseInt(storyId),
            _csrf_token: getCsrfTokenValue()
        })
    }).then(function(res) { return res.json(); })
      .then(function(data) {
          if (data.sprint_load !== undefined) {
              updateSprintCapacityBarFromLoad(sprintId, data.sprint_load);
          }
      });
}

/**
 * Unassign a story from a sprint via AJAX POST.
 *
 * @param {string|number} sprintId Sprint ID
 * @param {string|number} storyId  Story ID
 */
function unassignStoryFromSprint(sprintId, storyId) {
    fetch('/app/sprints/unassign', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({
            sprint_id: parseInt(sprintId),
            story_id: parseInt(storyId),
            _csrf_token: getCsrfTokenValue()
        })
    }).then(function(res) { return res.json(); })
      .then(function(data) {
          if (data.sprint_load !== undefined) {
              updateSprintCapacityBarFromLoad(sprintId, data.sprint_load);
          }
      });
}

/**
 * Recalculate capacity bar from DOM items (client-side fallback).
 *
 * @param {string|number} sprintId Sprint ID
 */
function updateSprintCapacityBar(sprintId) {
    var bucket = document.getElementById('sprint-' + sprintId + '-stories');
    if (!bucket) return;

    var items = bucket.querySelectorAll('.sprint-story-item');
    var total = 0;
    items.forEach(function(item) {
        var badge = item.querySelector('.badge');
        if (badge) {
            var pts = parseInt(badge.textContent);
            if (!isNaN(pts)) total += pts;
        }
    });

    var card = bucket.closest('.sprint-card');
    var capacity = parseInt(card.dataset.capacity) || 1;
    var fill = card.querySelector('.capacity-fill');
    var label = card.querySelector('.capacity-label');

    if (fill && label) {
        fill.style.width = Math.min(100, (total / capacity) * 100) + '%';
        label.textContent = total + ' / ' + capacity + ' pts';
        fill.className = 'capacity-fill' + (total > capacity ? ' over-capacity' : '');
    }
}

/**
 * Update capacity bar from server-reported load value.
 *
 * @param {string|number} sprintId Sprint ID
 * @param {number}        load     Total points from server
 */
function updateSprintCapacityBarFromLoad(sprintId, load) {
    var card = document.querySelector('.sprint-card[data-sprint-id="' + sprintId + '"]');
    if (!card) return;

    var capacity = parseInt(card.dataset.capacity) || 1;
    var fill = card.querySelector('.capacity-fill');
    var label = card.querySelector('.capacity-label');

    if (fill && label) {
        fill.style.width = Math.min(100, (load / capacity) * 100) + '%';
        label.textContent = load + ' / ' + capacity + ' pts';
        fill.className = 'capacity-fill' + (load > capacity ? ' over-capacity' : '');
    }
}

/**
 * Toggle the inline edit form for a sprint card.
 *
 * @param {number} sprintId Sprint ID
 */
function toggleSprintEditForm(sprintId) {
    var form = document.getElementById('sprint-edit-' + sprintId);
    if (form) {
        form.classList.toggle('hidden');
    }
}

// ===========================
// Sounding Board
// ===========================

/**
 * Open the sounding board modal and reset to configuration view.
 */
function openSoundingBoard() {
    document.getElementById('sounding-board-modal').style.display = 'flex';
    document.getElementById('sb-config').style.display = 'block';
    document.getElementById('sb-loading').style.display = 'none';
    document.getElementById('sb-results').style.display = 'none';
}

/**
 * Close the sounding board modal.
 */
function closeSoundingBoard() {
    document.getElementById('sounding-board-modal').style.display = 'none';
}

/**
 * Run an AI sounding board evaluation by sending screen content
 * to the server for persona-based analysis.
 */
function runSoundingBoard() {
    var trigger = document.querySelector('.sounding-board-trigger');
    var projectId = trigger ? trigger.dataset.projectId : '';
    var screenContext = trigger ? trigger.dataset.screen : '';

    // Gather main content text from the page
    var mainContent = '';
    var contentEl = document.querySelector('.app-content');
    if (contentEl) {
        mainContent = contentEl.innerText || '';
    }

    var panelType = document.getElementById('sb-panel-type').value;
    var evalLevel = document.getElementById('sb-eval-level').value;

    document.getElementById('sb-config').style.display = 'none';
    document.getElementById('sb-loading').style.display = 'block';

    fetch('/app/sounding-board/evaluate', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({
            project_id: parseInt(projectId),
            panel_type: panelType,
            evaluation_level: evalLevel,
            screen_context: screenContext,
            screen_content: mainContent.substring(0, 5000),
            _csrf_token: getCsrfTokenValue()
        })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        document.getElementById('sb-loading').style.display = 'none';
        document.getElementById('sb-results').style.display = 'block';
        renderSoundingBoardResults(data);
    })
    .catch(function(err) {
        document.getElementById('sb-loading').style.display = 'none';
        document.getElementById('sb-config').style.display = 'block';
        alert('Evaluation failed: ' + err.message);
    });
}

/**
 * Render persona evaluation results into the modal results container.
 *
 * @param {Object} data Response from the evaluate endpoint: {id, results}
 */
function renderSoundingBoardResults(data) {
    var container = document.getElementById('sb-results');
    var html = '<h4>Evaluation Results</h4>';

    if (data.error) {
        html += '<p class="text-danger">' + escapeHtml(data.error) + '</p>';
        container.innerHTML = html;
        return;
    }

    data.results.forEach(function(result, index) {
        html += '<div class="persona-result" data-eval-id="' + data.id + '" data-index="' + index + '">'
            + '<div class="persona-header">'
            + '<strong>' + escapeHtml(result.role_title) + '</strong>'
            + '<span class="persona-status badge badge-secondary">' + escapeHtml(result.status) + '</span>'
            + '</div>'
            + '<div class="persona-response">' + escapeHtml(result.response).replace(/\n/g, '<br>') + '</div>'
            + '<div class="persona-actions">'
            + '<button class="btn btn-sm btn-primary js-persona-response" data-eval-id="' + data.id + '" data-member-index="' + index + '" data-action="accept">Accept</button>'
            + '<button class="btn btn-sm btn-secondary js-persona-response" data-eval-id="' + data.id + '" data-member-index="' + index + '" data-action="reject">Reject</button>'
            + '</div>'
            + '</div>';
    });

    container.innerHTML = html;
}

/**
 * Accept or reject a single persona's evaluation response.
 *
 * @param {number} evalId      Evaluation result ID
 * @param {number} memberIndex Index of the persona in the results array
 * @param {string} action      'accept' or 'reject'
 */
function respondToPersona(evalId, memberIndex, action) {
    fetch('/app/sounding-board/results/' + evalId + '/respond', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({
            member_index: memberIndex,
            action: action,
            _csrf_token: getCsrfTokenValue()
        })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.status === 'ok') {
            var card = document.querySelector('[data-eval-id="' + evalId + '"][data-index="' + memberIndex + '"]');
            if (card) {
                var statusBadge = card.querySelector('.persona-status');
                statusBadge.textContent = action + 'ed';
                statusBadge.className = 'persona-status badge ' + (action === 'accept' ? 'badge-success' : 'badge-muted');
                card.querySelector('.persona-actions').style.display = 'none';
            }
        }
    });
}

// Wire up sounding board trigger buttons on page load
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.sounding-board-trigger').forEach(function(btn) {
        btn.addEventListener('click', openSoundingBoard);
    });
});

var onboardingCurrentStep = 1;
var onboardingTotalSteps = 4;

function nextOnboardingStep() {
    var currentStepEl = document.getElementById('onboarding-step-' + onboardingCurrentStep);
    var dots = document.querySelectorAll('.onboarding-dot');
    if (!currentStepEl || dots.length === 0) {
        return;
    }

    currentStepEl.style.display = 'none';
    if (dots[onboardingCurrentStep - 1]) {
        dots[onboardingCurrentStep - 1].classList.remove('onboarding-dot--active');
        dots[onboardingCurrentStep - 1].style.background = 'var(--border)';
    }

    onboardingCurrentStep++;

    if (onboardingCurrentStep > onboardingTotalSteps) {
        dismissOnboarding();
        return;
    }

    var nextStepEl = document.getElementById('onboarding-step-' + onboardingCurrentStep);
    if (nextStepEl) {
        nextStepEl.style.display = 'block';
    }

    if (dots[onboardingCurrentStep - 1]) {
        dots[onboardingCurrentStep - 1].style.background = 'var(--primary)';
    }

    var nextBtn = document.getElementById('onboarding-next');
    if (nextBtn && onboardingCurrentStep === onboardingTotalSteps) {
        nextBtn.textContent = 'Get Started';
    }
}

function dismissOnboarding() {
    var modal = document.getElementById('onboarding-wizard');
    if (modal) {
        modal.remove();
    }
}

// ===========================
// Enterprise Loading States
// ===========================

/**
 * Show a full-page processing overlay with a spinner and message.
 *
 * @param {string} message Text to display below the spinner
 */
function showProcessingOverlay(message) {
    var overlay = document.createElement('div');
    overlay.className = 'processing-overlay';
    overlay.id = 'processing-overlay';
    overlay.innerHTML =
        '<div class="processing-card">' +
            '<div class="loading-spinner"></div>' +
            '<p>' + message + '</p>' +
            '<p style="color: #94a3b8; font-size: 0.8125rem; margin-top: 0.5rem;">Please don\'t close this page</p>' +
        '</div>';
    document.body.appendChild(overlay);
}

/**
 * Remove the full-page processing overlay if present.
 */
function hideProcessingOverlay() {
    var overlay = document.getElementById('processing-overlay');
    if (overlay) { overlay.remove(); }
}

/**
 * Attach loading states to all forms with data-loading and/or data-overlay attributes.
 * - data-loading: disables the submit button and shows inline spinner text.
 * - data-overlay: shows a full-page processing overlay with the given message.
 */
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('form[data-loading]').forEach(function(form) {
        form.addEventListener('submit', function() {
            var btn = form.querySelector('button[type="submit"], input[type="submit"]');
            if (btn && !btn.disabled) {
                var loadingText = form.getAttribute('data-loading') || 'Processing...';
                btn.disabled = true;
                btn.dataset.originalText = btn.textContent || btn.innerText;
                btn.innerHTML = '<span class="loading-spinner-inline"></span> ' + loadingText;
                btn.classList.add('btn-loading');
            }
        });
    });

    document.querySelectorAll('form[data-overlay]').forEach(function(form) {
        form.addEventListener('submit', function() {
            showProcessingOverlay(form.getAttribute('data-overlay'));
        });
    });

    document.querySelectorAll('.password-wrapper').forEach(function(wrapper) {
        syncPasswordToggle(wrapper);

        var input = wrapper.querySelector('input');
        if (!input) return;

        input.addEventListener('input', function() {
            syncPasswordToggle(wrapper);
        });
    });
});

function getCsrfTokenValue() {
    var csrfToken = document.querySelector('input[name="_csrf_token"]');
    return csrfToken ? csrfToken.value : '';
}

// Password visibility toggle
function togglePassword(btn) {
    var wrapper = btn.closest('.password-wrapper');
    var input = wrapper.querySelector('input');
    var eyeOn = btn.querySelector('.eye-icon');
    var eyeOff = btn.querySelector('.eye-off-icon');
    if (input.type === 'password') {
        input.type = 'text';
        eyeOn.style.display = 'none';
        eyeOff.style.display = 'block';
    } else {
        input.type = 'password';
        eyeOn.style.display = 'block';
        eyeOff.style.display = 'none';
    }
}

function syncPasswordToggle(wrapper) {
    var input = wrapper.querySelector('input');
    var button = wrapper.querySelector('.password-toggle');
    if (!input || !button) return;

    var hasValue = input.value.length > 0;
    button.style.display = hasValue ? 'flex' : 'none';
}

// ===========================
// Jira Sync Preview Modal
// ===========================
function showJiraSyncPreview(form) {
    var projectId = form.dataset.projectId;
    var syncType  = form.dataset.syncType || 'all';
    var jiraKey   = form.dataset.jiraKey || '';
    var csrfToken = form.querySelector('input[name="_csrf_token"]').value;

    // Create or reuse modal
    var modal = document.getElementById('jira-sync-preview-modal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'jira-sync-preview-modal';
        modal.className = 'modal-overlay';
        modal.style.cssText = 'position:fixed; inset:0; background:rgba(15,23,42,0.5); display:flex; align-items:center; justify-content:center; z-index:1000;';
        modal.onclick = function(e) { if (e.target === modal) modal.remove(); };
        document.body.appendChild(modal);
    }

    modal.innerHTML = '<div class="card" style="max-width:560px; width:90%; margin:0;">' +
        '<div class="card-header flex justify-between items-center">' +
            '<h2 class="card-title" style="margin:0;">Preview Jira Sync</h2>' +
            '<button type="button" onclick="document.getElementById(\'jira-sync-preview-modal\').remove();" style="background:none; border:none; font-size:1.5rem; cursor:pointer; color:var(--text-muted);">&times;</button>' +
        '</div>' +
        '<div class="card-body" id="jira-preview-body">' +
            '<div style="text-align:center; padding:1.5rem;">' +
                '<div class="loading-spinner" style="margin:0 auto 0.75rem;"></div>' +
                '<p class="text-muted" style="margin:0; font-size:0.875rem;">Checking what will be synced to ' + jiraKey + '...</p>' +
            '</div>' +
        '</div>' +
        '</div>';

    // Fetch preview
    var formData = new FormData();
    formData.append('_csrf_token', csrfToken);
    formData.append('project_id', projectId);

    fetch('/app/jira/sync/preview', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: formData
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        var body = document.getElementById('jira-preview-body');
        if (!body) return;

        if (data.error) {
            body.innerHTML = '<p style="color:var(--danger); margin:0;">Error: ' + escapeHtml(data.error) + '</p>' +
                '<div class="flex justify-end gap-2" style="margin-top:1rem;">' +
                    '<button type="button" class="btn btn-secondary" onclick="document.getElementById(\'jira-sync-preview-modal\').remove();">Close</button>' +
                '</div>';
            return;
        }

        var pushCount = (data.push || []).length;
        var pullCount = (data.pull || []).length;

        var html = '<p class="text-muted" style="font-size:0.875rem; margin:0 0 1rem;">Review what will happen when you sync to <strong>' + escapeHtml(jiraKey) + '</strong>:</p>';

        html += '<div style="display:grid; grid-template-columns:1fr 1fr; gap:1rem; margin-bottom:1rem;">';
        html += '  <div style="padding:1rem; background:#eff6ff; border-radius:8px; text-align:center;">';
        html += '    <div style="font-size:1.75rem; font-weight:700; color:var(--primary);">' + pushCount + '</div>';
        html += '    <div style="font-size:0.75rem; text-transform:uppercase; letter-spacing:0.05em; color:var(--text-muted);">To Push</div>';
        html += '  </div>';
        html += '  <div style="padding:1rem; background:#f0fdf4; border-radius:8px; text-align:center;">';
        html += '    <div style="font-size:1.75rem; font-weight:700; color:#059669;">' + pullCount + '</div>';
        html += '    <div style="font-size:0.75rem; text-transform:uppercase; letter-spacing:0.05em; color:var(--text-muted);">To Import</div>';
        html += '  </div>';
        html += '</div>';

        if (pushCount > 0) {
            html += '<details style="margin-bottom:0.75rem;"><summary style="cursor:pointer; font-size:0.85rem; font-weight:600;">Items to push (' + pushCount + ')</summary>';
            html += '<ul style="margin:0.5rem 0 0; padding-left:1.5rem; font-size:0.85rem; max-height:200px; overflow-y:auto;">';
            (data.push || []).forEach(function(item) {
                var action = item.action === 'create' ? '<span style="color:var(--primary);">NEW</span>' : '<span style="color:#059669;">UPDATE</span>';
                html += '<li>' + action + ' ' + escapeHtml(item.type) + ': ' + escapeHtml(item.title || '') + '</li>';
            });
            html += '</ul></details>';
        }

        if (pullCount > 0) {
            html += '<details style="margin-bottom:0.75rem;"><summary style="cursor:pointer; font-size:0.85rem; font-weight:600;">Items to import from Jira (' + pullCount + ')</summary>';
            html += '<ul style="margin:0.5rem 0 0; padding-left:1.5rem; font-size:0.85rem; max-height:200px; overflow-y:auto;">';
            (data.pull || []).forEach(function(item) {
                html += '<li>' + escapeHtml(item.type) + ': ' + escapeHtml(item.title || '') + ' <span class="text-muted">(' + escapeHtml(item.key || '') + ')</span></li>';
            });
            html += '</ul></details>';
        }

        if (pushCount === 0 && pullCount === 0) {
            html += '<p style="text-align:center; padding:1rem; color:var(--text-muted); margin:0;">Everything is already in sync. No changes to push or pull.</p>';
        }

        html += '<div class="flex justify-end gap-2" style="margin-top:1rem; padding-top:1rem; border-top:1px solid var(--border);">';
        html += '  <button type="button" class="btn btn-secondary" onclick="document.getElementById(\'jira-sync-preview-modal\').remove();">Cancel</button>';
        if (pushCount > 0 || pullCount > 0) {
            html += '  <button type="button" class="btn btn-primary" onclick="document.getElementById(\'jira-sync-preview-modal\').remove(); document.querySelector(\'.jira-sync-form[data-project-id=\\\''+projectId+'\\\']\').submit();">Confirm Sync</button>';
        }
        html += '</div>';

        body.innerHTML = html;
    })
    .catch(function(err) {
        var body = document.getElementById('jira-preview-body');
        if (body) {
            body.innerHTML = '<p style="color:var(--danger);">Failed to load preview: ' + escapeHtml(err.message) + '</p>' +
                '<div class="flex justify-end gap-2" style="margin-top:1rem;">' +
                    '<button type="button" class="btn btn-secondary" onclick="document.getElementById(\'jira-sync-preview-modal\').remove();">Close</button>' +
                    '<button type="button" class="btn btn-primary" onclick="document.getElementById(\'jira-sync-preview-modal\').remove(); document.querySelector(\'.jira-sync-form[data-project-id=\\\''+projectId+'\\\']\').submit();">Sync Anyway</button>' +
                '</div>';
        }
    });
}



// ===========================
// Document Summary Toggle (upload page)
// ===========================
document.querySelectorAll('.doc-summary-toggle').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var docId   = btn.getAttribute('data-doc-id');
        var panel   = document.getElementById('doc-summary-' + docId);
        if (!panel) return;
        var expanded = btn.getAttribute('aria-expanded') === 'true';
        if (expanded) {
            panel.style.display = 'none';
            btn.setAttribute('aria-expanded', 'false');
            btn.innerHTML = 'Summarised &#9660;';
        } else {
            panel.style.display = 'block';
            btn.setAttribute('aria-expanded', 'true');
            btn.innerHTML = 'Summarised &#9650;';
        }
    });
});

// ===========================
// Mermaid Node Click → OKR Scroll
// ===========================
/**
 * After the Mermaid SVG renders, find every node element and wire up a click
 * that opens and scrolls to the matching OKR accordion item.
 *
 * Mermaid renders nodes as <g> elements whose id attribute matches the node
 * key defined in the flowchart code (e.g. "flowchart-A-123" where "A" is the
 * key). We strip the "flowchart-" prefix and trailing "-NNN" index to recover
 * the key, then look for data-node-key on the accordion items.
 */
function attachDiagramNodeClicks(container) {
    if (!container) return;
    var ns = 'http://www.w3.org/2000/svg';
    var badgedKeys = {};

    // Select the canonical node <g> elements only — those with id containing "flowchart-KEY-N".
    // Mermaid v11 prefixes IDs with the diagram instance id: "mermaid-{ts}-flowchart-KEY-N".
    var nodeEls = container.querySelectorAll('[id*="flowchart-"]');
    nodeEls.forEach(function(el) {
        // Only process <g> elements that match the "flowchart-KEY-N" id pattern (with optional prefix).
        var match = (el.id || '').match(/flowchart-(.+)-\d+$/);
        if (!match || el.tagName.toLowerCase() !== 'g') return;
        var nodeKey = match[1];

        // Skip Mermaid internal grouping keys (graph wrapper, edge paths etc.)
        if (/^(graph|edge|label|cluster|flowchart|root|mermaid)$/i.test(nodeKey)) return;

        var accordion = document.querySelector('[data-node-key="' + nodeKey + '"]');

        // Make the whole <g> clickable with a pointer cursor
        el.style.cursor = 'pointer';
        el.setAttribute('pointer-events', 'all');
        el.setAttribute('role', 'button');
        el.setAttribute('title', 'Click to set OKR for ' + nodeKey);

        el.addEventListener('click', function(e) {
            e.stopPropagation();
            if (typeof openNodeOkrPanel === 'function') {
                openNodeOkrPanel(nodeKey);
            } else if (accordion) {
                // Fallback: scroll to accordion
                accordion.classList.add('accordion-item--open');
                accordion.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        });

        // Inject key label into top-left corner — dark text, no background box.
        if (badgedKeys[nodeKey]) return;
        badgedKeys[nodeKey] = true;

        var shapeEl = el.querySelector('rect, path, polygon, ellipse, circle');
        if (!shapeEl) return;

        var bbox;
        try { bbox = shapeEl.getBBox(); } catch (e) { return; }
        if (!bbox || bbox.width === 0) return;

        var txt = document.createElementNS(ns, 'text');
        txt.setAttribute('x', bbox.x + 8);
        txt.setAttribute('y', bbox.y + 14);
        txt.setAttribute('text-anchor', 'start');
        txt.setAttribute('font-size', '11');
        txt.setAttribute('font-weight', '700');
        txt.setAttribute('fill', '#1e1b4b');
        txt.setAttribute('font-family', 'system-ui, -apple-system, sans-serif');
        txt.setAttribute('pointer-events', 'none');
        txt.textContent = nodeKey;

        el.appendChild(txt);
    });
}
