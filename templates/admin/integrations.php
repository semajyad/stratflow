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

$github = $integrations['github'] ?? null;
$githubActive = $github && $github['status'] === 'active';
$githubConfig = $github ? (json_decode($github['config_json'] ?? '{}', true) ?: []) : [];

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
    <div class="card-header" style="display: flex; align-items: center; justify-content: space-between;">
        <div>
            <h2 class="card-title" style="margin: 0;">Jira Cloud</h2>
            <small class="text-muted">Sync work items and user stories with Jira Cloud</small>
        </div>
        <div>
            <?php if ($jiraActive): ?>
                <span class="badge badge-success" style="font-size: 0.85rem; padding: 4px 12px;">Connected</span>
            <?php elseif ($jira && $jira['status'] === 'error'): ?>
                <span class="badge badge-danger" style="font-size: 0.85rem; padding: 4px 12px;">Error</span>
            <?php else: ?>
                <span class="badge badge-secondary" style="font-size: 0.85rem; padding: 4px 12px;">Disconnected</span>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body">
        <?php if ($jiraActive): ?>
            <!-- Connected state -->
            <?php $sh = $sync_health ?? []; ?>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
                <div>
                    <span class="text-muted" style="font-size: 0.75rem; display: block; text-transform: uppercase; letter-spacing: 0.05em;">Site</span>
                    <strong><?= htmlspecialchars($jira['display_name'] ?? '') ?></strong>
                    <?php if (!empty($jiraConfig['project_key'])): ?>
                        <br><span class="badge badge-primary" style="font-size: 0.7rem;"><?= htmlspecialchars($jiraConfig['project_key']) ?></span>
                    <?php endif; ?>
                </div>
                <div>
                    <span class="text-muted" style="font-size: 0.75rem; display: block; text-transform: uppercase; letter-spacing: 0.05em;">Epics</span>
                    <strong style="font-size: 1.25rem;"><?= (int) ($sh['epics'] ?? 0) ?></strong>
                </div>
                <div>
                    <span class="text-muted" style="font-size: 0.75rem; display: block; text-transform: uppercase; letter-spacing: 0.05em;">Stories</span>
                    <strong style="font-size: 1.25rem;"><?= (int) ($sh['stories'] ?? 0) ?></strong>
                </div>
                <div>
                    <span class="text-muted" style="font-size: 0.75rem; display: block; text-transform: uppercase; letter-spacing: 0.05em;">Risks</span>
                    <strong style="font-size: 1.25rem;"><?= (int) ($sh['risks'] ?? 0) ?></strong>
                </div>
                <div>
                    <span class="text-muted" style="font-size: 0.75rem; display: block; text-transform: uppercase; letter-spacing: 0.05em;">Sprints</span>
                    <strong style="font-size: 1.25rem;"><?= (int) ($sh['sprints'] ?? 0) ?></strong>
                </div>
                <div>
                    <span class="text-muted" style="font-size: 0.75rem; display: block; text-transform: uppercase; letter-spacing: 0.05em;">Last Sync</span>
                    <strong><?= $jira['last_sync_at'] ? date('j M H:i', strtotime($jira['last_sync_at'])) : 'Never' ?></strong>
                </div>
                <?php if (($sh['recent_errors'] ?? 0) > 0): ?>
                <div>
                    <span class="text-muted" style="font-size: 0.75rem; display: block; text-transform: uppercase; letter-spacing: 0.05em;">Errors (24h)</span>
                    <strong style="font-size: 1.25rem; color: var(--danger);"><?= (int) $sh['recent_errors'] ?></strong>
                </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($jira['error_message'])): ?>
                <div class="alert alert-warning" style="margin-bottom: 1rem;">
                    <strong>Last error:</strong> <?= htmlspecialchars($jira['error_message']) ?>
                    (<?= (int) $jira['error_count'] ?> consecutive errors)
                </div>
            <?php endif; ?>

            <!-- Action buttons -->
            <div style="display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; margin-top: 1rem; padding-top: 1rem; border-top: 1px solid var(--border);">
                <a href="/app/admin/integrations/jira/configure" class="btn btn-sm btn-secondary">Configure</a>

                <form method="POST" action="/app/admin/integrations/jira/push" class="inline-form"
                      data-loading="Pushing to Jira..."
                      data-overlay="Pushing work items and user stories to Jira. This may take a moment."
                      style="display: flex; align-items: center; gap: 0.375rem;">
                    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <select name="project_id" class="form-control" style="font-size:0.8125rem; padding:0.25rem 0.5rem; min-width:150px;" required>
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
                            onclick="return this.form.project_id.value ? confirm('Push work items and stories to Jira?') : (alert('Select a project first'), false)">
                        Push
                    </button>
                </form>

                <form method="POST" action="/app/admin/integrations/jira/pull" class="inline-form"
                      data-loading="Pulling from Jira...">
                    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <input type="hidden" name="project_id" value="<?= (int) ($_SESSION['_last_project_id'] ?? 0) ?>">
                    <button type="submit" class="btn btn-sm btn-secondary"
                            onclick="return confirm('Pull changes from Jira?')">
                        Pull Changes
                    </button>
                </form>

                <a href="/app/admin/integrations/sync-log" class="btn btn-sm btn-secondary">Sync Log</a>

                <div style="margin-left: auto;">
                    <form method="POST" action="/app/admin/integrations/jira/disconnect" class="inline-form">
                        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        <button type="submit" class="btn btn-sm btn-danger"
                                onclick="return confirm('Disconnect Jira Cloud? Sync mappings will be preserved.')">
                            Disconnect
                        </button>
                    </form>
                </div>
            </div>
        <?php else: ?>
            <!-- Disconnected state -->
            <p class="text-muted" style="margin-bottom: 1rem;">
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
     GitHub Webhook Integration
     =========================== -->
