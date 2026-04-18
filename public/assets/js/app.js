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
    var openProjectModal = e.target.closest('.js-open-project-modal');
    if (openProjectModal) {
        e.preventDefault();
        openProjectModalById(
            openProjectModal.dataset.modalId || '',
            openProjectModal.dataset.prefix || '',
            openProjectModal.dataset.focusId || ''
        );
        return;
    }

    var closeProjectModal = e.target.closest('.js-close-project-modal');
    if (closeProjectModal) {
        e.preventDefault();
        closeProjectModalById(closeProjectModal.dataset.modalId || '');
        return;
    }

    var editProjectModal = e.target.closest('.js-open-edit-project-modal');
    if (editProjectModal) {
        e.preventDefault();
        openEditProjectModalFromButton(editProjectModal);
        return;
    }

    var modalOverlay = e.target.closest('.js-project-modal');
    if (modalOverlay && e.target === modalOverlay) {
        closeProjectModalById(modalOverlay.id || '');
        return;
    }

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

    if (e.target.closest('.js-open-work-item-modal')) {
        e.preventDefault();
        openWorkItemModal(null);
        return;
    }

    if (e.target.closest('.js-toggle-risk-modal')) {
        e.preventDefault();
        toggleRiskModal();
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

    if (e.target.closest('.js-close-board-review')) {
        e.preventDefault();
        closeBoardReview();
        return;
    }

    if (e.target.closest('.js-run-board-review')) {
        e.preventDefault();
        runBoardReview();
        return;
    }

    if (e.target.closest('.js-accept-board-review')) {
        e.preventDefault();
        respondToBoardReview('accept');
        return;
    }

    if (e.target.closest('.js-reject-board-review')) {
        e.preventDefault();
        respondToBoardReview('reject');
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

    if (e.target.closest('.js-toggle-framework-info')) {
        e.preventDefault();
        toggleFrameworkInfo();
        return;
    }

    if (e.target.closest('.js-request-ai-baseline')) {
        e.preventDefault();
        requestAiBaseline();
        return;
    }

    if (e.target.closest('.js-admin-test-ai')) {
        e.preventDefault();
        runAdminAiConnectionTest();
        return;
    }

    if (e.target.closest('.js-superadmin-test-ai')) {
        e.preventDefault();
        runSuperadminAiConnectionTest();
        return;
    }

    if (e.target.closest('.js-add-jira-mapping')) {
        e.preventDefault();
        addJiraCustomMappingRow();
        return;
    }

    var removeJiraMappingButton = e.target.closest('.js-remove-jira-mapping');
    if (removeJiraMappingButton) {
        e.preventDefault();
        var mappingRow = removeJiraMappingButton.closest('.custom-mapping-row');
        if (mappingRow) {
            mappingRow.remove();
        }
        return;
    }

    var revealGitSecretButton = e.target.closest('.js-reveal-git-secret');
    if (revealGitSecretButton) {
        e.preventDefault();
        toggleGitSecretReveal(revealGitSecretButton.dataset.provider || '');
        return;
    }

    var genericToggle = e.target.closest('.js-toggle-target');
    if (genericToggle) {
        e.preventDefault();
        toggleTargetVisibility(genericToggle.dataset.targetId || '');
        return;
    }

    var passwordToggle = e.target.closest('.password-toggle');
    if (passwordToggle) {
        e.preventDefault();
        togglePassword(passwordToggle);
        return;
    }

    var accordionToggle = e.target.closest('.js-accordion-toggle');
    if (accordionToggle) {
        e.preventDefault();
        toggleAccordionItem(accordionToggle);
        return;
    }

    if (e.target.closest('.js-focus-paste-text')) {
        e.preventDefault();
        focusUploadPasteText();
        return;
    }

    var docListToggle = e.target.closest('.js-toggle-doc-list');
    if (docListToggle) {
        e.preventDefault();
        toggleDocumentList(docListToggle);
        return;
    }

    if (e.target.closest('.js-generate-diagram')) {
        e.preventDefault();
        generateDiagramAjax();
        return;
    }

    if (e.target.closest('.js-open-okr-modal')) {
        e.preventDefault();
        openDiagramOkrModal();
        return;
    }

    if (e.target.closest('.js-close-okr-modal')) {
        e.preventDefault();
        closeDiagramOkrModal();
        return;
    }

    if (e.target.closest('.js-close-node-okr-panel')) {
        e.preventDefault();
        closeNodeOkrPanel();
        return;
    }

    if (e.target.closest('.js-save-node-okr')) {
        e.preventDefault();
        saveNodeOkr();
        return;
    }

    if (e.target.closest('.js-diagram-accordion-toggle')) {
        e.preventDefault();
        toggleDiagramAccordionItem(e.target.closest('.js-diagram-accordion-toggle'));
        return;
    }

    if (e.target.closest('.js-stop-propagation')) {
        e.stopPropagation();
        return;
    }

    var copyTextButton = e.target.closest('.js-copy-text');
    if (copyTextButton) {
        e.preventDefault();
        copyTextFromTarget(copyTextButton);
        return;
    }

    if (e.target.closest('.js-jira-preview-close')) {
        e.preventDefault();
        closeJiraSyncPreviewModal();
        return;
    }

    var jiraPreviewSubmit = e.target.closest('.js-jira-preview-submit');
    if (jiraPreviewSubmit) {
        e.preventDefault();
        submitJiraSyncPreview(jiraPreviewSubmit.dataset.projectId || '');
        return;
    }

    var jiraPreviewToggle = e.target.closest('.js-show-jira-preview');
    if (jiraPreviewToggle) {
        e.preventDefault();
        var jiraForm = jiraPreviewToggle.closest('form');
        if (jiraForm) {
            showJiraSyncPreview(jiraForm);
        }
        return;
    }

    if (e.target.closest('.js-git-links-add')) {
        e.preventDefault();
        addGitLink();
        return;
    }

    var heatmapFilter = e.target.closest('.js-heatmap-filter');
    if (heatmapFilter) {
        e.preventDefault();
        var likelihood = parseInt(heatmapFilter.dataset.likelihood || '', 10);
        var impact = parseInt(heatmapFilter.dataset.impact || '', 10);
        if (!Number.isNaN(likelihood) && !Number.isNaN(impact)) {
            filterHeatmapRisks(likelihood, impact);
        }
        return;
    }

    if (e.target.closest('.js-clear-heatmap-filter')) {
        e.preventDefault();
        clearHeatmapFilter();
        return;
    }

    var gitLinksDelete = e.target.closest('.js-git-links-delete');
    if (gitLinksDelete) {
        e.preventDefault();
        deleteGitLink(gitLinksDelete.dataset.linkId || '');
        return;
    }

    var removeMemberChipButton = e.target.closest('.js-remove-member-chip');
    if (removeMemberChipButton) {
        e.preventDefault();
        removeMemberChip(removeMemberChipButton);
        return;
    }

    if (!e.target.closest('.row-actions-menu')) {
        document.querySelectorAll('.row-actions-menu--open').forEach(function(m) {
            m.classList.remove('row-actions-menu--open');
            var t = m.querySelector('.row-actions-toggle');
            if (t) t.setAttribute('aria-expanded', 'false');
        });
    }

    if (!e.target.closest('.member-picker-wrap')) {
        document.querySelectorAll('.member-search-results').forEach(function(resultsEl) {
            resultsEl.style.display = 'none';
            resultsEl.innerHTML = '';
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
    var projectVisibility = e.target.closest('.js-project-visibility');
    if (projectVisibility) {
        toggleProjectMemberPicker(projectVisibility.dataset.prefix || '', projectVisibility.value || '');
        return;
    }

    var memberRoleSelect = e.target.closest('.js-member-role-select');
    if (memberRoleSelect) {
        updateMemberRole(memberRoleSelect);
        return;
    }

    var adminAiProvider = e.target.closest('.js-admin-ai-provider');
    if (adminAiProvider) {
        updateAdminAiModelPlaceholder(adminAiProvider.value || '');
        return;
    }

    var superadminAiProvider = e.target.closest('.js-superadmin-ai-provider');
    if (superadminAiProvider) {
        toggleSuperadminApiKey(superadminAiProvider.value || '');
        return;
    }

    var qualityEnabledToggle = e.target.closest('.js-quality-enabled-toggle');
    if (qualityEnabledToggle) {
        toggleVisibilityTarget(qualityEnabledToggle.dataset.targetId || '', qualityEnabledToggle.checked);
        return;
    }

    var selectAllStories = e.target.closest('.js-select-all-items');
    if (selectAllStories) {
        var splitForm = selectAllStories.closest('form');
        if (splitForm) {
            splitForm.querySelectorAll('input[name="hl_item_ids[]"]').forEach(function(cb) {
                cb.checked = selectAllStories.checked;
            });
        }
        return;
    }

    var projectSwitcher = e.target.closest('.js-project-switcher');
    if (!projectSwitcher || !projectSwitcher.value) {
        if (!e.target.closest('.js-framework-select')) {
            return;
        }
    }

    var frameworkSelect = e.target.closest('.js-framework-select');
    if (frameworkSelect) {
        var frameworkForm = frameworkSelect.closest('.js-framework-form');
        if (frameworkForm) {
            frameworkForm.submit();
        }
        return;
    }

    var baseUrl = projectSwitcher.dataset.projectBaseUrl || '/app/home';
    window.location = baseUrl + '?project_id=' + encodeURIComponent(projectSwitcher.value);
});

document.addEventListener('input', function(e) {
    var projectSearch = e.target.closest('.js-project-search');
    if (projectSearch) {
        filterProjectList(projectSearch.value || '');
        return;
    }

    var memberSearchInput = e.target.closest('.member-search-input');
    if (memberSearchInput) {
        memberPickerSearch(memberSearchInput);
    }

    var qualityThresholdRange = e.target.closest('.js-quality-threshold-range');
    if (qualityThresholdRange) {
        syncRangeOutput(qualityThresholdRange);
        return;
    }

    var invoiceSeatsInput = e.target.closest('.js-invoice-seats-input');
    if (invoiceSeatsInput) {
        updateInvoiceSeatPreview(invoiceSeatsInput);
    }
});

document.addEventListener('focusin', function(e) {
    var memberSearchInput = e.target.closest('.member-search-input');
    if (memberSearchInput) {
        memberPickerSearch(memberSearchInput);
    }
});

document.addEventListener('mousedown', function(e) {
    var memberResultItem = e.target.closest('.member-result-item');
    if (memberResultItem) {
        e.preventDefault();
        addMemberChip(memberResultItem);
    }
});

document.addEventListener('submit', function(e) {
    var jiraPushForm = e.target.closest('form[action="/app/admin/integrations/jira/push"]');
    if (jiraPushForm) {
        var projectSelect = jiraPushForm.querySelector('select[name="project_id"]');
        if (!projectSelect || !projectSelect.value) {
            e.preventDefault();
            window.alert('Select a project first');
            return;
        }
    }

    var storySplitForm = e.target.closest('.js-story-split-form');
    if (storySplitForm) {
        var checkedItems = storySplitForm.querySelectorAll('input[name="hl_item_ids[]"]:checked');
        if (checkedItems.length === 0) {
            e.preventDefault();
            window.alert('Select at least one work item.');
            return;
        }
    }

    var submitter = e.submitter;
    if (!submitter) {
        return;
    }

    var confirmMessage = submitter.dataset.confirm;
    if (confirmMessage && !window.confirm(confirmMessage)) {
        e.preventDefault();
    }
});

document.addEventListener('change', function(e) {
    var sprintTeamSelector = e.target.closest('.js-sprint-team-selector');
    if (sprintTeamSelector) {
        filterSprintsByTeam(sprintTeamSelector.value || '');
        return;
    }

    if (e.target.id === 'sprint-start-date') {
        autoSetSprintEndDate();
        return;
    }

    var executiveProjectSelector = e.target.closest('.js-executive-project-select');
    if (executiveProjectSelector) {
        navigateToExecutiveProject(executiveProjectSelector);
        return;
    }

    var githubRepoCheckbox = e.target.closest('.js-github-repo-checkbox');
    if (githubRepoCheckbox) {
        syncGithubRepoLabel(githubRepoCheckbox);
    }
});

document.addEventListener('keydown', function(e) {
    if (e.key !== 'Enter' || !e.target.matches('#git-links-ref-input')) {
        return;
    }

    e.preventDefault();
    addGitLink();
});

document.addEventListener('DOMContentLoaded', function() {
    initializeDiagramPage();
    initializeFieldSortList('sort-list-wi', 'field-order-wi');
    initializeFieldSortList('sort-list-st', 'field-order-st');

    var adminAiProvider = document.querySelector('.js-admin-ai-provider');
    if (adminAiProvider) {
        updateAdminAiModelPlaceholder(adminAiProvider.value || '');
    }

    var superadminAiProvider = document.querySelector('.js-superadmin-ai-provider');
    if (superadminAiProvider) {
        toggleSuperadminApiKey(superadminAiProvider.value || '');
    }

    document.querySelectorAll('.js-invoice-seats-input').forEach(updateInvoiceSeatPreview);
    document.querySelectorAll('.js-quality-threshold-range').forEach(syncRangeOutput);
    document.querySelectorAll('.js-quality-enabled-toggle').forEach(function(toggle) {
        toggleVisibilityTarget(toggle.dataset.targetId || '', toggle.checked);
    });
});

// ===========================
// Sidebar Toggle
// ===========================
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.js-field-order, .js-story-field-order').forEach(function(body) {
        var rawOrder = body.dataset.fieldOrder || '[]';
        var order = [];
        try {
            order = JSON.parse(rawOrder);
        } catch (e) {
            order = [];
        }
        if (!Array.isArray(order) || order.length === 0) {
            return;
        }
        order.forEach(function(key) {
            var field = body.querySelector('.modal-field-wrap[data-field="' + key + '"]');
            if (field) {
                body.appendChild(field);
            }
        });
    });

    if (typeof mermaid !== 'undefined') {
        var codeEl = document.getElementById('mermaid-thumb-code');
        var outputEl = document.getElementById('mermaid-thumb-output');
        if (codeEl && outputEl) {
            mermaid.initialize({ startOnLoad: false, theme: 'default', securityLevel: 'loose' });
            var code = codeEl.value.trim();
            if (code) {
                mermaid.render('mermaid-thumb-' + Date.now(), code).then(function(result) {
                    outputEl.innerHTML = result.svg;
                }).catch(function() {
                    outputEl.innerHTML = '<p class="text-muted mermaid-thumb-error">Diagram preview unavailable</p>';
                });
            }
        }
    }

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
                opt.hidden = opt.value === currentStoryId;
            });

            setHiddenState(document.getElementById('ai-size-reasoning'), true);
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
                        setHiddenState(reasoningEl, false);
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

