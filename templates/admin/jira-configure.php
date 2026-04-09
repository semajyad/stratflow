<?php
/**
 * Jira Configuration Template
 *
 * Configure the Jira project, field mappings, and board selection.
 *
 * Variables: $user, $integration, $jira_projects, $jira_fields,
 *            $jira_issue_types, $jira_boards, $current_config,
 *            $error, $csrf_token
 */
$fm = $current_config['field_mapping'] ?? [];
?>

<div class="page-header">
    <h1 class="page-title">Jira Configuration</h1>
    <p class="page-subtitle">
        <a href="/app/admin/integrations">&larr; Back to Integrations</a>
    </p>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger" style="margin-bottom: 1rem;"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<form method="POST" action="/app/admin/integrations/jira/configure">
    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

    <!-- Project Selection -->
    <section class="card">
        <div class="card-header"><h2 class="card-title">Jira Project</h2></div>
        <div class="card-body">
            <p class="text-muted mb-4" style="font-size: 0.875rem;">
                Select the Jira project where StratFlow items will be synced.
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

    <!-- Board Selection -->
    <?php if (!empty($jira_boards)): ?>
    <section class="card mt-4">
        <div class="card-header"><h2 class="card-title">Scrum Board</h2></div>
        <div class="card-body">
            <p class="text-muted mb-4" style="font-size: 0.875rem;">
                Select the board used for sprint management. Sprints will be created on this board.
            </p>
            <div class="form-group">
                <label class="form-label">Board</label>
                <select name="board_id" class="form-input" style="max-width: 400px;">
                    <option value="0">Auto-detect</option>
                    <?php foreach ($jira_boards as $board): ?>
                        <option value="<?= (int) $board['id'] ?>"
                                <?= ((int) ($fm['board_id'] ?? 0)) === (int) $board['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($board['name'] ?? 'Board ' . $board['id']) ?>
                            (<?= htmlspecialchars($board['type'] ?? '') ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- Issue Type Mapping -->
    <section class="card mt-4">
        <div class="card-header"><h2 class="card-title">Issue Type Mapping</h2></div>
        <div class="card-body">
            <p class="text-muted mb-4" style="font-size: 0.875rem;">
                Map StratFlow item types to Jira issue types. The defaults work for most Scrum projects.
            </p>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; max-width: 600px;">
                <div class="form-group">
                    <label class="form-label">Work Items create as</label>
                    <?php if (!empty($jira_issue_types)): ?>
                        <select name="epic_type" class="form-input">
                            <?php foreach ($jira_issue_types as $it): ?>
                                <?php if (!($it['subtask'] ?? false)): ?>
                                <option value="<?= htmlspecialchars($it['name']) ?>"
                                        <?= ($fm['epic_type'] ?? 'Epic') === $it['name'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($it['name']) ?>
                                </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <input type="text" name="epic_type" class="form-input" value="<?= htmlspecialchars($fm['epic_type'] ?? 'Epic') ?>">
                    <?php endif; ?>
                </div>
                <div class="form-group">
                    <label class="form-label">User Stories create as</label>
                    <?php if (!empty($jira_issue_types)): ?>
                        <select name="story_type" class="form-input">
                            <?php foreach ($jira_issue_types as $it): ?>
                                <?php if (!($it['subtask'] ?? false)): ?>
                                <option value="<?= htmlspecialchars($it['name']) ?>"
                                        <?= ($fm['story_type'] ?? 'Story') === $it['name'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($it['name']) ?>
                                </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <input type="text" name="story_type" class="form-input" value="<?= htmlspecialchars($fm['story_type'] ?? 'Story') ?>">
                    <?php endif; ?>
                </div>
                <div class="form-group">
                    <label class="form-label">Risks create as</label>
                    <?php if (!empty($jira_issue_types)): ?>
                        <select name="risk_type" class="form-input">
                            <?php foreach ($jira_issue_types as $it): ?>
                                <?php if (!($it['subtask'] ?? false)): ?>
                                <option value="<?= htmlspecialchars($it['name']) ?>"
                                        <?= ($fm['risk_type'] ?? 'Risk') === $it['name'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($it['name']) ?>
                                </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <input type="text" name="risk_type" class="form-input" value="<?= htmlspecialchars($fm['risk_type'] ?? 'Risk') ?>">
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>

    <!-- Custom Field Mapping -->
    <section class="card mt-4">
        <div class="card-header"><h2 class="card-title">Custom Field Mapping</h2></div>
        <div class="card-body">
            <p class="text-muted mb-4" style="font-size: 0.875rem;">
                Map StratFlow data to Jira custom fields. These vary by Jira instance — select the correct fields for your setup.
            </p>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; max-width: 700px;">
                <div class="form-group">
                    <label class="form-label">Epic Name Field</label>
                    <?php if (!empty($jira_fields)): ?>
                        <select name="epic_name_field" class="form-input">
                            <option value="">None (not required for team-managed)</option>
                            <?php foreach ($jira_fields as $f): ?>
                                <option value="<?= htmlspecialchars($f['id']) ?>"
                                        <?= ($fm['epic_name_field'] ?? 'customfield_10011') === $f['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($f['name']) ?> (<?= htmlspecialchars($f['id']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <input type="text" name="epic_name_field" class="form-input" value="<?= htmlspecialchars($fm['epic_name_field'] ?? 'customfield_10011') ?>" placeholder="customfield_10011">
                    <?php endif; ?>
                    <small class="text-muted">Required for company-managed projects. Leave empty for team-managed.</small>
                </div>
                <div class="form-group">
                    <label class="form-label">Story Points Field</label>
                    <?php if (!empty($jira_fields)): ?>
                        <select name="story_points_field" class="form-input">
                            <option value="">None</option>
                            <?php foreach ($jira_fields as $f): ?>
                                <option value="<?= htmlspecialchars($f['id']) ?>"
                                        <?= ($fm['story_points_field'] ?? 'customfield_10016') === $f['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($f['name']) ?> (<?= htmlspecialchars($f['id']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <input type="text" name="story_points_field" class="form-input" value="<?= htmlspecialchars($fm['story_points_field'] ?? 'customfield_10016') ?>" placeholder="customfield_10016">
                    <?php endif; ?>
                    <small class="text-muted">Usually "Story point estimate" (customfield_10016).</small>
                </div>
                <div class="form-group">
                    <label class="form-label">Team Field</label>
                    <?php if (!empty($jira_fields)): ?>
                        <select name="team_field" class="form-input">
                            <option value="">None</option>
                            <?php foreach ($jira_fields as $f): ?>
                                <option value="<?= htmlspecialchars($f['id']) ?>"
                                        <?= ($fm['team_field'] ?? 'customfield_10001') === $f['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($f['name']) ?> (<?= htmlspecialchars($f['id']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <input type="text" name="team_field" class="form-input" value="<?= htmlspecialchars($fm['team_field'] ?? 'customfield_10001') ?>" placeholder="customfield_10001">
                    <?php endif; ?>
                    <small class="text-muted">Maps the "Team" field for team assignment on stories.</small>
                </div>
            </div>
        </div>
    </section>

    <!-- Priority Mapping -->
    <?php $pr = $fm['priority_ranges'] ?? []; ?>
    <section class="card mt-4">
        <div class="card-header"><h2 class="card-title">Priority Mapping</h2></div>
        <div class="card-body">
            <p class="text-muted mb-4" style="font-size: 0.875rem;">
                Map StratFlow priority numbers (1-10) to Jira priority levels. Items with priority &le; the threshold get that level.
            </p>
            <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; max-width: 500px;">
                <div class="form-group">
                    <label class="form-label">Highest (&le;)</label>
                    <input type="number" name="priority_highest" class="form-input" value="<?= (int) ($pr['highest'] ?? 2) ?>" min="1" max="10">
                </div>
                <div class="form-group">
                    <label class="form-label">High (&le;)</label>
                    <input type="number" name="priority_high" class="form-input" value="<?= (int) ($pr['high'] ?? 4) ?>" min="1" max="10">
                </div>
                <div class="form-group">
                    <label class="form-label">Medium (&le;)</label>
                    <input type="number" name="priority_medium" class="form-input" value="<?= (int) ($pr['medium'] ?? 6) ?>" min="1" max="10">
                </div>
                <div class="form-group">
                    <label class="form-label">Low (&le;)</label>
                    <input type="number" name="priority_low" class="form-input" value="<?= (int) ($pr['low'] ?? 8) ?>" min="1" max="10">
                </div>
            </div>
            <small class="text-muted">Items above the Low threshold map to Lowest.</small>
        </div>
    </section>

    <!-- Connection Details -->
    <section class="card mt-4">
        <div class="card-header"><h2 class="card-title">Connection Details</h2></div>
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

    <div class="mt-4 mb-6">
        <button type="submit" class="btn btn-primary">Save Configuration</button>
    </div>
</form>
