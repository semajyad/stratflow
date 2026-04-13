<?php
/**
 * Quality Scoring Backfill
 *
 * Resets matching rows to quality_status='pending' so the background
 * worker (score_quality.php) will re-score them on its next run.
 *
 * Usage:
 *   php bin/backfill_quality.php [--org=N] [--limit=1000] [--all]
 *
 * Flags:
 *   --org=N    Scope to a specific org ID (optional)
 *   --limit=N  Max rows to reset per table (default: 1000)
 *   --all      Include already-scored rows (default: only-null)
 *
 * Safe to re-run — idempotent. Does not touch quality_score itself.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

$config = require __DIR__ . '/../src/Config/config.php';

// ===========================
// ARG PARSING
// ===========================

$orgId = null;
$limit = 1000;
$onlyNull = true;

foreach ($argv as $arg) {
    if (preg_match('/^--org=(\d+)$/', $arg, $m)) {
        $orgId = (int) $m[1];
    } elseif (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = (int) $m[1];
    } elseif ($arg === '--all') {
        $onlyNull = false;
    }
}

// ===========================
// BOOTSTRAP
// ===========================

try {
    $db = new \StratFlow\Core\Database($config['db']);
} catch (\Throwable $e) {
    fwrite(STDERR, '[backfill_quality] Bootstrap failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

$pdo = $db->getPdo();

// ===========================
// RESET FUNCTION
// ===========================

function reset_table(
    \PDO $pdo,
    string $table,
    ?int $orgId,
    int $limit,
    bool $onlyNull
): int {
    // org_id lives on projects, not on the item tables, so scope via subquery
    $conditions = $onlyNull ? "t.quality_score IS NULL" : "1=1";
    $orgClause  = $orgId !== null
        ? " AND t.project_id IN (SELECT id FROM projects WHERE org_id = ?)"
        : "";
    $params     = $orgId !== null ? [$orgId, $limit] : [$limit];

    // MySQL UPDATE with subquery alias requires a workaround — use EXISTS
    if ($orgId !== null) {
        $stmt = $pdo->prepare(
            "UPDATE `{$table}` t
             SET t.quality_status          = 'pending',
                 t.quality_attempts        = 0,
                 t.quality_error           = NULL,
                 t.quality_last_attempt_at = NULL
             WHERE {$conditions}
               AND t.quality_status != 'pending'
               AND EXISTS (SELECT 1 FROM projects p WHERE p.id = t.project_id AND p.org_id = ?)
             LIMIT ?"
        );
        $stmt->execute([$orgId, $limit]);
    } else {
        $stmt = $pdo->prepare(
            "UPDATE `{$table}`
             SET quality_status          = 'pending',
                 quality_attempts        = 0,
                 quality_error           = NULL,
                 quality_last_attempt_at = NULL
             WHERE {$conditions}
               AND quality_status != 'pending'
             LIMIT ?"
        );
        $stmt->execute([$limit]);
    }

    return $stmt->rowCount();
}

// ===========================
// MAIN
// ===========================

$nullDesc = $onlyNull ? '(null-score only)' : '(all rows)';
$orgDesc  = $orgId !== null ? "org={$orgId}" : 'all orgs';

echo "[backfill_quality] Resetting {$nullDesc} for {$orgDesc}, limit={$limit} per table" . PHP_EOL;

$storiesReset = reset_table($pdo, 'user_stories', $orgId, $limit, $onlyNull);
$itemsReset   = reset_table($pdo, 'hl_work_items', $orgId, $limit, $onlyNull);

echo "[backfill_quality] Done: stories={$storiesReset} work_items={$itemsReset} rows enqueued" . PHP_EOL;
echo "[backfill_quality] The background worker will score them on its next run (within 2 min)" . PHP_EOL;

exit(0);