function setHiddenState(element, hidden) {
    if (!element) {
        return;
    }
    element.classList.toggle('hidden', hidden);
}

function setToneState(element, tone) {
    if (!element) {
        return;
    }
    element.classList.remove('text-success', 'text-danger', 'text-muted');
    if (tone) {
        element.classList.add('text-' + tone);
    }
}

function setRiskRowHidden(row, hidden) {
    if (!row) {
        return;
    }
    row.classList.toggle('hidden', hidden);
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

function initKrEditor(itemId) {
    var container = document.querySelector('.kr-editor[data-item-id="' + itemId + '"]');
    if (!container || container.dataset.krInit) { return; }
    container.dataset.krInit = '1';

    var tbody = document.getElementById('kr-rows-' + itemId);
    var msg = container.querySelector('.kr-status-msg');
    var saveBtn = container.querySelector('.kr-save-btn');
    var addBtn = container.querySelector('.kr-add-btn');

    if (!tbody || !saveBtn || !addBtn) { return; }

    saveBtn.addEventListener('click', function() {
        var rows = tbody.querySelectorAll('.kr-row[data-kr-id]');
        var promises = [];

        rows.forEach(function(row) {
            var krId = row.dataset.krId;
            var body = new FormData();
            body.append('_csrf_token', getCsrfTokenValue());
            row.querySelectorAll('.kr-field').forEach(function(field) {
                body.append(field.dataset.field, field.value);
            });
            promises.push(fetch('/app/key-results/' + krId, { method: 'POST', body: body }));
        });

        Promise.all(promises)
            .then(function(responses) {
                var allOk = responses.every(function(response) { return response.ok; });
                msg.textContent = allOk ? 'Saved.' : 'Error saving.';
                setToneState(msg, allOk ? 'success' : 'danger');
                setTimeout(function() { msg.textContent = ''; }, 2500);
            })
            .catch(function() {
                msg.textContent = 'Network error.';
                setToneState(msg, 'danger');
            });
    });

    addBtn.addEventListener('click', function() {
        var body = new FormData();
        body.append('_csrf_token', getCsrfTokenValue());
        body.append('hl_work_item_id', String(itemId));
        body.append('title', 'New Key Result');
        body.append('status', 'not_started');

        fetch('/app/key-results', { method: 'POST', body: body })
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (!data.ok) {
                    msg.textContent = 'Error adding KR.';
                    setToneState(msg, 'danger');
                    return;
                }
                tbody.insertAdjacentHTML('beforeend', buildKrRow(data.id));
                attachKrDelete(tbody.lastElementChild.querySelector('.kr-delete-btn'));
            })
            .catch(function() {
                msg.textContent = 'Network error.';
                setToneState(msg, 'danger');
            });
    });

    tbody.querySelectorAll('.kr-delete-btn').forEach(attachKrDelete);
}

function attachKrDelete(btn) {
    if (!btn || btn.dataset.krDeleteBound === '1') { return; }
    btn.dataset.krDeleteBound = '1';

    btn.addEventListener('click', function(e) {
        var krId = e.currentTarget.dataset.krId;
        var body = new FormData();
        body.append('_csrf_token', getCsrfTokenValue());
        fetch('/app/key-results/' + krId + '/delete', { method: 'POST', body: body })
            .then(function() {
                var row = e.currentTarget.closest('.kr-row');
                if (row) {
                    row.remove();
                }
            });
    });
}

function buildKrRow(id) {
    return '<tr class="kr-row kr-row--dynamic" data-kr-id="' + id + '">' +
        '<td class="kr-row__cell"><input type="text" class="kr-field kr-field--compact" data-field="title" value="New Key Result"/></td>' +
        '<td class="kr-row__cell"><input type="number" step="any" class="kr-field kr-field--compact" data-field="baseline_value" value=""/></td>' +
        '<td class="kr-row__cell"><input type="number" step="any" class="kr-field kr-field--compact" data-field="current_value" value=""/></td>' +
        '<td class="kr-row__cell"><input type="number" step="any" class="kr-field kr-field--compact" data-field="target_value" value=""/></td>' +
        '<td class="kr-row__cell"><input type="text" class="kr-field kr-field--compact" data-field="unit" value="" placeholder="%"/></td>' +
        '<td class="kr-row__cell"><select class="kr-field kr-field--compact" data-field="status">' +
        '<option value="not_started" selected>Not Started</option>' +
        '<option value="on_track">On Track</option>' +
        '<option value="at_risk">At Risk</option>' +
        '<option value="off_track">Off Track</option>' +
        '<option value="achieved">Achieved</option>' +
        '</select></td>' +
        '<td class="kr-row__cell kr-row__cell--action"><button type="button" class="kr-delete-btn" data-kr-id="' + id + '" title="Delete">&times;</button></td>' +
        '</tr>';
}

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.kr-editor').forEach(function(el) {
        initKrEditor(Number(el.dataset.itemId));
    });
});

document.addEventListener('kr-editor-mounted', function(e) {
    initKrEditor(e.detail.itemId);
});

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

