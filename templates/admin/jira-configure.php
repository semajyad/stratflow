<?php
/**
 * Jira Configuration Template
 *
 * Configure the Jira project to sync with, view field mappings,
 * and test the connection.
 *
 * Variables: $user (array), $integration (array), $jira_projects (array),
 *            $current_config (array), $error (string|null), $csrf_token (string)
 */
?>

<!-- ===========================
     Page Header
     =========================== -->
<div class="page-header">
    <h1 class="page-title">Jira Configuration</h1>
    <p class="page-subtitle">
        <a href="/app/admin/integrations">&larr; Back to Integrations</a>
    </p>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger" style="margin-bottom: 1rem;">
        <?= htmlspecialchars($error) ?>
    </div>
<?php endif; ?>

<!-- ===========================
     Project Selection
     =========================== -->
<form method="POST" action="/app/admin/integrations/jira/configure">
    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

    <section class="card">
        <div class="card-header">
            <h2 class="card-title">Jira Project</h2>
        </div>
        <div class="card-body">
            <p class="text-muted mb-4" style="font-size: 0.875rem;">
                Select the Jira project where StratFlow items will be synced.
                Work items will be created as Epics and user stories as Stories.
            </p>

            <div class="form-group mb-4">
                <label class="form-label">Project</label>
                <?php if (!empty($jira_projects)): ?>
                    <select name="jira_project_key" class="form-input" style="max-width: 400px;">
                        <option value="">Select a project...</option>
                        <?php foreach ($jira_projects as $jp): ?>
                            <option value="<?= htmlspecialchars($jp['key'] ?? '') ?>"
                                    <?= ($current_config['project_key'] ?? '') === ($jp['key'] ?? '') ? 'selected' : '' ?>>
                                <?= htmlspecialchars(($jp['key'] ?? '') . ' - ' . ($jp['name'] ?? '')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted"><?= count($jira_projects) ?> project<?= count($jira_projects) !== 1 ? 's' : '' ?> found</small>
                <?php else: ?>
                    <p class="text-muted">No projects found. Ensure the connected Jira account has project access.</p>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- ===========================
         Field Mapping (Read-only)
         =========================== -->
    <section class="card mt-4">
        <div class="card-header">
            <h2 class="card-title">Field Mapping</h2>
        </div>
        <div class="card-body">
            <p class="text-muted mb-4" style="font-size: 0.875rem;">
                Default field mapping between StratFlow and Jira. Custom mapping will be available in a future release.
            </p>

            <table class="table" style="max-width: 600px;">
                <thead>
                    <tr>
                        <th>StratFlow</th>
                        <th>Jira</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>HL Work Item</td>
                        <td>Epic</td>
                    </tr>
                    <tr>
                        <td>Work Item Title</td>
                        <td>Summary</td>
                    </tr>
                    <tr>
                        <td>Work Item Description + OKR</td>
                        <td>Description (ADF)</td>
                    </tr>
                    <tr>
                        <td>Priority Number</td>
                        <td>Priority (Highest/High/Medium/Low/Lowest)</td>
                    </tr>
                    <tr>
                        <td>User Story</td>
                        <td>Story (linked to parent Epic)</td>
                    </tr>
                    <tr>
                        <td>Story Size</td>
                        <td>Story Points</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>

    <!-- ===========================
         Connection Info
         =========================== -->
    <section class="card mt-4">
        <div class="card-header">
            <h2 class="card-title">Connection Details</h2>
        </div>
        <div class="card-body">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div>
                    <span class="text-muted" style="font-size: 0.8rem; display: block;">Site Name</span>
                    <strong><?= htmlspecialchars($integration['display_name'] ?? '') ?></strong>
                </div>
                <div>
                    <span class="text-muted" style="font-size: 0.8rem; display: block;">Site URL</span>
                    <a href="<?= htmlspecialchars($integration['site_url'] ?? '') ?>" target="_blank" rel="noopener">
                        <?= htmlspecialchars($integration['site_url'] ?? '') ?>
                    </a>
                </div>
                <div>
                    <span class="text-muted" style="font-size: 0.8rem; display: block;">Cloud ID</span>
                    <code style="font-size: 0.85rem;"><?= htmlspecialchars($integration['cloud_id'] ?? '') ?></code>
                </div>
                <div>
                    <span class="text-muted" style="font-size: 0.8rem; display: block;">Token Expires</span>
                    <span><?= htmlspecialchars($integration['token_expires_at'] ?? 'Unknown') ?></span>
                </div>
            </div>
        </div>
    </section>

    <!-- ===========================
         Save Button
         =========================== -->
    <div class="mt-4 mb-6">
        <button type="submit" class="btn btn-primary">Save Configuration</button>
    </div>
</form>
