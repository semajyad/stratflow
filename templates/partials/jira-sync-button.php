<?php
// Jira sync button — shown only when project has a Jira link and integration is active
// Expects: $project, $csrf_token, $sync_type ('work_items' or 'user_stories')
try {
    $jiraKey = $project['jira_project_key'] ?? '';
    if ($jiraKey !== '') {
        $integration = \StratFlow\Models\Integration::findByOrgAndProvider(
            \StratFlow\Core\Database::getInstance(),
            (int) ($project['org_id'] ?? 0),
            'jira'
        );
        if ($integration && $integration['status'] === 'active') {
?>
<form method="POST" action="/app/jira/sync" class="inline-form jira-sync-form"
      data-project-id="<?= (int) $project['id'] ?>"
      data-sync-type="<?= htmlspecialchars($sync_type ?? 'all') ?>"
      data-jira-key="<?= htmlspecialchars($jiraKey) ?>"
      data-loading="Syncing to Jira..."
      data-overlay="Syncing to Jira project <?= htmlspecialchars($jiraKey) ?>. This may take a moment.">
    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
    <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
    <input type="hidden" name="sync_type" value="<?= htmlspecialchars($sync_type ?? 'all') ?>">
    <button type="button" class="btn btn-sm btn-secondary js-show-jira-preview gen-style-5cca83">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M7 16V4m0 0L3 8m4-4l4 4M17 8v12m0 0l4-4m-4 4l-4-4"/></svg>
        Sync to Jira (<?= htmlspecialchars($jiraKey) ?>)
    </button>
</form>
<?php
        }
    }
} catch (\Throwable $e) {
    // Silently skip — don't break the page if Jira check fails
    error_log('Jira sync button error: ' . $e->getMessage());
}
?>
