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
<div class="git-links-section" id="git-links-section">
    <div class="git-links-section__header">
        <h4 class="git-links-section__title">
            Git Links
        </h4>
        <span id="git-links-loading" class="git-links-section__loading hidden">Loading...</span>
    </div>

    <!-- Existing links list -->
    <div id="git-links-list" class="git-links-section__list"></div>

    <!-- Add new link form -->
    <div class="git-links-section__form">
        <input type="text"
               id="git-links-ref-input"
               class="form-control git-links-section__input"
               placeholder="Paste PR URL, commit SHA, or branch name"
               >
        <button type="button"
                id="git-links-add-btn"
                class="btn btn-sm btn-secondary js-git-links-add">
            Link
        </button>
    </div>
    <div id="git-links-error" class="git-links-section__error hidden"></div>
</div>
