<?php
/**
 * Integration Hub Template
 *
 * Lists all available integrations (Jira Cloud, Azure DevOps) with
 * connection status, sync controls, and configuration links.
 *
 * Variables: $user (array), $integrations (array keyed by provider),
 *            $jira_sync_count (int), $csrf_token (string)
 */

$jira = $integrations['jira'] ?? null;
$jiraActive = $jira && $jira['status'] === 'active';
$jiraConfig = $jira ? (json_decode($jira['config_json'] ?? '{}', true) ?: []) : [];
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
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem; margin-bottom: 1.5rem;">
                <div>
                    <span class="text-muted" style="font-size: 0.8rem; display: block;">Site</span>
                    <strong><?= htmlspecialchars($jira['display_name'] ?? '') ?></strong>
                    <?php if (!empty($jira['site_url'])): ?>
                        <br><a href="<?= htmlspecialchars($jira['site_url']) ?>" target="_blank" rel="noopener" style="font-size: 0.85rem;"><?= htmlspecialchars($jira['site_url']) ?></a>
                    <?php endif; ?>
                </div>
                <div>
                    <span class="text-muted" style="font-size: 0.8rem; display: block;">Last Sync</span>
                    <strong><?= $jira['last_sync_at'] ? htmlspecialchars($jira['last_sync_at']) : 'Never' ?></strong>
                </div>
                <div>
                    <span class="text-muted" style="font-size: 0.8rem; display: block;">Synced Items</span>
                    <strong><?= (int) $jira_sync_count ?></strong>
                    <?php if (!empty($jiraConfig['project_key'])): ?>
                        <br><span class="text-muted" style="font-size: 0.85rem;">Project: <?= htmlspecialchars($jiraConfig['project_key']) ?></span>
                    <?php endif; ?>
                </div>
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