<section class="card mt-4">
    <div class="card-header" style="display: flex; align-items: center; justify-content: space-between;">
        <div>
            <h2 class="card-title" style="margin: 0;">GitHub</h2>
            <small class="text-muted">Auto-link PRs to user stories and work items via webhook</small>
        </div>
        <div>
            <?php if ($githubActive): ?>
                <span class="badge badge-success" style="font-size: 0.85rem; padding: 4px 12px;">Connected</span>
            <?php else: ?>
                <span class="badge badge-secondary" style="font-size: 0.85rem; padding: 4px 12px;">Disconnected</span>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body">
        <?php if ($githubActive): ?>
            <!-- Connected state -->
            <div style="margin-bottom: 1rem;">
                <span class="text-muted" style="font-size: 0.8rem; display: block; margin-bottom: 0.25rem;">Webhook URL</span>
                <code style="font-size: 0.85rem; word-break: break-all;"><?= htmlspecialchars(($_SERVER['HTTP_HOST'] ?? '') ? 'https://' . $_SERVER['HTTP_HOST'] . '/webhook/git/github' : '/webhook/git/github') ?></code>
            </div>

            <div style="margin-bottom: 1rem;">
                <span class="text-muted" style="font-size: 0.8rem; display: block; margin-bottom: 0.25rem;">Webhook Secret</span>
                <?php $ghSecret = $githubConfig['webhook_secret'] ?? ''; ?>
                <?php if ($ghSecret): ?>
                    <div style="display: flex; align-items: center; gap: 0.5rem;">
                        <code id="github-secret-display" style="font-size: 0.85rem; letter-spacing: 0.05em;">
                            <?= str_repeat('*', max(0, strlen($ghSecret) - 4)) . htmlspecialchars(substr($ghSecret, -4)) ?>
                        </code>
                        <button type="button" class="btn btn-sm btn-secondary"
                                onclick="toggleGitSecret('github', <?= htmlspecialchars(json_encode($ghSecret), ENT_QUOTES) ?>)"
                                id="github-secret-reveal-btn">Reveal</button>
                    </div>
                <?php else: ?>
                    <span class="text-muted">No secret set.</span>
                <?php endif; ?>
            </div>

            <div style="display: flex; gap: 0.5rem; flex-wrap: wrap; padding-top: 1rem; border-top: 1px solid var(--border);">
                <form method="POST" action="/app/admin/integrations/git/github/regenerate-secret" class="inline-form">
                    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <button type="submit" class="btn btn-sm btn-secondary"
                            onclick="return confirm('Regenerate GitHub webhook secret? You must update your repository webhook settings with the new secret.')">
                        Regenerate Secret
                    </button>
                </form>

                <div style="margin-left: auto;">
                    <form method="POST" action="/app/admin/integrations/git/github/disconnect" class="inline-form">
                        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        <button type="submit" class="btn btn-sm btn-danger"
                                onclick="return confirm('Disconnect GitHub webhook?')">
                            Disconnect
                        </button>
                    </form>
                </div>
            </div>
        <?php else: ?>
            <!-- Disconnected state -->
            <p class="text-muted" style="margin-bottom: 1rem;">
                Connect a GitHub webhook to automatically link pull requests to user stories and work items.
                Include <code>SF-{id}</code> or <code>StratFlow-{id}</code> in your PR description.
            </p>
            <form method="POST" action="/app/admin/integrations/git/github/connect" class="inline-form">
                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <button type="submit" class="btn btn-primary">Connect GitHub</button>
            </form>
        <?php endif; ?>
    </div>
