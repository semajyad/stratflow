<?php
/**
 * Integration Hub Template
 *
 * Lists all available integrations (Jira Cloud, Azure DevOps, GitHub, GitLab) with
 * connection status, sync controls, and configuration links.
 *
 * Variables: $user (array), $integrations (array keyed by provider),
 *            $jira_sync_count (int), $csrf_token (string)
 */

$jira = $integrations['jira'] ?? null;
$jiraActive = $jira && $jira['status'] === 'active';
$jiraConfig = $jira ? (json_decode($jira['config_json'] ?? '{}', true) ?: []) : [];

// GitHub App — multiple installs per org; $github_installs is an array from the controller
$githubInstalls = $github_installs ?? [];
$hasGithub      = count($githubInstalls) > 0;

$gitlab = $integrations['gitlab'] ?? null;
$gitlabActive = $gitlab && $gitlab['status'] === 'active';
$gitlabConfig = $gitlab ? (json_decode($gitlab['config_json'] ?? '{}', true) ?: []) : [];
?>

<!-- ===========================
     Page Header
     =========================== -->
<div class="page-header">
    <h1 class="page-title">Integrations</h1>
    <p class="page-subtitle">
        <a href="/app/admin">&larr; Back to Administration</a>
    </p>
</div>

<!-- ===========================
     Jira Cloud Integration
     =========================== -->
