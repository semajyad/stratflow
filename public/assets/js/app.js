// ===========================
// Sidebar Toggle
// ===========================
document.addEventListener('DOMContentLoaded', function() {
    const toggle  = document.getElementById('sidebar-toggle');
    const sidebar = document.getElementById('sidebar');

    if (toggle && sidebar) {
        toggle.addEventListener('click', function() {
            sidebar.classList.toggle('collapsed');
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
        }).catch(function(err) {
            outputEl.innerHTML = '<p class="error">Invalid Mermaid syntax: ' + err.message + '</p>';
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

            document.getElementById('modal-priority').value      = row.querySelector('.priority-number').textContent;
            document.getElementById('modal-title').value         = row.dataset.title || '';
            document.getElementById('modal-description').value   = row.dataset.description || '';
            document.getElementById('modal-okr-title').value     = row.dataset.okrTitle || '';
            document.getElementById('modal-okr-desc').value      = row.dataset.okrDesc || '';
            document.getElementById('modal-owner').value         = row.dataset.owner || '';

            document.getElementById('edit-form').action = '/app/work-items/' + currentEditId;
            document.getElementById('edit-modal').classList.remove('hidden');
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
                body: JSON.stringify({})
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
                body: JSON.stringify({})
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

    // ===========================
    // Risks: Heatmap Cell Click Highlighting
    // ===========================
    document.querySelectorAll('.heatmap-cell.has-risks').forEach(function(cell) {
        cell.addEventListener('click', function() {
            var l = cell.dataset.likelihood;
            var i = cell.dataset.impact;

            // Remove previous highlights
            document.querySelectorAll('.risk-row').forEach(function(row) {
                row.classList.remove('risk-highlighted');
            });

            // Highlight matching risks
            document.querySelectorAll('.risk-row').forEach(function(row) {
                if (row.dataset.likelihood === l && row.dataset.impact === i) {
                    row.classList.add('risk-highlighted');
                    row.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }
            });
        });
    });

    // ===========================
    // User Stories: SortableJS Drag & Drop
    // ===========================
    var storyList = document.getElementById('user-stories-list');
    if (storyList && typeof Sortable !== 'undefined') {
        Sortable.create(storyList, {
            handle: '.drag-handle',
            animation: 150,
            ghostClass: 'sortable-ghost',
            onEnd: function() {
                var items = storyList.querySelectorAll('.story-row');
                var order = [];
                items.forEach(function(el, index) {
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
            document.getElementById('story-title').value       = row.dataset.title || '';
            document.getElementById('story-description').value = row.dataset.description || '';
            document.getElementById('story-team').value        = row.dataset.team || '';
            document.getElementById('story-size').value        = row.dataset.size || '';
            document.getElementById('story-blocked-by').value  = row.dataset.blockedBy || '';
            document.getElementById('story-parent').value      = row.dataset.parentId || '';
            document.getElementById('story-submit-btn').textContent = 'Update';
            document.getElementById('story-form').action = '/app/user-stories/' + currentStoryId;

            // Hide the current story from the blocked-by dropdown
            var blockedBySelect = document.getElementById('story-blocked-by');
            Array.from(blockedBySelect.options).forEach(function(opt) {
                opt.style.display = opt.value === currentStoryId ? 'none' : '';
            });

            document.getElementById('ai-size-reasoning').style.display = 'none';
            document.getElementById('story-modal').classList.remove('hidden');
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
                body: JSON.stringify({})
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

    var csrfToken = document.querySelector('input[name="_csrf_token"]');
    fetch('/app/prioritisation/scores', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify({
            item_id: parseInt(itemId),
            scores: scores,
            _csrf_token: csrfToken ? csrfToken.value : ''
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
    var csrfToken  = document.querySelector('input[name="_csrf_token"]');

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
            _csrf_token: csrfToken ? csrfToken.value : ''
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
        scoreFields.forEach(function(field, i) {
            var val = parseInt(suggestion[field]) || 0;
            if (val >= 1 && val <= 10 && dropdowns[i]) {
                dropdowns[i].value = val;
            }
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
        modal.classList.add('hidden');
    }
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
        document.getElementById('story-size').value = '';
        document.getElementById('story-blocked-by').value = '';
        document.getElementById('story-parent').value = '';
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
            story_id: parseInt(storyId)
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
            story_id: parseInt(storyId)
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