</section>

<!-- ===========================
     GitLab Webhook Integration
     =========================== -->
<section class="card mt-4">
    <div class="card-header" style="display: flex; align-items: center; justify-content: space-between;">
        <div>
            <h2 class="card-title" style="margin: 0;">GitLab</h2>
            <small class="text-muted">Auto-link merge requests to user stories and work items via webhook</small>
        </div>
        <div>
            <?php if ($gitlabActive): ?>
                <span class="badge badge-success" style="font-size: 0.85rem; padding: 4px 12px;">Connected</span>
            <?php else: ?>
                <span class="badge badge-secondary" style="font-size: 0.85rem; padding: 4px 12px;">Disconnected</span>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body">
        <?php if ($gitlabActive): ?>
            <!-- Connected state -->
            <div style="margin-bottom: 1rem;">
                <span class="text-muted" style="font-size: 0.8rem; display: block; margin-bottom: 0.25rem;">Webhook URL</span>
                <code style="font-size: 0.85rem; word-break: break-all;"><?= htmlspecialchars(($_SERVER['HTTP_HOST'] ?? '') ? 'https://' . $_SERVER['HTTP_HOST'] . '/webhook/git/gitlab' : '/webhook/git/gitlab') ?></code>
            </div>

            <div style="margin-bottom: 1rem;">
                <span class="text-muted" style="font-size: 0.8rem; display: block; margin-bottom: 0.25rem;">Webhook Secret Token</span>
                <?php $glSecret = $gitlabConfig['webhook_secret'] ?? ''; ?>
                <?php if ($glSecret): ?>
                    <div style="display: flex; align-items: center; gap: 0.5rem;">
                        <code id="gitlab-secret-display" style="font-size: 0.85rem; letter-spacing: 0.05em;">
                            <?= str_repeat('*', max(0, strlen($glSecret) - 4)) . htmlspecialchars(substr($glSecret, -4)) ?>
                        </code>
                        <button type="button" class="btn btn-sm btn-secondary"
                                onclick="toggleGitSecret('gitlab', <?= htmlspecialchars(json_encode($glSecret), ENT_QUOTES) ?>)"
                                id="gitlab-secret-reveal-btn">Reveal</button>
                    </div>
                <?php else: ?>
                    <span class="text-muted">No secret set.</span>
                <?php endif; ?>
            </div>

            <div style="display: flex; gap: 0.5rem; flex-wrap: wrap; padding-top: 1rem; border-top: 1px solid var(--border);">
                <form method="POST" action="/app/admin/integrations/git/gitlab/regenerate-secret" class="inline-form">
                    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <button type="submit" class="btn btn-sm btn-secondary"
                            onclick="return confirm('Regenerate GitLab webhook secret? You must update your repository webhook settings with the new secret token.')">
                        Regenerate Secret
                    </button>
                </form>

                <div style="margin-left: auto;">
                    <form method="POST" action="/app/admin/integrations/git/gitlab/disconnect" class="inline-form">
                        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        <button type="submit" class="btn btn-sm btn-danger"
                                onclick="return confirm('Disconnect GitLab webhook?')">
                            Disconnect
                        </button>
                    </form>
                </div>
            </div>
        <?php else: ?>
            <!-- Disconnected state -->
            <p class="text-muted" style="margin-bottom: 1rem;">
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

<script>
/**
 * Toggle reveal/mask of a Git webhook secret.
 *
 * @param {string} provider 'github' or 'gitlab'
 * @param {string} secret   Plain-text secret value (never written to DOM until revealed)
 */
function toggleGitSecret(provider, secret) {
    var display = document.getElementById(provider + '-secret-display');
    var btn     = document.getElementById(provider + '-secret-reveal-btn');
    if (!display || !btn) { return; }

    if (btn.textContent === 'Reveal') {
        display.textContent = secret;
        btn.textContent     = 'Hide';
    } else {
        var masked = secret.slice(-4).padStart(secret.length, '*');
        display.textContent = masked;
        btn.textContent     = 'Reveal';
    }
}
</script>

<!-- ===========================
     Azure DevOps (Coming Soon)
     =========================== -->
<section class="card mt-4" style="opacity: 0.6;">
    <div class="card-header" style="display: flex; align-items: center; justify-content: space-between;">
        <div>
            <h2 class="card-title" style="margin: 0;">Azure DevOps</h2>
            <small class="text-muted">Sync with Azure DevOps boards and work items</small>
        </div>
        <div>
            <span class="badge badge-secondary" style="font-size: 0.85rem; padding: 4px 12px;">Coming Soon</span>
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
