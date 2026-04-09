<?php
/**
 * Git Links Field Partial
 *
 * Reusable section for displaying and adding git links (PRs, commits, branches)
 * to a user story or work item. Rendered inside an edit modal.
 *
 * Expects the following JS variables to be set before include:
 *   - window._gitLinksLocalType  ('user_story' or 'hl_work_item')
 *   - window._gitLinksLocalId    (int)
 *   - window._gitLinksCsrfToken  (string)
 *
 * The section loads its data on-demand via AJAX when the modal opens.
 * The containing modal must call loadGitLinks(localType, localId) after opening.
 */
?>

<!-- ===========================
     Git Links Section
     =========================== -->
<div class="git-links-section" id="git-links-section" style="margin-top: 1.5rem; padding-top: 1.25rem; border-top: 1px solid var(--border);">
    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.75rem;">
        <h4 style="margin: 0; font-size: 0.9rem; font-weight: 600; color: var(--text-muted, #6b7280);">
            Git Links
        </h4>
        <span id="git-links-loading" style="font-size: 0.8rem; color: var(--text-muted, #6b7280); display: none;">Loading...</span>
    </div>

    <!-- Existing links list -->
    <div id="git-links-list" style="margin-bottom: 0.75rem;"></div>

    <!-- Add new link form -->
    <div style="display: flex; gap: 0.5rem; align-items: center;">
        <input type="text"
               id="git-links-ref-input"
               class="form-control"
               placeholder="Paste PR URL, commit SHA, or branch name"
               style="flex: 1; font-size: 0.8125rem;">
        <button type="button"
                id="git-links-add-btn"
                class="btn btn-sm btn-secondary"
                onclick="addGitLink()">
            Link
        </button>
    </div>
    <div id="git-links-error" style="color: var(--danger, #dc3545); font-size: 0.8rem; margin-top: 0.25rem; display: none;"></div>
</div>

