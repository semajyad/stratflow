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
        <h2 class="card-title gen-style-e2b74b">Linked Repositories</h2>
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
                <div class="gen-style-d3297f">
                    <h3 class="gen-style-464282">
                        @<?= htmlspecialchars($accountLogin) ?>
                    </h3>

                    <div class="gen-style-dd0c1e">
                        <?php foreach ($repos as $repo): ?>
                        <?php $checked = isset($linked_id_set[(int) $repo['id']]); ?>
                        <label class="js-github-repo-label gen-style-d3af80">
                            <input type="checkbox"
                                   class="js-github-repo-checkbox"
                                   name="integration_repo_ids[]"
                                   value="<?= (int) $repo['id'] ?>"
                                   <?= $checked ? 'checked' : '' ?> class="gen-style-0f09ce">
                            <span class="gen-style-83bdfb">
                                <?= htmlspecialchars($repo['repo_full_name']) ?>
                            </span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>

                <div class="gen-style-9c9fd4">
                    <button type="submit" class="btn btn-primary">Save</button>
                    <a href="/app/home" class="btn btn-secondary">Cancel</a>
                    <span class="gen-style-754ab1">
                        Changes take effect immediately for new webhooks.
                    </span>
                </div>
            </form>
        <?php endif; ?>
    </div>
</section>
