<?php
/**
 * Project GitHub Repos Template
 *
 * Per-project repo subscription picker. Shows all repos available across
 * every active GitHub App installation in the org, grouped by account login.
 * Currently linked repos are pre-checked.
 *
 * Variables:
 *   $project          (array)  Project row
 *   $repos_by_account (array)  Repos grouped by account_login string key
 *   $linked_id_set    (array)  Flip map of linked integration_repo_id => true
 *   $csrf_token       (string)
 */
?>

<!-- ===========================
     Page Header
     =========================== -->
<div class="page-header">
    <h1 class="page-title">GitHub Repos — <?= htmlspecialchars($project['name'] ?? '') ?></h1>
    <p class="page-subtitle">
        <a href="/app/home">&larr; Back to Projects</a>
    </p>
</div>

<!-- ===========================
     Repo Picker
     =========================== -->
<section class="card mt-4">
    <div class="card-header">
        <h2 class="card-title" style="margin: 0;">Linked Repositories</h2>
        <small class="text-muted">
            Select which repos should feed pull request links into this project.
            A repo can be linked to multiple projects independently.
        </small>
    </div>
    <div class="card-body">
        <?php if (empty($repos_by_account)): ?>
            <p class="text-muted">
                No GitHub App installations found for your organisation.
                Ask an admin to
                <a href="/app/admin/integrations">connect a GitHub account</a> first.
            </p>
        <?php else: ?>
            <form method="POST" action="/app/projects/<?= (int) $project['id'] ?>/github/save">
                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

                <?php foreach ($repos_by_account as $accountLogin => $repos): ?>
                <div style="margin-bottom: 1.5rem;">
                    <h3 style="font-size: 0.9rem; font-weight: 600; color: var(--text-muted);
                               text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 0.75rem;
                               border-bottom: 1px solid var(--border); padding-bottom: 0.4rem;">
                        @<?= htmlspecialchars($accountLogin) ?>
                    </h3>

                    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 0.4rem;">
                        <?php foreach ($repos as $repo): ?>
                        <?php $checked = isset($linked_id_set[(int) $repo['id']]); ?>
                        <label style="display: flex; align-items: center; gap: 0.5rem;
                                      padding: 0.4rem 0.6rem; border-radius: 4px; cursor: pointer;
                                      background: <?= $checked ? 'var(--primary-light, #eff6ff)' : 'transparent' ?>;
                                      border: 1px solid <?= $checked ? 'var(--primary, #3b82f6)' : 'var(--border)' ?>;">
                            <input type="checkbox"
                                   name="integration_repo_ids[]"
                                   value="<?= (int) $repo['id'] ?>"
                                   <?= $checked ? 'checked' : '' ?>
                                   style="margin: 0; flex-shrink: 0;">
                            <span style="font-size: 0.875rem; word-break: break-all;">
                                <?= htmlspecialchars($repo['repo_full_name']) ?>
                            </span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>

                <div style="padding-top: 1rem; border-top: 1px solid var(--border); display: flex; gap: 0.5rem; align-items: center;">
                    <button type="submit" class="btn btn-primary">Save</button>
                    <a href="/app/home" class="btn btn-secondary">Cancel</a>
                    <span style="margin-left: auto; font-size: 0.8rem; color: var(--text-muted);">
                        Changes take effect immediately for new webhooks.
                    </span>
                </div>
            </form>
        <?php endif; ?>
    </div>
</section>

<script>
// Highlight label when checkbox state changes
document.querySelectorAll('input[name="integration_repo_ids[]"]').forEach(function(cb) {
    cb.addEventListener('change', function() {
        var label = this.closest('label');
        if (!label) return;
        if (this.checked) {
            label.style.background = 'var(--primary-light, #eff6ff)';
            label.style.borderColor = 'var(--primary, #3b82f6)';
        } else {
            label.style.background = 'transparent';
            label.style.borderColor = 'var(--border)';
        }
    });
});
</script>
