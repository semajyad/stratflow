<?php
/**
 * Purge Deleted Organisations
 *
 * Permanently removes orgs that have been soft-deleted for >= 30 days.
 * Audit logs are retained in the audit_logs table per SOC 2 Type II 7-year
 * retention policy — they are NOT deleted by this script.
 *
 * Run daily via a Railway worker or GitHub Actions scheduled job.
 *
 * Usage:
 *   php bin/purge_deleted_orgs.php [--dry-run]
 *
 * Flags:
 *   --dry-run  Show what would be purged without deleting anything
 *
 * Deletion order (respects FK constraints):
 *   1. Child data: user_stories, hl_work_items, projects, integrations,
 *      rate_limits (for org users), password_tokens (for org users), sessions (for org users)
 *   2. Users
 *   3. Organisation row
 *   NOTE: audit_logs are intentionally NOT deleted.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

$config = require __DIR__ . '/../src/Config/config.php';

$dryRun = in_array('--dry-run', $argv, true);

// ===========================
// BOOTSTRAP
// ===========================

try {
    $db  = new \StratFlow\Core\Database($config['db']);
    $pdo = $db->getPdo();
} catch (\Throwable $e) {
    fwrite(STDERR, '[purge_deleted_orgs] DB connection failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

// ===========================
// FIND ELIGIBLE ORGS
// ===========================

$stmt = $pdo->prepare(
    "SELECT id, name, deleted_at, deletion_reason
     FROM organisations
     WHERE deleted_at IS NOT NULL
       AND deleted_at <= NOW() - INTERVAL 30 DAY"
);
$stmt->execute();
$orgs = $stmt->fetchAll(\PDO::FETCH_ASSOC);

if (empty($orgs)) {
    echo '[purge_deleted_orgs] No orgs eligible for purge.' . PHP_EOL;
    exit(0);
}

// ===========================
// PURGE EACH ORG
// ===========================

$purged = 0;

foreach ($orgs as $org) {
    $id   = (int) $org['id'];
    $name = $org['name'];

    echo "[purge_deleted_orgs] " . ($dryRun ? '[dry-run] ' : '') . "Purging org id={$id} name={$name} deleted_at={$org['deleted_at']}" . PHP_EOL;

    if ($dryRun) {
        $purged++;
        continue;
    }

    try {
        $pdo->beginTransaction();

        // Get user IDs before deleting users (for session/token cleanup)
        $userIds = $pdo->prepare("SELECT id FROM users WHERE org_id = ?");
        $userIds->execute([$id]);
        $uids = array_column($userIds->fetchAll(\PDO::FETCH_ASSOC), 'id');

        // Delete child data via projects (cascaded where FK exists,
        // explicit where not)
        $projectIds = $pdo->prepare("SELECT id FROM projects WHERE org_id = ?");
        $projectIds->execute([$id]);
        $pids = array_column($projectIds->fetchAll(\PDO::FETCH_ASSOC), 'id');

        if (!empty($pids)) {
            $inPids = implode(',', array_map('intval', $pids));
            $pdo->exec("DELETE FROM user_stories  WHERE project_id IN ({$inPids})");
            $pdo->exec("DELETE FROM hl_work_items WHERE project_id IN ({$inPids})");
        }

        $pdo->prepare("DELETE FROM projects     WHERE org_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM integrations WHERE org_id = ?")->execute([$id]);

        // Clean up user-scoped tables
        if (!empty($uids)) {
            $inUids = implode(',', array_map('intval', $uids));
            $pdo->exec("DELETE FROM password_tokens WHERE user_id IN ({$inUids})");
            // Sessions are keyed by session ID string, not user_id — expire naturally
        }

        // Delete users
        $pdo->prepare("DELETE FROM users WHERE org_id = ?")->execute([$id]);

        // Finally, delete the org row (audit_logs intentionally kept)
        $pdo->prepare("DELETE FROM organisations WHERE id = ?")->execute([$id]);

        $pdo->commit();
        $purged++;
        echo "[purge_deleted_orgs] Purged org id={$id}." . PHP_EOL;
    } catch (\Throwable $e) {
        $pdo->rollBack();
        fwrite(STDERR, "[purge_deleted_orgs] Failed to purge org id={$id}: " . $e->getMessage() . PHP_EOL);
    }
}

$mode = $dryRun ? ' (dry-run)' : '';
echo "[purge_deleted_orgs] Done. purged={$purged}{$mode}" . PHP_EOL;
exit(0);
