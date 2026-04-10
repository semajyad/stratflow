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
     GitHub App Integration
     =========================== -->
<?php if (($settings['feature_github'] ?? true) || $hasGithub): ?>
<section class="card mt-4">
    <div class="card-header" style="display: flex; align-items: center; justify-content: space-between;">
        <div>
            <h2 class="card-title" style="margin: 0;">GitHub</h2>
            <small class="text-muted">Auto-link pull requests to user stories via GitHub App — no copy/paste required</small>
        </div>
        <div>
            <?php if ($hasGithub): ?>
                <span class="badge badge-success" style="font-size: 0.85rem; padding: 4px 12px;">
                    <?= count($githubInstalls) ?> account<?= count($githubInstalls) === 1 ? '' : 's' ?> connected
                </span>
            <?php else: ?>
                <span class="badge badge-secondary" style="font-size: 0.85rem; padding: 4px 12px;">Not connected</span>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body">
        <?php if ($hasGithub): ?>
            <!-- Connected state — list each GitHub account installation -->
            <table style="width: 100%; border-collapse: collapse; margin-bottom: 1rem;">
                <thead>
                    <tr style="border-bottom: 1px solid var(--border);">
                        <th style="text-align: left; padding: 0.4rem 0.5rem; font-size: 0.8rem; color: var(--text-muted); font-weight: 500; text-transform: uppercase; letter-spacing: 0.04em;">Account</th>
                        <th style="text-align: left; padding: 0.4rem 0.5rem; font-size: 0.8rem; color: var(--text-muted); font-weight: 500; text-transform: uppercase; letter-spacing: 0.04em;">Repos</th>
                        <th style="text-align: right; padding: 0.4rem 0.5rem;"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($githubInstalls as $install): ?>
                    <tr style="border-bottom: 1px solid var(--border);">
                        <td style="padding: 0.6rem 0.5rem;">
                            <strong>@<?= htmlspecialchars($install['account_login'] ?? '') ?></strong>
                        </td>
                        <td style="padding: 0.6rem 0.5rem;">
                            <?php
                                $repoCount = (int) $install['repo_count'];
                                $repoNames = $install['repo_names'] ?? [];
                            ?>
                            <?php if ($repoCount > 0 && !empty($repoNames)): ?>
                            <span class="repo-count-badge" tabindex="0"
                                  style="display: inline-flex; align-items: center; gap: 0.25rem; cursor: default;
                                         background: var(--primary-light, #e8f0fe); color: var(--primary, #1a73e8);
                                         border-radius: 999px; padding: 2px 10px; font-size: 0.8rem; font-weight: 600;
                                         position: relative; user-select: none;">
                                <?= $repoCount ?> repo<?= $repoCount === 1 ? '' : 's' ?>
                                <span class="repo-tooltip"
                                      style="display: none; position: absolute; top: calc(100% + 6px); left: 0;
                                             min-width: 220px; max-width: 340px; background: #1e2330; color: #e2e8f0;
                                             border-radius: 6px; padding: 0.5rem 0.75rem; font-size: 0.8rem;
                                             font-weight: 400; line-height: 1.6; z-index: 100;
                                             box-shadow: 0 4px 16px rgba(0,0,0,0.25); white-space: nowrap;">
                                    <?php foreach ($repoNames as $rn): ?>
                                        <span style="display: block;"><?= htmlspecialchars($rn) ?></span>
                                    <?php endforeach; ?>
                                </span>
                            </span>
                            <?php else: ?>
                            <span style="color: var(--text-muted); font-size: 0.85rem;">0 repos</span>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 0.6rem 0.5rem; text-align: right; white-space: nowrap;">
                            <?php if ($install['installation_id']): ?>
                            <a href="https://github.com/settings/installations/<?= (int) $install['installation_id'] ?>"
                               target="_blank" rel="noopener noreferrer"
                               class="btn btn-sm btn-secondary" style="margin-right: 0.25rem;">
                                Manage on GitHub
                            </a>
                            <?php endif; ?>
                            <form method="POST"
                                  action="/app/admin/integrations/github/<?= (int) $install['id'] ?>/disconnect"
                                  class="inline-form" style="display: inline;">
                                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                <button type="submit" class="btn btn-sm btn-danger"
                                        data-account="<?= htmlspecialchars($install['account_login'] ?? '') ?>"
                                        onclick="return confirm('Disconnect @' + this.dataset.account + '? PR links already created will be preserved.')">
                                    Disconnect
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <p class="text-muted" style="font-size: 0.85rem; margin-bottom: 1rem;">
                To add or remove repos from an installation, use the <em>Manage on GitHub</em> link above —
                stratflow will sync the change automatically. Project managers can then subscribe their
                projects to specific repos from the project settings page.
            </p>

            <a href="/app/admin/integrations/github/install" class="btn btn-sm btn-secondary">
                + Add another GitHub account
            </a>

        <?php else: ?>
            <!-- Disconnected state -->
            <p class="text-muted" style="margin-bottom: 1rem;">
                Install the StratFlow GitHub App to automatically link pull requests to user stories.
                GitHub handles the repository picker — no webhook URLs or secrets to copy.
                Include <code>SF-{id}</code> or <code>StratFlow-{id}</code> in your PR description.
            </p>
            <?php if (($github_app_slug ?? '') !== ''): ?>
            <a href="/app/admin/integrations/github/install" class="btn btn-primary">
                Install GitHub App
            </a>
            <?php else: ?>
            <p class="text-muted" style="font-size: 0.85rem;">
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
                <?php
                    $glSecret = $gitlabConfig['webhook_secret'] ?? '';
                    $glMasked = $glSecret !== ''
                        ? str_repeat('*', max(0, strlen($glSecret) - 4)) . substr($glSecret, -4)
                        : '';
                ?>
                <?php if ($glSecret !== ''): ?>
                    <div style="display: flex; align-items: center; gap: 0.5rem;">
                        <code id="gitlab-secret-display"
                              data-masked="<?= htmlspecialchars($glMasked) ?>"
                              style="font-size: 0.85rem; letter-spacing: 0.05em;"><?= htmlspecialchars($glMasked) ?></code>
                        <button type="button" class="btn btn-sm btn-secondary"
                                onclick="toggleGitSecret('gitlab')"
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

<?php endif; ?>

<script>
// Repo count badge hover tooltip
document.querySelectorAll('.repo-count-badge').forEach(function(badge) {
    var tip = badge.querySelector('.repo-tooltip');
    if (!tip) { return; }
    badge.addEventListener('mouseenter', function() { tip.style.display = 'block'; });
    badge.addEventListener('mouseleave', function() { tip.style.display = 'none'; });
    badge.addEventListener('focus',      function() { tip.style.display = 'block'; });
    badge.addEventListener('blur',       function() { tip.style.display = 'none'; });
});

/**
 * Toggle reveal/mask of a Git webhook secret.
 *
 * The plaintext secret is NOT embedded in the HTML source. On reveal, it
 * is fetched from a CSRF-protected admin endpoint and written to the DOM
 * only after the user explicitly clicks Reveal. On hide, we restore the
 * masked value from the element's data-masked attribute and drop the
 * plaintext from the DOM.
 *
 * @param {string} provider 'github' or 'gitlab'
 */
function toggleGitSecret(provider) {
    var display = document.getElementById(provider + '-secret-display');
    var btn     = document.getElementById(provider + '-secret-reveal-btn');
    if (!display || !btn) { return; }

    if (btn.textContent === 'Hide') {
        display.textContent = display.getAttribute('data-masked') || '';
        btn.textContent     = 'Reveal';
        return;
    }

    btn.disabled    = true;
    btn.textContent = 'Loading...';

    var form = new FormData();
    form.append('_csrf_token', '<?= htmlspecialchars($csrf_token) ?>');

    fetch('/app/admin/integrations/git/' + encodeURIComponent(provider) + '/reveal-secret', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: form,
        credentials: 'same-origin'
    })
    .then(function(r) { return r.json().then(function(d) { return { ok: r.ok, data: d }; }); })
    .then(function(res) {
        btn.disabled = false;
        if (res.ok && res.data && res.data.secret) {
            display.textContent = res.data.secret;
            btn.textContent     = 'Hide';
        } else {
            btn.textContent = 'Reveal';
            alert((res.data && res.data.error) || 'Could not reveal secret.');
        }
    })
    .catch(function() {
        btn.disabled    = false;
        btn.textContent = 'Reveal';
        alert('Network error revealing secret.');
    });
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
