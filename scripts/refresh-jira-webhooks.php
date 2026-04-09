#!/usr/bin/env php
<?php
/**
 * Jira Webhook Refresh Script
 *
 * Jira webhooks expire after 30 days. This script refreshes all active
 * integrations' webhooks. Should be run every 25 days via cron or
 * Railway scheduled task.
 *
 * Usage: php scripts/refresh-jira-webhooks.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

$config = require __DIR__ . '/../src/Config/config.php';

$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    $config['db']['host'],
    $config['db']['port'],
    $config['db']['database']
);

try {
    $pdo = new PDO($dsn, $config['db']['username'], $config['db']['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    echo "Connected to database.\n";
} catch (PDOException $e) {
    echo "Database error: " . $e->getMessage() . "\n";
    exit(1);
}

// Use the application's Database class
$db = new \StratFlow\Core\Database(
    $config['db']['host'],
    (int) $config['db']['port'],
    $config['db']['database'],
    $config['db']['username'],
    $config['db']['password']
);

// Find all active Jira integrations
$stmt = $db->query(
    "SELECT * FROM integrations WHERE provider = 'jira' AND status = 'active'"
);
$integrations = $stmt->fetchAll();

echo "Found " . count($integrations) . " active Jira integration(s).\n";

foreach ($integrations as $integration) {
    $orgId = (int) $integration['org_id'];
    echo "\nOrg #{$orgId}: ";

    try {
        $jiraService = new \StratFlow\Services\JiraService($config['jira'] ?? [], $integration, $db);
        $jiraService->refreshWebhooks();
        echo "Webhooks refreshed.\n";

        // Also refresh OAuth token proactively if it expires within 7 days
        $expiresAt = $integration['token_expires_at'] ?? null;
        if ($expiresAt && strtotime($expiresAt) < time() + (7 * 86400)) {
            echo "  Token expires soon ({$expiresAt}), refreshing...\n";
            $jiraService->refreshAccessToken();
            echo "  Token refreshed.\n";
        }
    } catch (\Throwable $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
        \StratFlow\Models\Integration::recordError($db, (int) $integration['id'], $e->getMessage());
    }
}

echo "\nDone.\n";