<script>
(function () {
    // ===========================
    // Git Links: Load on modal open
    // ===========================

    /**
     * Fetch and render git links for the currently-open modal item.
     *
     * @param {string} localType 'user_story' or 'hl_work_item'
     * @param {number} localId   Primary key of the item
     */
    window.loadGitLinks = function (localType, localId) {
        if (!localType || !localId) { return; }

        window._gitLinksLocalType = localType;
        window._gitLinksLocalId   = localId;

        var loading = document.getElementById('git-links-loading');
        var list    = document.getElementById('git-links-list');
        if (!list) { return; }

        loading.style.display = 'inline';
        list.innerHTML = '';

        fetch('/app/git-links?local_type=' + encodeURIComponent(localType) + '&local_id=' + encodeURIComponent(localId), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            loading.style.display = 'none';
            if (data.ok) {
                renderGitLinks(data.links);
            }
        })
        .catch(function () {
            loading.style.display = 'none';
        });
    };

    // ===========================
    // Git Links: Render
    // ===========================

    function renderGitLinks(links) {
        var list = document.getElementById('git-links-list');
        if (!list) { return; }

        if (!links || links.length === 0) {
            list.innerHTML = '<p style="font-size:0.8125rem; color:var(--text-muted,#6b7280); margin:0;">No git links yet.</p>';
            return;
        }

        list.innerHTML = links.map(function (link) {
            var statusClass = {
                'open':    'badge-info',
                'merged':  'badge-success',
                'closed':  'badge-secondary',
                'unknown': 'badge-secondary'
            }[link.status] || 'badge-secondary';

            var label = escapeHtml(link.ref_label || link.ref_url);
            var urlEscaped = escapeHtml(link.ref_url);

            var linkHtml = link.ref_url.startsWith('http')
                ? '<a href="' + urlEscaped + '" target="_blank" rel="noopener" style="font-size:0.8125rem;">' + label + '</a>'
                : '<span style="font-size:0.8125rem;">' + label + '</span>';

            return '<div class="git-link-row" data-link-id="' + escapeHtml(String(link.id)) + '" ' +
                   'style="display:flex;align-items:center;gap:0.5rem;margin-bottom:0.375rem;">' +
                   '<span class="badge ' + statusClass + '" style="font-size:0.7rem;min-width:52px;text-align:center;">' +
                       escapeHtml(link.status) +
                   '</span>' +
                   linkHtml +
                   '<button type="button" onclick="deleteGitLink(' + link.id + ')" ' +
                   'style="margin-left:auto;background:none;border:none;cursor:pointer;color:var(--danger,#dc3545);font-size:1rem;line-height:1;padding:0 4px;" ' +
                   'title="Remove link">&times;</button>' +
                   '</div>';
        }).join('');
    }

    // ===========================
    // Git Links: Add
    // ===========================

    window.addGitLink = function () {
        var input    = document.getElementById('git-links-ref-input');
        var errorEl  = document.getElementById('git-links-error');
        var addBtn   = document.getElementById('git-links-add-btn');
        var refUrl   = input ? input.value.trim() : '';

        if (!refUrl) {
            showGitLinksError('Please enter a PR URL, commit SHA, or branch name.');
            return;
        }

        errorEl.style.display = 'none';
        addBtn.disabled = true;
        addBtn.textContent = 'Linking...';

        var csrfToken = document.querySelector('input[name="_csrf_token"]');

        var body = new URLSearchParams({
            _csrf_token: csrfToken ? csrfToken.value : '',
            local_type:  window._gitLinksLocalType || '',
            local_id:    String(window._gitLinksLocalId || ''),
            ref_url:     refUrl
        });

        fetch('/app/git-links', {
            method:  'POST',
            headers: {
                'Content-Type':    'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: body.toString()
        })
        .then(function (res) { return res.json().then(function (d) { return { status: res.status, data: d }; }); })
        .then(function (r) {
            addBtn.disabled = false;
            addBtn.textContent = 'Link';

            if (r.data.ok) {
                input.value = '';
                window.loadGitLinks(window._gitLinksLocalType, window._gitLinksLocalId);
                refreshGitLinkBadge(window._gitLinksLocalType, window._gitLinksLocalId);
            } else {
                showGitLinksError(r.data.error || 'Failed to add link.');
            }
        })
        .catch(function () {
            addBtn.disabled = false;
            addBtn.textContent = 'Link';
            showGitLinksError('Network error. Please try again.');
        });
    };

    // ===========================
    // Git Links: Delete
    // ===========================

    window.deleteGitLink = function (linkId) {
        if (!confirm('Remove this git link?')) { return; }

        var csrfToken = document.querySelector('input[name="_csrf_token"]');

        var body = new URLSearchParams({
            _csrf_token: csrfToken ? csrfToken.value : '',
            local_type:  window._gitLinksLocalType || '',
            local_id:    String(window._gitLinksLocalId || '')
        });

        fetch('/app/git-links/' + linkId + '/delete', {
            method:  'POST',
            headers: {
                'Content-Type':    'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: body.toString()
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (data.ok) {
                window.loadGitLinks(window._gitLinksLocalType, window._gitLinksLocalId);
                refreshGitLinkBadge(window._gitLinksLocalType, window._gitLinksLocalId);
            } else {
                showGitLinksError(data.error || 'Delete failed.');
            }
        })
        .catch(function () {
            showGitLinksError('Network error. Please try again.');
        });
    };

    // ===========================
    // Git Links: Badge refresh
    // ===========================

    /**
     * Update the row badge count for a local item after add/delete.
     *
     * Finds the row element by data-id and updates its .git-link-badge span.
     *
     * @param {string} localType 'user_story' or 'hl_work_item'
     * @param {number} localId
     */
    function refreshGitLinkBadge(localType, localId) {
        fetch('/app/git-links?local_type=' + encodeURIComponent(localType) + '&local_id=' + encodeURIComponent(localId), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
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
                    // Insert badge into the info div
                    var infoDiv = row.querySelector('.story-info, .work-item-info');
                    if (infoDiv) {
                        var newBadge = document.createElement('span');
                        newBadge.className = 'badge badge-secondary git-link-badge';
                        newBadge.textContent = 'Git: ' + count;
                        infoDiv.appendChild(newBadge);
                    }
                }
            } else {
                if (badge) { badge.remove(); }
            }
        });
    }

    // ===========================
    // Git Links: Error display
    // ===========================

    function showGitLinksError(msg) {
        var errorEl = document.getElementById('git-links-error');
        if (!errorEl) { return; }
        errorEl.textContent = msg;
        errorEl.style.display = 'block';
    }

    // ===========================
    // Enter key support for input (guarded against double-include)
    // ===========================

    if (!window._gitLinksInputBound) {
        window._gitLinksInputBound = true;
        document.addEventListener('DOMContentLoaded', function () {
            var input = document.getElementById('git-links-ref-input');
            if (input) {
                input.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        addGitLink();
                    }
                });
            }
        });
    }
}());
</script>