var heatmapFilterState = { likelihood: null, impact: null };

function filterHeatmapRisks(likelihood, impact) {
    if (heatmapFilterState.likelihood === likelihood && heatmapFilterState.impact === impact) {
        clearHeatmapFilter();
        return;
    }

    heatmapFilterState = { likelihood: likelihood, impact: impact };

    document.querySelectorAll('.heatmap-cell').forEach(function(cell) {
        cell.classList.remove('heatmap-cell--selected');
    });

    var selected = document.querySelector('.heatmap-cell[data-likelihood="' + likelihood + '"][data-impact="' + impact + '"]');
    if (selected) {
        selected.classList.add('heatmap-cell--selected');
    }

    var visible = 0;
    document.querySelectorAll('.risk-row').forEach(function(row) {
        var rowLikelihood = parseInt(row.dataset.likelihood || '0', 10);
        var rowImpact = parseInt(row.dataset.impact || '0', 10);
        var match = rowLikelihood === likelihood && rowImpact === impact;
        setRiskRowHidden(row, !match);
        row.classList.toggle('risk-highlighted', match);
        if (match) {
            visible += 1;
        }
    });

    var banner = document.getElementById('heatmap-filter-banner');
    if (!banner) {
        banner = document.createElement('div');
        banner.id = 'heatmap-filter-banner';
        banner.className = 'heatmap-filter-banner';
        var riskList = document.querySelector('.risk-list') || document.querySelector('.risks-list');
        if (riskList && riskList.parentNode) {
            riskList.parentNode.insertBefore(banner, riskList);
        }
    }

    banner.innerHTML = '<span>Showing <strong>' + visible + '</strong> risk' + (visible !== 1 ? 's' : '') +
        ' at Likelihood ' + likelihood + ' × Impact ' + impact + '</span>' +
        '<button type="button" class="btn btn-sm btn-secondary js-clear-heatmap-filter">Clear filter</button>';

    var firstVisible = document.querySelector('.risk-row:not(.hidden)');
    if (firstVisible) {
        firstVisible.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

function clearHeatmapFilter() {
    heatmapFilterState = { likelihood: null, impact: null };

    document.querySelectorAll('.heatmap-cell').forEach(function(cell) {
        cell.classList.remove('heatmap-cell--selected');
    });

    document.querySelectorAll('.risk-row').forEach(function(row) {
        setRiskRowHidden(row, false);
        row.classList.remove('risk-highlighted');
    });

    var banner = document.getElementById('heatmap-filter-banner');
    if (banner) {
        banner.remove();
    }

    var riskList = document.querySelector('.risk-list');
    if (riskList) {
        riskList.scrollIntoView({ behavior: 'smooth', block: 'start' });
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
        setHiddenState(document.getElementById('ai-size-reasoning'), true);

        // Show all options in blocked-by dropdown
        var blockedBySelect = document.getElementById('story-blocked-by');
        if (blockedBySelect) {
            Array.from(blockedBySelect.options).forEach(function(opt) {
                opt.hidden = false;
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

function getHomeOrgUsers() {
    var source = document.getElementById('home-org-users-data');
    if (!source) {
        return [];
    }
    try {
        return JSON.parse(source.textContent || '[]');
    } catch (e) {
        return [];
    }
}

function openProjectModalById(modalId, prefix, focusId) {
    var modal = document.getElementById(modalId);
    if (!modal) {
        return;
    }
    modal.classList.remove('hidden');
    if (prefix) {
        clearMemberPicker(prefix);
        toggleProjectMemberPicker(prefix, 'everyone');
    }
    if (focusId) {
        window.setTimeout(function() {
            var focusTarget = document.getElementById(focusId);
            if (focusTarget) {
                focusTarget.focus();
            }
        }, 50);
    }
}

function closeProjectModalById(modalId) {
    var modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.add('hidden');
    }
}

function openEditProjectModalFromButton(button) {
    var projectId = button.dataset.projectId || '';
    var projectName = button.dataset.projectName || '';
    var jiraKey = button.dataset.jiraKey || '';
    var visibility = button.dataset.visibility || 'everyone';
    var memberships = [];

    try {
        memberships = JSON.parse(button.dataset.memberships || '[]');
    } catch (e) {
        memberships = [];
    }

    var form = document.getElementById('edit-project-form');
    if (form) {
        form.action = '/app/projects/' + projectId + '/edit';
    }

    var projectNameInput = document.getElementById('edit-project-name');
    if (projectNameInput) {
        projectNameInput.value = projectName;
    }

    var jiraInput = document.getElementById('edit-jira-key');
    if (jiraInput) {
        jiraInput.value = jiraKey;
    }

    var visibilityInput = document.getElementById('edit-visibility');
    if (visibilityInput) {
        visibilityInput.value = visibility;
    }

    toggleProjectMemberPicker('edit', visibility);
    preloadMemberPicker('edit', Array.isArray(memberships) ? memberships : []);
    openProjectModalById('edit-project-modal', '', 'edit-project-name');
}

function toggleProjectMemberPicker(prefix, value) {
    var picker = document.getElementById(prefix + '-member-picker');
    if (!picker) {
        return;
    }
    picker.classList.toggle('hidden', value !== 'restricted');
    if (value !== 'restricted') {
        clearMemberPicker(prefix);
    }
}

function filterProjectList(query) {
    var normalized = (query || '').trim().toLowerCase();
    document.querySelectorAll('.project-card').forEach(function(card) {
        var nameNode = card.querySelector('.project-name');
        var name = nameNode ? nameNode.textContent.toLowerCase() : '';
        card.style.display = (normalized === '' || name.indexOf(normalized) !== -1) ? '' : 'none';
    });
}

function focusUploadPasteText() {
    var pasteInput = document.getElementById('paste-text');
    if (!pasteInput) {
        return;
    }
    pasteInput.focus();
    pasteInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

function toggleDocumentList(toggle) {
    var targetId = toggle.dataset.targetId || '';
    var docList = targetId ? document.getElementById(targetId) : null;
    if (!docList) {
        return;
    }
    docList.classList.toggle('hidden');
    var icon = toggle.querySelector('.toggle-icon');
    if (icon) {
        icon.textContent = docList.classList.contains('hidden') ? '+' : '-';
    }
}

var sprintLengthDays = 14;

function getSprintsPage() {
    return document.getElementById('sprints-page');
}

function getTomorrowIsoDate() {
    var d = new Date();
    d.setDate(d.getDate() + 1);
    return d.toISOString().slice(0, 10);
}

function addDaysToIsoDate(isoDate, days) {
    var d = new Date(isoDate);
    d.setDate(d.getDate() + days);
    return d.toISOString().slice(0, 10);
}

function applySprintDefaults(data) {
    sprintLengthDays = data.sprint_length_days || 14;

    var start = data.suggested_start || getTomorrowIsoDate();
    var nameEl = document.getElementById('sprint-name-input');
    if (nameEl && !nameEl.value && data.next_sprint_number) {
        nameEl.value = 'Sprint ' + data.next_sprint_number;
        nameEl.placeholder = 'Sprint ' + data.next_sprint_number;
    }

    var startEl = document.getElementById('sprint-start-date');
    if (startEl && !startEl.value) {
        startEl.value = start;
    }

    var endEl = document.getElementById('sprint-end-date');
    if (endEl && !endEl.value && startEl && startEl.value) {
        endEl.value = addDaysToIsoDate(startEl.value, sprintLengthDays - 1);
    }

    var capEl = document.querySelector('input[name="team_capacity"]');
    if (capEl && !capEl.value && data.suggested_capacity) {
        capEl.value = data.suggested_capacity;
        capEl.placeholder = data.suggested_capacity + ' pts';
    }

    var genStart = document.querySelector('form[action*="auto-generate"] input[name="start_date"]');
    if (genStart && !genStart.value) {
        genStart.value = start;
        if (data.suggested_start) {
            genStart.title = 'Day after last Jira sprint (' + data.suggested_start + ')';
        }
    }

    var genLength = document.querySelector('form[action*="auto-generate"] select[name="sprint_length"]');
    if (genLength) {
        genLength.value = String(sprintLengthDays);
    }

    var genCap = document.querySelector('form[action*="auto-generate"] input[name="capacity"]');
    if (genCap && !genCap.value && data.suggested_capacity) {
        genCap.value = data.suggested_capacity;
        genCap.placeholder = 'e.g. ' + data.suggested_capacity + ' (Jira avg)';
    }
}

window.loadJiraDefaults = function loadJiraDefaults(boardId) {
    var sprintPage = getSprintsPage();
    if (!sprintPage) {
        return;
    }
    var projectId = parseInt(sprintPage.dataset.projectId || '0', 10);
    if (!projectId) {
        return;
    }

    var url = '/app/sprints/jira-defaults?project_id=' + projectId + '&board_id=' + (boardId || 0);
    fetch(url, { credentials: 'same-origin' })
        .then(function(r) { return r.ok ? r.json() : Promise.reject(); })
        .then(applySprintDefaults)
        .catch(function() {
            applySprintDefaults({
                sprint_length_days: 14,
                suggested_start: null,
                next_sprint_number: null,
                suggested_capacity: null
            });
        });
};

window.autoSetSprintEndDate = function autoSetSprintEndDate() {
    var startEl = document.getElementById('sprint-start-date');
    var endEl = document.getElementById('sprint-end-date');
    if (startEl && endEl && startEl.value) {
        endEl.value = addDaysToIsoDate(startEl.value, sprintLengthDays - 1);
    }
};

window.filterSprintsByTeam = function filterSprintsByTeam(teamId) {
    var hidden = document.getElementById('sprint-team-id');
    if (hidden) {
        hidden.value = teamId;
    }

    document.querySelectorAll('.sprint-card').forEach(function(card) {
        var cardTeamId = card.dataset.teamId || '';
        if (!teamId || cardTeamId === teamId || cardTeamId === '' || cardTeamId === '0') {
            card.style.display = '';
        } else {
            card.style.display = 'none';
        }
    });

    var sel = document.getElementById('active-team-selector');
    var opt = sel ? sel.querySelector('option[value="' + teamId + '"]') : null;
    var boardId = opt ? (parseInt(opt.dataset.jiraBoardId || '0', 10) || 0) : 0;
    window.loadJiraDefaults(boardId);
};

function initializeSprintsPage() {
    var sprintPage = getSprintsPage();
    if (!sprintPage || sprintPage.dataset.initialized === '1') {
        return;
    }
    sprintPage.dataset.initialized = '1';

    var selector = document.getElementById('active-team-selector');
    if (selector) {
        var opt = selector.options[selector.selectedIndex];
        var boardId = opt ? (parseInt(opt.dataset.jiraBoardId || '0', 10) || 0) : 0;
        window.loadJiraDefaults(boardId);
    } else {
        window.loadJiraDefaults(0);
    }
}

function toggleTargetVisibility(targetId) {
    var target = targetId ? document.getElementById(targetId) : null;
    if (target) {
        var shouldShow = target.classList.contains('hidden');
        target.classList.toggle('hidden');
        if (target.tagName && target.tagName.toLowerCase() === 'tr') {
            target.style.display = shouldShow ? 'table-row' : 'none';
        }
    }
}

function toggleAccordionItem(button) {
    var item = button.closest('.accordion-item');
    if (item) {
        item.classList.toggle('accordion-item--open');
    }
}

function toggleVisibilityTarget(targetId, shouldShow) {
    var target = targetId ? document.getElementById(targetId) : null;
    if (target) {
        target.style.display = shouldShow ? '' : 'none';
    }
}

function updateInvoiceSeatPreview(input) {
    var preview = document.getElementById('invoice-preview-text');
    if (!preview || !input) {
        return;
    }
    var seatCount = parseInt(input.value || '1', 10);
    var normalizedSeatCount = Number.isNaN(seatCount) || seatCount < 1 ? 1 : seatCount;
    var pricePerSeat = parseInt(input.dataset.pricePerSeat || '0', 10);
    var periodLabel = input.dataset.periodLabel || 'monthly';
    var totalCost = (pricePerSeat * normalizedSeatCount / 100).toFixed(2);
    preview.textContent = '+$' + totalCost + '/' + periodLabel + ' (' + normalizedSeatCount + ' seat' + (normalizedSeatCount !== 1 ? 's' : '') + ')';
}

function syncRangeOutput(input) {
    var outputId = input.dataset.outputId || '';
    var output = outputId ? document.getElementById(outputId) : null;
    if (output) {
        output.textContent = String(input.value || '') + '%';
    }
}

function initializeFieldSortList(listId, inputId) {
    var list = document.getElementById(listId);
    var input = document.getElementById(inputId);
    if (!list || !input || list.dataset.sortReady === '1') {
        return;
    }

    var dragging = null;
    list.dataset.sortReady = '1';

    list.addEventListener('dragstart', function(e) {
        dragging = e.target.closest('.field-sort-item');
        if (!dragging) {
            return;
        }
        dragging.classList.add('dragging');
        if (e.dataTransfer) {
            e.dataTransfer.effectAllowed = 'move';
        }
    });

    list.addEventListener('dragend', function() {
        if (dragging) {
            dragging.classList.remove('dragging');
        }
        list.querySelectorAll('.field-sort-item').forEach(function(el) {
            el.classList.remove('drag-over');
        });
        dragging = null;
        syncFieldSortOrder(list, input);
    });

    list.addEventListener('dragover', function(e) {
        e.preventDefault();
        var target = e.target.closest('.field-sort-item');
        if (!dragging || !target || target === dragging) {
            return;
        }
        var rect = target.getBoundingClientRect();
        var after = e.clientY > rect.top + (rect.height / 2);
        list.querySelectorAll('.field-sort-item').forEach(function(el) {
            el.classList.remove('drag-over');
        });
        target.classList.add('drag-over');
        list.insertBefore(dragging, after ? target.nextSibling : target);
    });
}

function syncFieldSortOrder(list, input) {
    var keys = [];
    list.querySelectorAll('.field-sort-item').forEach(function(el, index) {
        keys.push(el.dataset.key || '');
        var numberEl = el.querySelector('.field-sort-num');
        if (numberEl) {
            numberEl.textContent = String(index + 1);
        }
    });
    input.value = keys.join(',');
}

function updateAdminAiModelPlaceholder(provider) {
    var placeholders = {
        '': 'e.g. gemini-3-flash-preview',
        google: 'e.g. gemini-3-flash-preview',
        openai: 'e.g. gpt-4o',
        anthropic: 'e.g. claude-sonnet-4-6'
    };
    var hints = {
        '': 'Leave blank to use the platform default.',
        google: 'e.g. gemini-3-flash-preview gemini-3-flash-preview',
        openai: 'e.g. gpt-4o, gpt-4o-mini',
        anthropic: 'e.g. claude-opus-4-6, claude-sonnet-4-6'
    };
    var input = document.getElementById('ai-model-input');
    var hint = document.getElementById('ai-model-hint');
    if (input) {
        input.placeholder = placeholders[provider] || 'Enter model name';
    }
    if (hint) {
        hint.textContent = hints[provider] || '';
    }
}

function runAdminAiConnectionTest() {
    var button = document.querySelector('.js-admin-test-ai');
    var result = document.getElementById('ai-test-result');
    var provider = document.getElementById('ai-provider-select');
    var model = document.getElementById('ai-model-input');
    var apiKey = document.getElementById('ai-api-key-input');
    var csrf = document.querySelector('input[name="_csrf_token"]');
    if (!button || !result || !provider || !model || !apiKey) {
        return;
    }

    button.disabled = true;
    button.textContent = 'Testing...';
    setToneState(result, null);
    result.textContent = '';

    var form = new FormData();
    form.append('_csrf_token', csrf ? csrf.value : '');
    form.append('ai_provider', provider.value || '');
    form.append('ai_model', model.value || '');
    form.append('ai_api_key', apiKey.value || '');

    fetch('/app/admin/test-ai', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: form
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        button.disabled = false;
        button.textContent = 'Test Connection';
        if (data.status === 'ok') {
            setToneState(result, 'success');
            result.textContent = 'Connection successful';
            return;
        }
        setToneState(result, 'danger');
        result.textContent = data.message || 'Connection failed';
    })
    .catch(function() {
        button.disabled = false;
        button.textContent = 'Test Connection';
        setToneState(result, 'danger');
        result.textContent = 'Request failed';
    });
}

function toggleSuperadminApiKey(provider) {
    ['google', 'openai', 'anthropic'].forEach(function(slug) {
        var el = document.getElementById('api-key-' + slug);
        if (el) {
            setHiddenState(el, slug !== provider);
        }
    });
}

function runSuperadminAiConnectionTest() {
    var button = document.querySelector('.js-superadmin-test-ai');
    var result = document.getElementById('test-ai-result');
    var provider = document.getElementById('ai-provider-select');
    var model = document.querySelector('input[name="ai_model"]');
    var csrf = document.querySelector('input[name="_csrf_token"]');
    if (!button || !result || !provider || !model) {
        return;
    }

    var modelValue = (model.value || '').trim();
    if (!modelValue) {
        result.textContent = 'Enter a model identifier first.';
        setToneState(result, 'danger');
        return;
    }

    button.disabled = true;
    button.textContent = 'Testing...';
    result.textContent = 'Connecting...';
    setToneState(result, 'muted');

    var form = new FormData();
    form.append('_csrf_token', csrf ? csrf.value : '');
    form.append('provider', provider.value || '');
    form.append('model', modelValue);

    fetch('/superadmin/defaults/test-ai', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: form,
        credentials: 'same-origin'
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        button.disabled = false;
        button.textContent = 'Test Connection';
        if (data.success) {
            setToneState(result, 'success');
            result.textContent = 'Connected' + (data.latency_ms ? ' (' + data.latency_ms + 'ms)' : '');
            return;
        }
        setToneState(result, 'danger');
        result.textContent = 'Failed: ' + (data.error || 'Unknown error');
    })
    .catch(function(error) {
        button.disabled = false;
        button.textContent = 'Test Connection';
        setToneState(result, 'danger');
        result.textContent = 'Network error: ' + error.message;
    });
}

function toggleGitSecretReveal(provider) {
    var display = document.getElementById(provider + '-secret-display');
    var button = document.getElementById(provider + '-secret-reveal-btn');
    if (!provider || !display || !button) {
        return;
    }

    if (button.textContent === 'Hide') {
        display.textContent = display.getAttribute('data-masked') || '';
        button.textContent = 'Reveal';
        return;
    }

    button.disabled = true;
    button.textContent = 'Loading...';

    var form = new FormData();
    form.append('_csrf_token', getCsrfTokenValue());

    fetch('/app/admin/integrations/git/' + encodeURIComponent(provider) + '/reveal-secret', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: form,
        credentials: 'same-origin'
    })
    .then(function(response) {
        return response.json().then(function(data) {
            return { ok: response.ok, data: data };
        });
    })
    .then(function(result) {
        button.disabled = false;
        if (result.ok && result.data && result.data.secret) {
            display.textContent = result.data.secret;
            button.textContent = 'Hide';
            return;
        }
        button.textContent = 'Reveal';
        window.alert((result.data && result.data.error) || 'Could not reveal secret.');
    })
    .catch(function() {
        button.disabled = false;
        button.textContent = 'Reveal';
        window.alert('Network error revealing secret.');
    });
}

function addJiraCustomMappingRow() {
    var table = document.getElementById('custom-mappings-table');
    var tbody = document.getElementById('custom-mappings-body');
    if (!table || !tbody) {
        return;
    }

    var index = tbody.querySelectorAll('.custom-mapping-row').length;
    var stratflowFields = parseJsonDataset(table.dataset.stratflowFields, {});
    var jiraFields = parseJsonDataset(table.dataset.jiraFields, []);
    var row = document.createElement('tr');
    row.className = 'custom-mapping-row';
    row.innerHTML =
        '<td class="custom-mapping-row__cell">' + buildJiraStratflowFieldSelect(index, stratflowFields) + '</td>' +
        '<td class="custom-mapping-row__cell">' + buildJiraFieldInput(index, jiraFields) + '</td>' +
        '<td class="custom-mapping-row__cell">' + buildJiraDirectionSelect(index) + '</td>' +
        '<td class="custom-mapping-row__cell custom-mapping-row__cell--action">' +
            '<button type="button" class="btn-remove-mapping js-remove-jira-mapping custom-mapping-remove" title="Remove mapping">&times;</button>' +
        '</td>';
    tbody.appendChild(row);
}

function buildJiraStratflowFieldSelect(index, stratflowFields) {
    var html = '<select name="custom_mappings[' + index + '][stratflow_field]" class="form-input custom-mapping-input">';
    html += '<option value="">Select...</option>';
    Object.keys(stratflowFields || {}).forEach(function(key) {
        html += '<option value="' + escapeHtml(key) + '">' + escapeHtml(stratflowFields[key]) + '</option>';
    });
    html += '</select>';
    return html;
}

function buildJiraFieldInput(index, jiraFields) {
    if (Array.isArray(jiraFields) && jiraFields.length > 0) {
        var html = '<select name="custom_mappings[' + index + '][jira_field]" class="form-input custom-mapping-input">';
        html += '<option value="">Select...</option>';
        jiraFields.forEach(function(field) {
            html += '<option value="' + escapeHtml(field.id || '') + '">' +
                escapeHtml((field.name || '') + ' (' + (field.id || '') + ')') +
                '</option>';
        });
        html += '</select>';
        return html;
    }

    return '<input type="text" name="custom_mappings[' + index + '][jira_field]" class="form-input custom-mapping-input" placeholder="customfield_XXXXX">';
}

function buildJiraDirectionSelect(index) {
    return '<select name="custom_mappings[' + index + '][direction]" class="form-input custom-mapping-input">' +
        '<option value="push">Push</option>' +
        '<option value="pull">Pull</option>' +
        '<option value="both">Both</option>' +
        '</select>';
}

function parseJsonDataset(value, fallback) {
    if (!value) {
        return fallback;
    }
    try {
        return JSON.parse(value);
    } catch (e) {
        return fallback;
    }
}

function getSelectedMemberIds(pickerWrap) {
    return Array.from(pickerWrap.querySelectorAll('.member-chip[data-member-id]')).map(function(el) {
        return parseInt(el.dataset.memberId || '0', 10);
    });
}

function memberPickerSearch(input) {
    var pickerWrap = input.closest('.member-picker-wrap');
    if (!pickerWrap) {
        return;
    }

    var query = input.value.trim().toLowerCase();
    var resultsEl = pickerWrap.querySelector('.member-search-results');
    var selectedIds = getSelectedMemberIds(pickerWrap);
    var matches = getHomeOrgUsers().filter(function(user) {
        return selectedIds.indexOf(user.id) === -1 &&
            (query === '' || user.label.toLowerCase().indexOf(query) !== -1);
    }).slice(0, 20);

    if (!resultsEl) {
        return;
    }

    if (matches.length === 0) {
        setHiddenState(resultsEl, true);
        resultsEl.innerHTML = '';
        return;
    }

    resultsEl.textContent = '';
    matches.forEach(function(user) {
        var div = document.createElement('div');
        div.className = 'member-result-item';
        div.dataset.id = String(user.id);
        div.dataset.label = user.label;
        div.textContent = user.label;
        resultsEl.appendChild(div);
    });
    setHiddenState(resultsEl, false);
}

function buildMemberChip(label, memberId, membershipRole) {
    var safeLabel = escapeHtml(label);
    var role = membershipRole || 'editor';
    return '<span class="member-chip" data-member-id="' + String(memberId) + '"' +
        '>' +
        '<span>' + safeLabel + '</span>' +
        '<select class="js-member-role-select member-chip__role">' +
        '<option value="viewer"' + (role === 'viewer' ? ' selected' : '') + '>Viewer</option>' +
        '<option value="editor"' + (role === 'editor' ? ' selected' : '') + '>Editor</option>' +
        '<option value="project_admin"' + (role === 'project_admin' ? ' selected' : '') + '>Project Admin</option>' +
        '</select>' +
        '<button type="button" class="js-remove-member-chip member-chip__remove" title="Remove">&times;</button>' +
        '</span>';
}

function addMemberChip(resultItem) {
    var pickerWrap = resultItem.closest('.member-picker-wrap');
    if (!pickerWrap) {
        return;
    }

    var memberId = parseInt(resultItem.dataset.id || '0', 10);
    var label = resultItem.dataset.label || '';
    var membershipRole = resultItem.dataset.role || 'editor';
    var chipsEl = pickerWrap.querySelector('.member-chips');
    if (!chipsEl || !memberId) {
        return;
    }

    chipsEl.insertAdjacentHTML('beforeend', buildMemberChip(label, memberId, membershipRole));

    var hidden = document.createElement('input');
    hidden.type = 'hidden';
    hidden.name = 'member_roles[' + memberId + ']';
    hidden.value = membershipRole;
    hidden.dataset.memberId = String(memberId);
    pickerWrap.appendChild(hidden);

    var input = pickerWrap.querySelector('.member-search-input');
    if (input) {
        input.value = '';
    }
    var resultsEl = pickerWrap.querySelector('.member-search-results');
    if (resultsEl) {
        setHiddenState(resultsEl, true);
        resultsEl.innerHTML = '';
    }
}

function removeMemberChip(button) {
    var chip = button.closest('.member-chip');
    if (!chip) {
        return;
    }
    var pickerWrap = chip.closest('.member-picker-wrap');
    var memberId = chip.dataset.memberId || '';
    if (pickerWrap) {
        var hidden = pickerWrap.querySelector('input[type="hidden"][data-member-id="' + memberId + '"]');
        if (hidden) {
            hidden.remove();
        }
    }
    chip.remove();
}

function updateMemberRole(select) {
    var chip = select.closest('.member-chip');
    var pickerWrap = chip ? chip.closest('.member-picker-wrap') : null;
    if (!chip || !pickerWrap) {
        return;
    }
    var memberId = chip.dataset.memberId || '';
    var hidden = pickerWrap.querySelector('input[type="hidden"][data-member-id="' + memberId + '"]');
    if (hidden) {
        hidden.value = select.value;
    }
}

function clearMemberPicker(prefix) {
    var picker = document.getElementById(prefix + '-member-picker');
    if (!picker) {
        return;
    }
    var wrap = picker.querySelector('.member-picker-wrap');
    if (!wrap) {
        return;
    }
    wrap.querySelectorAll('.member-chip').forEach(function(chip) {
        chip.remove();
    });
    wrap.querySelectorAll('input[type="hidden"][data-member-id]').forEach(function(input) {
        input.remove();
    });
    var searchInput = wrap.querySelector('.member-search-input');
    if (searchInput) {
        searchInput.value = '';
    }
    var resultsEl = wrap.querySelector('.member-search-results');
    if (resultsEl) {
        setHiddenState(resultsEl, true);
        resultsEl.innerHTML = '';
    }
}

function preloadMemberPicker(prefix, memberships) {
    clearMemberPicker(prefix);
    if (!Array.isArray(memberships) || memberships.length === 0) {
        return;
    }
    var picker = document.getElementById(prefix + '-member-picker');
    if (!picker) {
        return;
    }
    var wrap = picker.querySelector('.member-picker-wrap');
    var chipsEl = wrap ? wrap.querySelector('.member-chips') : null;
    if (!wrap || !chipsEl) {
        return;
    }
    memberships.forEach(function(entry) {
        var memberId = parseInt(entry.user_id || entry.id || '0', 10);
        var membershipRole = entry.membership_role || 'editor';
        var user = getHomeOrgUsers().find(function(candidate) {
            return candidate.id === memberId;
        });
        if (!user || !memberId) {
            return;
        }
        chipsEl.insertAdjacentHTML('beforeend', buildMemberChip(user.label, memberId, membershipRole));
        var hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'member_roles[' + memberId + ']';
        hidden.value = membershipRole;
        hidden.dataset.memberId = String(memberId);
        wrap.appendChild(hidden);
    });
}

// ===========================
// Sprint Allocation — Drag & Drop
// ===========================

document.addEventListener('DOMContentLoaded', function() {
    initializeSprintsPage();

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
    setHiddenState(document.getElementById('sounding-board-modal'), false);
    setHiddenState(document.getElementById('sb-config'), false);
    setHiddenState(document.getElementById('sb-loading'), true);
    setHiddenState(document.getElementById('sb-results'), true);
}

/**
 * Close the sounding board modal.
 */
function closeSoundingBoard() {
    setHiddenState(document.getElementById('sounding-board-modal'), true);
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

    setHiddenState(document.getElementById('sb-config'), true);
    setHiddenState(document.getElementById('sb-loading'), false);

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
        setHiddenState(document.getElementById('sb-loading'), true);
        setHiddenState(document.getElementById('sb-results'), false);
        renderSoundingBoardResults(data);
    })
    .catch(function(err) {
        setHiddenState(document.getElementById('sb-loading'), true);
        setHiddenState(document.getElementById('sb-config'), false);
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
                setHiddenState(card.querySelector('.persona-actions'), true);
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

// ===========================
// Board Review
// ===========================

var _brCurrentReviewId = null;
var _brCurrentTrigger  = null;

function openBoardReview(trigger) {
    _brCurrentTrigger  = trigger;
    _brCurrentReviewId = null;
    setHiddenState(document.getElementById('board-review-modal'), false);
    setHiddenState(document.getElementById('br-config'),    false);
    setHiddenState(document.getElementById('br-loading'),   true);
    setHiddenState(document.getElementById('br-results'),   true);
    setHiddenState(document.getElementById('br-responded'), true);
}

function closeBoardReview() {
    setHiddenState(document.getElementById('board-review-modal'), true);
}

/**
 * Collect page content for the given screen context using corrected selectors.
 *
 * @param {string} screenContext  summary | roadmap | work_items | user_stories
 * @returns {string}
 */
function collectBoardReviewContent(screenContext) {
    if (screenContext === 'summary') {
        var el = document.querySelector('.upload-summary-text');
        return el ? (el.textContent || '').trim() : '';
    }
    if (screenContext === 'roadmap') {
        var mc = document.getElementById('mermaid-code');
        return mc ? (mc.value || '').trim() : '';
    }
    if (screenContext === 'work_items') {
        var rows = document.querySelectorAll('.work-item-row');
        var items = [];
        rows.forEach(function(row) {
            items.push({
                id:          row.dataset.id          || '',
                title:       row.dataset.title       || '',
                description: row.dataset.description || ''
            });
        });
        return JSON.stringify(items);
    }
    if (screenContext === 'user_stories') {
        var srows = document.querySelectorAll('.story-row');
        var stories = [];
        srows.forEach(function(row) {
            stories.push({
                id:          row.dataset.id          || '',
                title:       row.dataset.title       || '',
                description: row.dataset.description || ''
            });
        });
        return JSON.stringify(stories);
    }
    return '';
}

function runBoardReview() {
    var trigger      = _brCurrentTrigger;
    var projectId    = trigger ? trigger.dataset.projectId : '';
    var screenContext = trigger ? trigger.dataset.screen   : '';
    var reviewLevel  = document.getElementById('br-review-level').value;
    var screenContent = collectBoardReviewContent(screenContext);

    if (!screenContent) {
        alert('No content found to review on this page.');
        return;
    }

    setHiddenState(document.getElementById('br-config'),  true);
    setHiddenState(document.getElementById('br-loading'), false);

    fetch('/app/board-review/evaluate', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({
            project_id:       parseInt(projectId),
            evaluation_level: reviewLevel,
            screen_context:   screenContext,
            screen_content:   screenContent,
            _csrf_token:      getCsrfTokenValue()
        })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        setHiddenState(document.getElementById('br-loading'), true);
        if (data.error) {
            setHiddenState(document.getElementById('br-config'), false);
            alert('Board review failed: ' + data.error);
            return;
        }
        _brCurrentReviewId = data.id;
        setHiddenState(document.getElementById('br-results'), false);
        renderBoardReviewResults(data);
    })
    .catch(function(err) {
        setHiddenState(document.getElementById('br-loading'), true);
        setHiddenState(document.getElementById('br-config'),  false);
        alert('Board review failed: ' + err.message);
    });
}

function renderBoardReviewResults(data) {
    var convEl = document.getElementById('br-conversation');
    var recEl  = document.getElementById('br-recommendation');
    var convHtml = '<h4>Board Deliberation</h4><div class="br-conversation-list">';
    (data.conversation || []).forEach(function(turn) {
        convHtml += '<div class="br-turn">'
            + '<strong class="br-speaker">' + escapeHtml(turn.speaker || '') + '</strong>'
            + '<p class="br-message">' + escapeHtml(turn.message || '').replace(/\n/g, '<br>') + '</p>'
            + '</div>';
    });
    convHtml += '</div>';
    convEl.innerHTML = convHtml;

    var rec = data.recommendation || {};
    recEl.innerHTML = '<h4>Board Recommendation</h4>'
        + '<p><strong>Summary:</strong> ' + escapeHtml(rec.summary   || '') + '</p>'
        + '<p><strong>Rationale:</strong> ' + escapeHtml(rec.rationale || '') + '</p>';
}

function respondToBoardReview(action) {
    if (!_brCurrentReviewId) { return; }
    var reviewId = _brCurrentReviewId;

    setHiddenState(document.getElementById('br-actions'), true);

    fetch('/app/board-review/' + reviewId + '/' + action, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({ _csrf_token: getCsrfTokenValue() })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        setHiddenState(document.getElementById('br-results'),   true);
        setHiddenState(document.getElementById('br-responded'), false);
        var msg = action === 'accept'
            ? 'Changes accepted and applied.'
            : 'Review rejected. No changes were made.';
        document.getElementById('br-responded').innerHTML =
            '<p class="br-response-msg">' + escapeHtml(msg) + '</p>'
            + '<button class="btn btn-secondary js-close-board-review" type="button">Close</button>';
        if (action === 'accept') {
            setTimeout(function() { window.location.reload(); }, 1200);
        }
    })
    .catch(function(err) {
        setHiddenState(document.getElementById('br-actions'), false);
        alert('Failed: ' + err.message);
    });
}

// Wire up board review trigger buttons on page load
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.board-review-trigger').forEach(function(btn) {
        btn.addEventListener('click', function() { openBoardReview(btn); });
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

    setHiddenState(currentStepEl, true);
    if (dots[onboardingCurrentStep - 1]) {
        dots[onboardingCurrentStep - 1].classList.remove('onboarding-dot--active');
    }

    onboardingCurrentStep++;

    if (onboardingCurrentStep > onboardingTotalSteps) {
        dismissOnboarding();
        return;
    }

    var nextStepEl = document.getElementById('onboarding-step-' + onboardingCurrentStep);
    if (nextStepEl) {
        setHiddenState(nextStepEl, false);
    }

    if (dots[onboardingCurrentStep - 1]) {
        dots[onboardingCurrentStep - 1].classList.add('onboarding-dot--active');
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
    var card = document.createElement('div');
    card.className = 'processing-card';
    var spinner = document.createElement('div');
    spinner.className = 'loading-spinner';
    var msg = document.createElement('p');
    msg.textContent = message;
    var note = document.createElement('p');
    note.className = 'processing-card__note';
    note.textContent = "Please don't close this page";
    card.appendChild(spinner);
    card.appendChild(msg);
    card.appendChild(note);
    overlay.appendChild(card);
    document.body.appendChild(overlay);
}

/**
 * Remove the full-page processing overlay if present.
 */
function hideProcessingOverlay() {
    var overlay = document.getElementById('processing-overlay');
    if (overlay) { overlay.remove(); }
}

function getDiagramPage() {
    return document.getElementById('diagram-page');
}

function getDiagramNodeData() {
    var source = document.getElementById('diagram-node-data');
    if (!source) {
        return [];
    }
    try {
        return JSON.parse(source.value || '[]');
    } catch (e) {
        return [];
    }
}

function updateDiagramNodeData(nodes) {
    var source = document.getElementById('diagram-node-data');
    if (source) {
        source.value = JSON.stringify(nodes);
    }
}

function openDiagramOkrModal() {
    var modal = document.getElementById('add-okr-modal');
    if (modal) {
        modal.classList.remove('hidden');
    }
}

function closeDiagramOkrModal() {
    var modal = document.getElementById('add-okr-modal');
    if (modal) {
        modal.classList.add('hidden');
    }
}

function copyTextFromTarget(button) {
    var targetId = button.dataset.copyTargetId || '';
    var target = targetId ? document.getElementById(targetId) : null;
    if (!target) {
        return;
    }

    var text = '';
    if (target.value !== undefined) {
        text = target.value;
    } else {
        text = target.textContent || '';
    }

    var defaultLabel = button.dataset.copyDefaultLabel || 'Copy';
    navigator.clipboard.writeText(text).then(function() {
        button.textContent = 'Copied!';
        window.setTimeout(function() {
            button.textContent = defaultLabel;
        }, 2000);
    }).catch(function() {
        if (target.select) {
            target.select();
        }
        document.execCommand('copy');
        button.textContent = 'Copied!';
        window.setTimeout(function() {
            button.textContent = defaultLabel;
        }, 2000);
    });
}

function navigateToExecutiveProject(selectEl) {
    var baseUrl = selectEl.dataset.baseUrl || '/app/projects/';
    var value = selectEl.value || '';
    if (!value) {
        return;
    }
    var path = baseUrl + value + '/executive';
    if (/^\//.test(path)) { window.location = path; }
}

function syncGithubRepoLabel(checkbox) {
    var label = checkbox.closest('.js-github-repo-label');
    if (!label) {
        return;
    }
    label.classList.toggle('github-repo-label--selected', checkbox.checked);
}

function toggleDiagramAccordionItem(button) {
    var item = button ? button.closest('.accordion-item') : null;
    if (item) {
        item.classList.toggle('accordion-item--open');
    }
}

function openNodeOkrPanel(nodeKey) {
    var panel = document.getElementById('node-okr-panel');
    var nodes = getDiagramNodeData();
    var node = nodes.find(function(n) { return n.node_key === nodeKey; });
    if (!node || !panel) {
        return;
    }
    document.getElementById('node-okr-node-id').value = node.id;
    document.getElementById('node-okr-title').textContent = node.label;
    document.getElementById('node-okr-objective').value = node.okr_title || '';
    document.getElementById('node-okr-keyresults').value = node.okr_description || '';
    document.getElementById('node-okr-save-status').textContent = '';
    document.getElementById('node-okr-save-btn').disabled = false;
    document.getElementById('node-okr-save-btn').textContent = 'Save OKRs to Node';
    panel.classList.add('diagram-node-panel--open');
}

function closeNodeOkrPanel() {
    var panel = document.getElementById('node-okr-panel');
    if (panel) {
        panel.classList.remove('diagram-node-panel--open');
    }
}

function saveNodeOkr() {
    var diagramPage = getDiagramPage();
    if (!diagramPage) {
        return;
    }

    var nodeId = document.getElementById('node-okr-node-id').value;
    var title = document.getElementById('node-okr-objective').value;
    var desc = document.getElementById('node-okr-keyresults').value;
    var btn = document.getElementById('node-okr-save-btn');
    var status = document.getElementById('node-okr-save-status');
    var csrfToken = diagramPage.dataset.csrfToken || '';

    btn.disabled = true;
    btn.textContent = 'Saving...';
    status.textContent = '';

    var form = new FormData();
    form.append('_csrf_token', csrfToken);
    form.append('node_id', nodeId);
    form.append('okr_title', title);
    form.append('okr_description', desc);

    fetch('/app/diagram/save-okr', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: form
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        btn.disabled = false;
        btn.textContent = 'Save OKRs to Node';
        if (data.status === 'ok') {
            var nodes = getDiagramNodeData();
            var node = nodes.find(function(entry) { return String(entry.id) === String(nodeId); });
            if (node) {
                node.okr_title = title;
                node.okr_description = desc;
                updateDiagramNodeData(nodes);
            }
            if (node) {
                var accordion = document.querySelector('[data-node-key="' + node.node_key + '"]');
                if (accordion) {
                    var objInput = accordion.querySelector('input[name*="okr_title"]');
                    var krInput = accordion.querySelector('textarea[name*="okr_description"]');
                    if (objInput) { objInput.value = title; }
                    if (krInput) { krInput.value = desc; }
                    accordion.classList.toggle('accordion-item--complete', !!title);
                }
            }
            setToneState(status, 'success');
            status.textContent = 'Saved successfully';
            window.setTimeout(closeNodeOkrPanel, 800);
        } else {
            setToneState(status, 'danger');
            status.textContent = 'Save failed - please try again';
        }
    })
    .catch(function() {
        btn.disabled = false;
        btn.textContent = 'Save OKRs to Node';
        setToneState(status, 'danger');
        status.textContent = 'Connection error';
    });
}

function generateDiagramAjax() {
    var diagramPage = getDiagramPage();
    var btn = document.getElementById('generate-diagram-btn');
    if (!diagramPage || !btn) {
        return;
    }

    var status = document.getElementById('generate-status');
    var statusEmpty = document.getElementById('generate-status-empty');
    var activeStatus = (status && status.offsetParent !== null) ? status : (statusEmpty || status);
    var origText = btn.textContent;
    var csrfToken = diagramPage.dataset.csrfToken || '';
    var projectId = diagramPage.dataset.projectId || '';

    btn.disabled = true;
    btn.textContent = 'Generating...';

    if (activeStatus) {
        setStatusBannerState(activeStatus, 'info', 'AI is analysing your strategy and building a visual roadmap. This usually takes 10-20 seconds...');
    }

    var formData = new FormData();
    formData.append('_csrf_token', csrfToken);
    formData.append('project_id', projectId);

    fetch('/app/diagram/generate', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: formData
    })
    .then(function(r) {
        return r.json().then(function(d) {
            return { ok: r.ok, data: d };
        });
    })
    .then(function(res) {
        if (res.ok && res.data.success) {
            if (activeStatus) {
                setStatusBannerState(activeStatus, 'success', 'Roadmap generated with ' + res.data.node_count + ' initiatives. Loading...');
            }
            window.setTimeout(function() { window.location.reload(); }, 1000);
            return;
        }

        if (activeStatus) {
            setStatusBannerState(activeStatus, 'error', res.data.error || 'Generation failed');
        }
        btn.disabled = false;
        btn.textContent = 'Try Again';
    })
    .catch(function() {
        if (activeStatus) {
            setStatusBannerState(activeStatus, 'error', 'Connection error');
        }
        btn.disabled = false;
        btn.textContent = origText;
    });
}

function initializeDiagramPage() {
    var diagramPage = getDiagramPage();
    if (!diagramPage || diagramPage.dataset.initialized === '1') {
        return;
    }
    diagramPage.dataset.initialized = '1';

    var nodeKey = new URLSearchParams(window.location.search).get('node');
    if (!nodeKey) {
        return;
    }

    var attempts = 0;
    var interval = window.setInterval(function() {
        attempts += 1;
        openNodeOkrPanel(nodeKey);
        if (document.getElementById('node-okr-panel') && document.getElementById('node-okr-panel').classList.contains('diagram-node-panel--open')) {
            window.clearInterval(interval);
        } else if (attempts > 40) {
            window.clearInterval(interval);
        }
    }, 150);
}

function setStatusBannerState(element, tone, message) {
    if (!element) {
        return;
    }
    element.classList.remove('hidden', 'generate-status-banner--info', 'generate-status-banner--success', 'generate-status-banner--error');
    element.classList.add('generate-status-banner--' + tone);
    element.textContent = message;
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
                btn.textContent = '';
                var s = document.createElement('span');
                s.className = 'loading-spinner-inline';
                btn.appendChild(s);
                btn.appendChild(document.createTextNode(' ' + loadingText));
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

window.loadGitLinks = function(localType, localId) {
    if (!localType || !localId) { return; }

    window._gitLinksLocalType = localType;
    window._gitLinksLocalId = localId;

    var loading = document.getElementById('git-links-loading');
    var list = document.getElementById('git-links-list');
    if (!list) { return; }

    if (loading) {
        setHiddenState(loading, false);
    }
    list.innerHTML = '';

    fetch('/app/git-links?local_type=' + encodeURIComponent(localType) + '&local_id=' + encodeURIComponent(localId), {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(function(res) { return res.json(); })
    .then(function(data) {
        if (loading) {
            setHiddenState(loading, true);
        }
        if (data.ok) {
            renderGitLinks(data.links);
        }
    })
    .catch(function() {
        if (loading) {
            setHiddenState(loading, true);
        }
    });
};

function renderGitLinks(links) {
    var list = document.getElementById('git-links-list');
    if (!list) { return; }

    if (!links || links.length === 0) {
        list.innerHTML = '<p class="git-link-empty">No git links yet.</p>';
        return;
    }

    list.innerHTML = links.map(function(link) {
        var statusClass = {
            'open': 'badge-info',
            'merged': 'badge-success',
            'closed': 'badge-secondary',
            'unknown': 'badge-secondary'
        }[link.status] || 'badge-secondary';

        var label = escapeHtml(link.ref_label || link.ref_url);
        var urlEscaped = escapeHtml(link.ref_url);
        var linkHtml = link.ref_url.startsWith('http')
            ? '<a href="' + urlEscaped + '" target="_blank" rel="noopener" class="git-link-ref">' + label + '</a>'
            : '<span class="git-link-ref">' + label + '</span>';

        return '<div class="git-link-row" data-link-id="' + escapeHtml(String(link.id)) + '">' +
            '<span class="badge git-link-status ' + statusClass + '">' +
                escapeHtml(link.status) +
            '</span>' +
            linkHtml +
            '<button type="button" class="js-git-links-delete git-link-remove" data-link-id="' + escapeHtml(String(link.id)) + '" title="Remove link">&times;</button>' +
            '</div>';
    }).join('');
}

function addGitLink() {
    var input = document.getElementById('git-links-ref-input');
    var errorEl = document.getElementById('git-links-error');
    var addBtn = document.getElementById('git-links-add-btn');
    var refUrl = input ? input.value.trim() : '';

    if (!refUrl) {
        showGitLinksError('Please enter a PR URL, commit SHA, or branch name.');
        return;
    }

    if (errorEl) {
        setHiddenState(errorEl, true);
    }
    if (addBtn) {
        addBtn.disabled = true;
        addBtn.textContent = 'Linking...';
    }

    var body = new URLSearchParams({
        _csrf_token: getCsrfTokenValue(),
        local_type: window._gitLinksLocalType || '',
        local_id: String(window._gitLinksLocalId || ''),
        ref_url: refUrl
    });

    fetch('/app/git-links', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: body.toString()
    })
    .then(function(res) { return res.json().then(function(d) { return { status: res.status, data: d }; }); })
    .then(function(r) {
        if (addBtn) {
            addBtn.disabled = false;
            addBtn.textContent = 'Link';
        }

        if (r.data.ok) {
            input.value = '';
            window.loadGitLinks(window._gitLinksLocalType, window._gitLinksLocalId);
            refreshGitLinkBadge(window._gitLinksLocalType, window._gitLinksLocalId);
        } else {
            showGitLinksError(r.data.error || 'Failed to add link.');
        }
    })
    .catch(function() {
        if (addBtn) {
            addBtn.disabled = false;
            addBtn.textContent = 'Link';
        }
        showGitLinksError('Network error. Please try again.');
    });
}

function deleteGitLink(linkId) {
    if (!linkId || !window.confirm('Remove this git link?')) { return; }

    var body = new URLSearchParams({
        _csrf_token: getCsrfTokenValue(),
        local_type: window._gitLinksLocalType || '',
        local_id: String(window._gitLinksLocalId || '')
    });

    fetch('/app/git-links/' + linkId + '/delete', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: body.toString()
    })
    .then(function(res) { return res.json(); })
    .then(function(data) {
        if (data.ok) {
            window.loadGitLinks(window._gitLinksLocalType, window._gitLinksLocalId);
            refreshGitLinkBadge(window._gitLinksLocalType, window._gitLinksLocalId);
        } else {
            showGitLinksError(data.error || 'Delete failed.');
        }
    })
    .catch(function() {
        showGitLinksError('Network error. Please try again.');
    });
}

function refreshGitLinkBadge(localType, localId) {
    fetch('/app/git-links?local_type=' + encodeURIComponent(localType) + '&local_id=' + encodeURIComponent(localId), {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(function(res) { return res.json(); })
    .then(function(data) {
        if (!data.ok) { return; }

        var count = data.links ? data.links.length : 0;
        var rowSelector = localType === 'user_story' ? '.story-row' : '.work-item-row';
        var row = document.querySelector(rowSelector + '[data-id="' + localId + '"]');
        if (!row) { return; }

        var badge = row.querySelector('.git-link-badge');

        if (count > 0) {
            if (badge) {
                badge.textContent = 'Git: ' + count;
            } else {
                var infoDiv = row.querySelector('.story-info, .work-item-info');
                if (infoDiv) {
                    var newBadge = document.createElement('span');
                    newBadge.className = 'badge badge-secondary git-link-badge';
                    newBadge.textContent = 'Git: ' + count;
                    infoDiv.appendChild(newBadge);
                }
            }
        } else if (badge) {
            badge.remove();
        }
    });
}

function showGitLinksError(msg) {
    var errorEl = document.getElementById('git-links-error');
    if (!errorEl) { return; }
    errorEl.textContent = msg;
    setHiddenState(errorEl, false);
}

// Password visibility toggle
function togglePassword(btn) {
    var wrapper = btn.closest('.password-wrapper');
    var input = wrapper.querySelector('input');
    var eyeOn = btn.querySelector('.eye-icon');
    var eyeOff = btn.querySelector('.eye-off-icon');
    if (input.type === 'password') {
        input.type = 'text';
        if (eyeOn) { eyeOn.hidden = true; }
        if (eyeOff) { eyeOff.hidden = false; }
    } else {
        input.type = 'password';
        if (eyeOn) { eyeOn.hidden = false; }
        if (eyeOff) { eyeOff.hidden = true; }
    }
}

function syncPasswordToggle(wrapper) {
    var input = wrapper.querySelector('input');
    var button = wrapper.querySelector('.password-toggle');
    if (!input || !button) return;

    var hasValue = input.value.length > 0;
    button.classList.toggle('hidden', !hasValue);
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
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                closeJiraSyncPreviewModal();
            }
        });
        document.body.appendChild(modal);
    }

    modal.innerHTML = '<div class="card jira-sync-preview-card">' +
        '<div class="card-header flex justify-between items-center">' +
            '<h2 class="card-title jira-sync-preview-title">Preview Jira Sync</h2>' +
            '<button type="button" class="js-jira-preview-close jira-sync-preview-close" aria-label="Close preview">&times;</button>' +
        '</div>' +
        '<div class="card-body" id="jira-preview-body">' +
            '<div class="jira-sync-preview-loading">' +
                '<div class="loading-spinner jira-sync-preview-loading-spinner"></div>' +
                '<p class="text-muted jira-sync-preview-loading-text">Checking what will be synced to ' + jiraKey + '...</p>' +
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
            body.innerHTML = '<p class="jira-sync-preview-error">Error: ' + escapeHtml(data.error) + '</p>' +
                '<div class="flex justify-end gap-2 jira-sync-preview-actions">' +
                    '<button type="button" class="btn btn-secondary js-jira-preview-close">Close</button>' +
                '</div>';
            return;
        }

        var pushCount = (data.push || []).length;
        var pullCount = (data.pull || []).length;

        var html = '<p class="text-muted jira-sync-preview-intro">Review what will happen when you sync to <strong>' + escapeHtml(jiraKey) + '</strong>:</p>';

        html += '<div class="jira-sync-preview-summary-grid">';
        html += '  <div class="jira-sync-preview-summary-card jira-sync-preview-summary-card--push">';
        html += '    <div class="jira-sync-preview-summary-count jira-sync-preview-summary-count--push">' + pushCount + '</div>';
        html += '    <div class="jira-sync-preview-summary-label">To Push</div>';
        html += '  </div>';
        html += '  <div class="jira-sync-preview-summary-card jira-sync-preview-summary-card--pull">';
        html += '    <div class="jira-sync-preview-summary-count jira-sync-preview-summary-count--pull">' + pullCount + '</div>';
        html += '    <div class="jira-sync-preview-summary-label">To Import</div>';
        html += '  </div>';
        html += '</div>';

        if (pushCount > 0) {
            html += '<details class="jira-sync-preview-section"><summary class="jira-sync-preview-section-summary">Items to push (' + pushCount + ')</summary>';
            html += '<ul class="jira-sync-preview-list">';
            (data.push || []).forEach(function(item) {
                var action = item.action === 'create' ? '<span class="jira-sync-preview-action jira-sync-preview-action--new">NEW</span>' : '<span class="jira-sync-preview-action jira-sync-preview-action--update">UPDATE</span>';
                html += '<li>' + action + ' ' + escapeHtml(item.type) + ': ' + escapeHtml(item.title || '') + '</li>';
            });
            html += '</ul></details>';
        }

        if (pullCount > 0) {
            html += '<details class="jira-sync-preview-section"><summary class="jira-sync-preview-section-summary">Items to import from Jira (' + pullCount + ')</summary>';
            html += '<ul class="jira-sync-preview-list">';
            (data.pull || []).forEach(function(item) {
                html += '<li>' + escapeHtml(item.type) + ': ' + escapeHtml(item.title || '') + ' <span class="text-muted">(' + escapeHtml(item.key || '') + ')</span></li>';
            });
            html += '</ul></details>';
        }

        if (pushCount === 0 && pullCount === 0) {
            html += '<p class="jira-sync-preview-empty">Everything is already in sync. No changes to push or pull.</p>';
        }

        html += '<div class="flex justify-end gap-2 jira-sync-preview-actions jira-sync-preview-actions--footer">';
        html += '  <button type="button" class="btn btn-secondary js-jira-preview-close">Cancel</button>';
        if (pushCount > 0 || pullCount > 0) {
            html += '  <button type="button" class="btn btn-primary js-jira-preview-submit" data-project-id="' + escapeHtml(String(projectId)) + '">Confirm Sync</button>';
        }
        html += '</div>';

        body.innerHTML = html;
    })
    .catch(function(err) {
        var body = document.getElementById('jira-preview-body');
        if (body) {
            body.innerHTML = '<p class="jira-sync-preview-error">Failed to load preview: ' + escapeHtml(err.message) + '</p>' +
                '<div class="flex justify-end gap-2 jira-sync-preview-actions">' +
                    '<button type="button" class="btn btn-secondary js-jira-preview-close">Close</button>' +
                    '<button type="button" class="btn btn-primary js-jira-preview-submit" data-project-id="' + escapeHtml(String(projectId)) + '">Sync Anyway</button>' +
                '</div>';
        }
    });
}

