#!/usr/bin/env php
<?php
/**
 * Scheduled Jira Sync Script
 *
 * Runs bidirectional sync for all active Jira integrations that have
 * a configured project. Should be called via cron or Railway scheduled task.
 *
 * Usage: php scripts/scheduled-sync.php
 * Recommended: every 15-30 minutes
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

$config = require __DIR__ . '/../src/Config/config.php';

$db = new \StratFlow\Core\Database(
    $config['db']['host'],
    (int) $config['db']['port'],
    $config['db']['database'],
    $config['db']['username'],
    $config['db']['password']
);

echo "StratFlow Scheduled Sync — " . date('Y-m-d H:i:s') . "\n";

// Find all active Jira integrations
$stmt = $db->query("SELECT * FROM integrations WHERE provider = 'jira' AND status = 'active'");
$integrations = $stmt->fetchAll();

echo "Found " . count($integrations) . " active integration(s).\n";

foreach ($integrations as $integration) {
    $orgId = (int) $integration['org_id'];
    $intConfig = json_decode($integration['config_json'] ?? '{}', true) ?: [];
    $projectKey = $intConfig['project_key'] ?? '';

    if (!$projectKey) {
        echo "  Org #{$orgId}: No project key configured, skipping.\n";
        continue;
    }

    echo "  Org #{$orgId} ({$projectKey}): ";

    try {
        $jira = new \StratFlow\Services\JiraService($config['jira'] ?? [], $integration, $db);
        $sync = new \StratFlow\Services\JiraSyncService($db, $jira, $integration);

        // Find the project linked to this Jira key
        $stmt = $db->query(
            "SELECT id FROM projects WHERE jira_project_key = :key AND org_id = :org_id LIMIT 1",
            [':key' => $projectKey, ':org_id' => $orgId]
        );
        $project = $stmt->fetch();

        if (!$project) {
            echo "No linked project found, skipping.\n";
            continue;
        }

        $projectId = (int) $project['id'];

        // Push then pull
        $wiResult = $sync->pushWorkItems($projectId, $projectKey);
        $usResult = $sync->pushUserStories($projectId, $projectKey);
        $rkResult = $sync->pushRisks($projectId, $projectKey);
        $pullResult = $sync->pullChanges($projectId, $projectKey);

        $totalPushed = $wiResult['created'] + $usResult['created'] + $rkResult['created'];
        $totalUpdated = $wiResult['updated'] + $usResult['updated'] + $rkResult['updated'];
        $totalPulled = $pullResult['created'] + $pullResult['updated'];
        $totalErrors = $wiResult['errors'] + $usResult['errors'] + $rkResult['errors'] + $pullResult['errors'];

        echo "push={$totalPushed} created, {$totalUpdated} updated; pull={$totalPulled}; errors={$totalErrors}\n";

        \StratFlow\Models\Integration::update($db, (int) $integration['id'], [
            'last_sync_at' => date('Y-m-d H:i:s'),
        ]);

        // Log the sync
        \StratFlow\Models\SyncLog::create($db, [
            'integration_id' => (int) $integration['id'],
            'direction'      => 'push',
            'action'         => 'update',
            'local_type'     => null,
            'local_id'       => null,
            'external_id'    => null,
            'details_json'   => json_encode([
                'scheduled' => true,
                'pushed' => $totalPushed,
                'updated' => $totalUpdated,
                'pulled' => $totalPulled,
                'errors' => $totalErrors,
            ]),
            'status' => $totalErrors > 0 ? 'error' : 'success',
        ]);

    } catch (\Throwable $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
        \StratFlow\Models\Integration::recordError($db, (int) $integration['id'], $e->getMessage());
    }
}

echo "\nScheduled sync complete.\n";
