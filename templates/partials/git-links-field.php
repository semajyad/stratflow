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
                class="btn btn-sm btn-secondary js-git-links-add">
            Link
        </button>
    </div>
    <div id="git-links-error" style="color: var(--danger, #dc3545); font-size: 0.8rem; margin-top: 0.25rem; display: none;"></div>
</div>