function closeJiraSyncPreviewModal() {
    var modal = document.getElementById('jira-sync-preview-modal');
    if (modal) {
        modal.remove();
    }
}

function submitJiraSyncPreview(projectId) {
    closeJiraSyncPreviewModal();
    var form = document.querySelector('.jira-sync-form[data-project-id="' + projectId + '"]');
    if (form) {
        form.submit();
    }
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
            setHiddenState(panel, true);
            btn.setAttribute('aria-expanded', 'false');
            btn.innerHTML = 'Summarised &#9660;';
        } else {
            setHiddenState(panel, false);
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

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-style-width]').forEach(el => { el.style.width = el.getAttribute('data-style-width'); });
    document.querySelectorAll('[data-style-background]').forEach(el => { el.style.background = el.getAttribute('data-style-background'); });
    document.querySelectorAll('[data-style-color]').forEach(el => { el.style.color = el.getAttribute('data-style-color'); });
});

// ===========================
// Quality Score Background Loader
// ===========================
(function() {
    function QualityScoreManager() {
        this.queue = [];
        this.activeCount = 0;
        this.maxConcurrent = 2; // Gemini rate limit consideration
        this.csrfToken = document.querySelector('input[name="_csrf_token"]')?.value;
    }

    QualityScoreManager.prototype.init = function() {
        var self = this;
        document.querySelectorAll('.js-quality-score-placeholder').forEach(function(el) {
            self.queue.push({
                el: el,
                id: el.dataset.taskId,
                type: el.dataset.taskType // 'story' or 'work-item'
            });
        });

        if (this.queue.length > 0) {
            this.processQueue();
        }
    };

    QualityScoreManager.prototype.processQueue = function() {
        var self = this;
        // If nothing left or we're at capacity, stop
        if (this.queue.length === 0 || this.activeCount >= this.maxConcurrent) {
            return;
        }

        var task = this.queue.shift();
        this.activeCount++;

        var url = task.type === 'story'
            ? '/app/user-stories/' + task.id + '/score'
            : '/app/work-items/' + task.id + '/score';

        var formData = new FormData();
        if (this.csrfToken) formData.append('_csrf_token', this.csrfToken);

        fetch(url, {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(res) {
            if (!res.ok) throw new Error('Network response was not ok');
            return res.json();
        })
        .then(function(data) {
            if (data.status === 'ok') {
                self.updateUI(task, data);
            } else {
                task.el.classList.remove('js-quality-score-placeholder');
                task.el.textContent = '!';
                task.el.title = data.message || 'Scoring failed';
                task.el.style.backgroundColor = '#6b7280';
            }
        })
        .catch(function(err) {
            task.el.classList.remove('js-quality-score-placeholder');
            task.el.textContent = '!';
            task.el.style.backgroundColor = '#6b7280';
            console.error('Quality scoring failed:', err);
        })
        .finally(function() {
            self.activeCount--;
            // Trigger next batch
            setTimeout(function() { self.processQueue(); }, 200);
        });

        // Try to start another if we have capacity
        if (this.activeCount < this.maxConcurrent) {
            this.processQueue();
        }
    };

    QualityScoreManager.prototype.updateUI = function(task, data) {
        var score = data.score;
        var color = score >= 80 ? '#10b981' : (score >= 50 ? '#f59e0b' : '#ef4444');

        // Update pill
        task.el.textContent = score;
        task.el.style.background = color; // Use .background for consistency with existing code
        task.el.title = 'Quality score: ' + score + '/100';
        task.el.classList.remove('js-quality-score-placeholder');

        // Update breakdown container if provided
        if (data.html) {
            var container = document.querySelector('.js-quality-breakdown-container[data-id="' + task.id + '"][data-type="' + task.type + '"]');
            if (container) {
                container.innerHTML = data.html;
            }
        }
    };

    document.addEventListener('DOMContentLoaded', function() {
        var manager = new QualityScoreManager();
        manager.init();
    });
})();