<section class="card mt-4">
    <div class="card-header integration-card-header">
        <div>
            <h2 class="card-title integration-card-title">Jira Cloud</h2>
            <small class="text-muted">Sync work items and user stories with Jira Cloud</small>
        </div>
        <div>
            <?php if ($jiraActive): ?>
                <span class="badge badge-success integration-status-badge">Connected</span>
            <?php elseif ($jira && $jira['status'] === 'error'): ?>
                <span class="badge badge-danger integration-status-badge">Error</span>
            <?php else: ?>
                <span class="badge badge-secondary integration-status-badge">Disconnected</span>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body">
        <?php if ($jiraActive): ?>
            <!-- Connected state -->
            <?php $sh = $sync_health ?? []; ?>
            <div class="integration-stats-grid">
                <div>
                    <span class="text-muted integration-stat-label">Site</span>
                    <strong><?= htmlspecialchars($jira['display_name'] ?? '') ?></strong>
                    <?php if (!empty($jiraConfig['project_key'])): ?>
                        <br><span class="badge badge-primary integration-project-pill"><?= htmlspecialchars($jiraConfig['project_key']) ?></span>
                    <?php endif; ?>
                </div>
                <div>
                    <span class="text-muted integration-stat-label">Epics</span>
                    <strong class="integration-stat-value"><?= (int) ($sh['epics'] ?? 0) ?></strong>
                </div>
                <div>
                    <span class="text-muted integration-stat-label">Stories</span>
                    <strong class="integration-stat-value"><?= (int) ($sh['stories'] ?? 0) ?></strong>
                </div>
                <div>
                    <span class="text-muted integration-stat-label">Risks</span>
                    <strong class="integration-stat-value"><?= (int) ($sh['risks'] ?? 0) ?></strong>
                </div>
                <div>
                    <span class="text-muted integration-stat-label">Sprints</span>
                    <strong class="integration-stat-value"><?= (int) ($sh['sprints'] ?? 0) ?></strong>
                </div>
                <div>
                    <span class="text-muted integration-stat-label">Last Sync</span>
                    <strong><?= $jira['last_sync_at'] ? date('j M H:i', strtotime($jira['last_sync_at'])) : 'Never' ?></strong>
                </div>
                <?php if (($sh['recent_errors'] ?? 0) > 0): ?>
                <div>
                    <span class="text-muted integration-stat-label">Errors (24h)</span>
                    <strong class="integration-stat-value integration-stat-value--danger"><?= (int) $sh['recent_errors'] ?></strong>
                </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($jira['error_message'])): ?>
                <div class="alert alert-warning integration-alert-spaced">
                    <strong>Last error:</strong> <?= htmlspecialchars($jira['error_message']) ?>
                    (<?= (int) $jira['error_count'] ?> consecutive errors)
                </div>
            <?php endif; ?>

            <!-- Action buttons -->
            <div class="integration-actions">
                <a href="/app/admin/integrations/jira/configure" class="btn btn-sm btn-secondary">Configure</a>

                <form method="POST" action="/app/admin/integrations/jira/push" class="inline-form"
                      data-loading="Pushing to Jira..."
                      data-overlay="Pushing work items and user stories to Jira. This may take a moment."
                      class="inline-form integration-push-form">
                    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <select name="project_id" class="form-control integration-select-compact" required>
                        <option value="">Project...</option>
                        <?php if (!empty($all_projects)): ?>
                            <?php foreach ($all_projects as $ap): ?>
                                <option value="<?= (int) $ap['id'] ?>" <?= ((int)($ap['id'] ?? 0)) === (int)($_SESSION['_last_project_id'] ?? 0) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($ap['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                    <button type="submit" class="btn btn-sm btn-primary"
                            class="js-jira-push-submit"
                            data-confirm="Push work items and stories to Jira?">
                        Push
                    </button>
                </form>

                <form method="POST" action="/app/admin/integrations/jira/pull" class="inline-form"
                      data-loading="Pulling from Jira...">
                    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <input type="hidden" name="project_id" value="<?= (int) ($_SESSION['_last_project_id'] ?? 0) ?>">
                    <button type="submit" class="btn btn-sm btn-secondary"
                            data-confirm="Pull changes from Jira?">
                        Pull Changes
                    </button>
                </form>

                <a href="/app/admin/integrations/sync-log" class="btn btn-sm btn-secondary">Sync Log</a>

                <div class="integration-actions-spacer">
                    <form method="POST" action="/app/admin/integrations/jira/disconnect" class="inline-form">
                        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        <button type="submit" class="btn btn-sm btn-danger"
                                data-confirm="Disconnect Jira Cloud? Sync mappings will be preserved.">
                            Disconnect
                        </button>
                    </form>
                </div>
            </div>
        <?php else: ?>
            <!-- Disconnected state -->
            <p class="text-muted integration-copy-spaced">
                Connect your Jira Cloud instance to sync high-level work items as Epics
                and user stories as Stories. Changes can be pushed and pulled bidirectionally.
            </p>
            <a href="/app/admin/integrations/jira/connect" class="btn btn-primary">
                Connect to Jira Cloud
            </a>
        <?php endif; ?>
    </div>
</section>

<!-- ===========================
     GitHub App Integration
     =========================== -->
<?php if (($settings['feature_github'] ?? true) || $hasGithub): ?>
<section class="card mt-4">
    <div class="card-header integration-card-header">
        <div>
            <h2 class="card-title integration-card-title">GitHub</h2>
            <small class="text-muted">Auto-link pull requests to user stories via GitHub App — no copy/paste required</small>
        </div>
        <div>
            <?php if ($hasGithub): ?>
                <span class="badge badge-success integration-status-badge">
                    <?= count($githubInstalls) ?> account<?= count($githubInstalls) === 1 ? '' : 's' ?> connected
                </span>
            <?php else: ?>
                <span class="badge badge-secondary integration-status-badge">Not connected</span>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body">
        <?php if ($hasGithub): ?>
            <!-- Connected state — list each GitHub account installation -->
            <table class="integration-table">
                <thead>
                    <tr class="integration-table-row">
                        <th class="integration-table-head">Account</th>
                        <th class="integration-table-head">Repos</th>
                        <th class="integration-table-head integration-table-head--right"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($githubInstalls as $install): ?>
                    <tr class="integration-table-row">
                        <td class="integration-table-cell">
                            <strong>@<?= htmlspecialchars($install['account_login'] ?? '') ?></strong>
                        </td>
                        <td class="integration-table-cell">
                            <?php
                                $repoCount = (int) $install['repo_count'];
                                $repoNames = $install['repo_names'] ?? [];
                            ?>
                            <?php if ($repoCount > 0 && !empty($repoNames)): ?>
                            <span class="repo-count-badge integration-repo-badge" tabindex="0">
                                <?= $repoCount ?> repo<?= $repoCount === 1 ? '' : 's' ?>
                                <span class="repo-tooltip integration-repo-tooltip">
                                    <?php foreach ($repoNames as $rn): ?>
                                        <span class="integration-repo-tooltip-item"><?= htmlspecialchars($rn) ?></span>
                                    <?php endforeach; ?>
                                </span>
                            </span>
                            <?php else: ?>
                            <span class="integration-empty-note">0 repos</span>
                            <?php endif; ?>
                        </td>
                        <td class="integration-table-cell integration-table-cell--right integration-table-cell--nowrap">
                            <?php if ($install['installation_id']): ?>
                            <a href="https://github.com/settings/installations/<?= (int) $install['installation_id'] ?>"
                               target="_blank" rel="noopener noreferrer"
                               class="btn btn-sm btn-secondary integration-table-link-gap">
                                Manage on GitHub
                            </a>
                            <?php endif; ?>
                            <form method="POST"
                                  action="/app/admin/integrations/github/<?= (int) $install['id'] ?>/disconnect"
                                  class="inline-form integration-inline-form">
                                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                <button type="submit" class="btn btn-sm btn-danger"
                                        data-confirm="Disconnect @<?= htmlspecialchars($install['account_login'] ?? '', ENT_QUOTES, 'UTF-8') ?>? PR links already created will be preserved.">
                                    Disconnect
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <p class="text-muted integration-helper-copy">
                To add or remove repos use the <em>Manage on GitHub</em> link above — StratFlow syncs automatically.
                Include <code>SF-{id}</code> or <code>StratFlow-{id}</code> in a PR description or commit message to link it.
                No reference? AI will try to match it automatically.
            </p>

            <a href="/app/admin/integrations/github/install" class="btn btn-sm btn-secondary">
                + Add another GitHub account
            </a>

        <?php else: ?>
            <!-- Disconnected state -->
            <p class="text-muted integration-copy-spaced">
                Install the StratFlow GitHub App to automatically link pull requests and commits to user stories.
                GitHub handles the repository picker — no webhook URLs or secrets to copy.
                Include <code>SF-{id}</code> or <code>StratFlow-{id}</code> in your PR description or commit message, or let AI match automatically.
            </p>
            <?php if (($github_app_slug ?? '') !== ''): ?>
            <a href="/app/admin/integrations/github/install" class="btn btn-primary">
                Install GitHub App
            </a>
            <?php else: ?>
            <p class="text-muted integration-inline-note">
                <em>GITHUB_APP_SLUG is not configured — ask your system administrator to set it up.</em>
            </p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>

<?php endif; ?>

<!-- ===========================
     GitLab Webhook Integration
     =========================== -->
<?php if (($settings['feature_gitlab'] ?? true) || $gitlabActive): ?>
<section class="card mt-4">
    <div class="card-header integration-card-header">
        <div>
            <h2 class="card-title integration-card-title">GitLab</h2>
            <small class="text-muted">Auto-link merge requests to user stories and work items via webhook</small>
        </div>
        <div>
            <?php if ($gitlabActive): ?>
                <span class="badge badge-success integration-status-badge">Connected</span>
            <?php else: ?>
                <span class="badge badge-secondary integration-status-badge">Disconnected</span>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body">
        <?php if ($gitlabActive): ?>
            <!-- Connected state -->
            <div class="integration-webhook-block">
                <span class="text-muted integration-field-label">Webhook URL</span>
                <code class="integration-code-block"><?= htmlspecialchars(($_SERVER['HTTP_HOST'] ?? '') ? 'https://' . $_SERVER['HTTP_HOST'] . '/webhook/git/gitlab' : '/webhook/git/gitlab') ?></code>
            </div>

            <div class="integration-secret-block">
                <span class="text-muted integration-field-label">Webhook Secret Token</span>
                <?php
                    $glSecret = $gitlabConfig['webhook_secret'] ?? '';
                    $glMasked = $glSecret !== ''
                        ? str_repeat('*', max(0, strlen($glSecret) - 4)) . substr($glSecret, -4)
                        : '';
                ?>
                <?php if ($glSecret !== ''): ?>
                    <div class="integration-secret-row">
                        <code id="gitlab-secret-display"
                              data-masked="<?= htmlspecialchars($glMasked) ?>"
                              class="integration-secret-code"><?= htmlspecialchars($glMasked) ?></code>
                        <button type="button" class="btn btn-sm btn-secondary js-reveal-git-secret"
                                data-provider="gitlab"
                                id="gitlab-secret-reveal-btn">Reveal</button>
                    </div>
                <?php else: ?>
                    <span class="text-muted">No secret set.</span>
                <?php endif; ?>
            </div>

            <div class="integration-actions">
                <form method="POST" action="/app/admin/integrations/git/gitlab/regenerate-secret" class="inline-form">
                    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <button type="submit" class="btn btn-sm btn-secondary"
                            data-confirm="Regenerate GitLab webhook secret? You must update your repository webhook settings with the new secret token.">
                        Regenerate Secret
                    </button>
                </form>

                <div class="integration-actions-spacer">
                    <form method="POST" action="/app/admin/integrations/git/gitlab/disconnect" class="inline-form">
                        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        <button type="submit" class="btn btn-sm btn-danger"
                                data-confirm="Disconnect GitLab webhook?">
                            Disconnect
                        </button>
                    </form>
                </div>
            </div>
        <?php else: ?>
            <!-- Disconnected state -->
            <p class="text-muted integration-copy-spaced">
                Connect a GitLab webhook to automatically link merge requests to user stories and work items.
                Include <code>SF-{id}</code> or <code>StratFlow-{id}</code> in your MR description.
            </p>
            <form method="POST" action="/app/admin/integrations/git/gitlab/connect" class="inline-form">
                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <button type="submit" class="btn btn-primary">Connect GitLab</button>
            </form>
        <?php endif; ?>
    </div>
</section>

<?php endif; ?>

<!-- ===========================
     Azure DevOps (Coming Soon)
     =========================== -->
<section class="card mt-4 integration-card-disabled">
    <div class="card-header integration-card-header">
        <div>
            <h2 class="card-title integration-card-title">Azure DevOps</h2>
            <small class="text-muted">Sync with Azure DevOps boards and work items</small>
        </div>
        <div>
            <span class="badge badge-secondary integration-status-badge">Coming Soon</span>
        </div>
    </div>
    <div class="card-body">
        <p class="text-muted">
            Azure DevOps integration is planned for a future release. You'll be able to sync
            work items, user stories, and sprints with Azure Boards.
        </p>
        <button class="btn btn-secondary" disabled>Connect to Azure DevOps</button>
    </div>
</section>
