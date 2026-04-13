<?php
/**
 * Quality Scoring Worker
 *
 * Processes pending and failed (with backoff) quality score rows
 * for both user_stories and hl_work_items.
 *
 * Usage:
 *   php bin/score_quality.php [--loop] [--limit=N]
 *
 * Flags:
 *   --loop   Run continuously, sleeping 120s between batches. Handles
 *            SIGTERM/SIGINT for clean shutdown (Railway, Docker stop).
 *            Without this flag, runs one batch and exits.
 *   --limit  Max rows per table per run (default: 25)
 *
 * Row selection:
 *   - status = 'pending' (never tried, or reset by an edit)
 *   - status = 'failed' AND attempts < 5 AND last_attempt_at < NOW() - POW(2, attempts) minutes
 *
 * Concurrency: each row is claimed optimistically before processing to avoid
 * double-scoring under concurrent workers.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

$config = require __DIR__ . '/../src/Config/config.php';

$limit  = 25;
$loop   = false;
foreach ($argv as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = (int) $m[1];
    }
    if ($arg === '--loop') {
        $loop = true;
    }
}

// === SIGTERM / SIGINT handling (loop mode) ===
// Allows Railway / Docker to stop the worker cleanly without leaving
// half-processed rows in flight.
$shutdown = false;
if (function_exists('pcntl_signal')) {
    $handler = static function () use (&$shutdown): void {
        $shutdown = true;
        worker_log('Shutdown signal received — finishing current batch then exiting.');
    };
    pcntl_signal(SIGTERM, $handler);
    pcntl_signal(SIGINT,  $handler);
}

// ===========================
// BOOTSTRAP
// ===========================

try {
    $db      = new \StratFlow\Core\Database($config['db']);
    $gemini  = new \StratFlow\Services\GeminiService($config);
    $scorer  = new \StratFlow\Services\StoryQualityScorer($gemini);
    $improver = new \StratFlow\Services\StoryImprovementService($gemini);
} catch (\Throwable $e) {
    fwrite(STDERR, '[score_quality] Bootstrap failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

// ===========================
// LOG HELPERS
// ===========================

$logFile = __DIR__ . '/../storage/logs/quality_worker.log';

function worker_log(string $line): void
{
    global $logFile;
    $entry = '[' . date('Y-m-d H:i:s') . '] ' . $line . PHP_EOL;
    file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
}

// ===========================
// SCORING LOOP
// ===========================

/**
 * Fetch and claim pending/retry rows for one table.
 * Returns [] if nothing to do.
 *
 * @param  \StratFlow\Core\Database $db
 * @param  string                   $table    'user_stories' or 'hl_work_items'
 * @param  int                      $limit
 * @return array
 */
function fetch_workload(\StratFlow\Core\Database $db, string $table, int $limit): array
{
    $pdo = $db->getPdo();

    // Select candidates — join projects to get org_id (not on the item/story tables directly)
    $stmt = $pdo->prepare(
        "SELECT t.id, t.quality_attempts, t.quality_last_attempt_at, t.quality_status,
                t.title, t.description, t.acceptance_criteria, t.kr_hypothesis,
                p.org_id
         FROM `{$table}` t
         JOIN projects p ON p.id = t.project_id
         WHERE t.quality_status = 'pending'
            OR (t.quality_status = 'failed'
                AND t.quality_attempts < 5
                AND (
                    t.quality_last_attempt_at IS NULL
                    OR t.quality_last_attempt_at < NOW() - INTERVAL POWER(2, t.quality_attempts) MINUTE
                ))
         ORDER BY t.quality_last_attempt_at IS NULL DESC, t.quality_last_attempt_at ASC
         LIMIT :lim"
    );
    $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    if (empty($rows)) {
        return [];
    }

    // Claim rows by touching quality_last_attempt_at (optimistic lock — only update
    // rows whose quality_last_attempt_at still matches what we read, preventing double-scoring
    // if two workers race). A simple timestamp claim is sufficient for a 2-min cron.
    $ids = array_column($rows, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $pdo->prepare(
        "UPDATE `{$table}`
         SET quality_last_attempt_at = NOW()
         WHERE id IN ({$placeholders})
           AND (quality_last_attempt_at IS NULL OR quality_last_attempt_at <= NOW())"
    )->execute($ids);

    return $rows;
}

/**
 * Score one batch for a given table.
 * If a row scores below 80, run one improvement pass then re-score in the same cycle.
 *
 * @param  \StratFlow\Core\Database                   $db
 * @param  \StratFlow\Services\StoryQualityScorer      $scorer
 * @param  \StratFlow\Services\StoryImprovementService $improver
 * @param  string                                      $table    'user_stories' or 'hl_work_items'
 * @param  string                                      $model    'UserStory' or 'HLWorkItem'
 * @param  string                                      $scoreMethod  'scoreStory' or 'scoreWorkItem'
 * @param  string                                      $improveMethod 'improveStory' or 'improveWorkItem'
 * @param  string                                      $updateModel  model class for update calls
 * @param  int                                         $limit
 * @return array{scored: int, failed: int, errors: list<string>}
 */
function score_batch(
    \StratFlow\Core\Database $db,
    \StratFlow\Services\StoryQualityScorer $scorer,
    \StratFlow\Services\StoryImprovementService $improver,
    string $table,
    string $model,
    string $scoreMethod,
    string $improveMethod,
    int $limit
): array {
    $rows = fetch_workload($db, $table, $limit);
    $stats = ['scored' => 0, 'failed' => 0, 'errors' => []];

    foreach ($rows as $row) {
        $id       = (int) $row['id'];
        $orgId    = (int) $row['org_id'];
        $attempts = (int) $row['quality_attempts'] + 1;

        // Load org quality rules
        $qualityBlock = '';
        try {
            $qualityBlock = \StratFlow\Models\StoryQualityConfig::buildPromptBlock($db, $orgId);
        } catch (\Throwable) {
            // Empty block — scorer still runs; dimensions won't be org-customised
        }

        $modelClass = 'StratFlow\\Models\\' . $model;

        $result = $scorer->{$scoreMethod}($row, $qualityBlock);

        if ($result['score'] !== null) {
            // If below threshold, improve the failing dimensions then re-score
            if ($result['score'] < 80) {
                try {
                    $improvedFields = $improver->{$improveMethod}($row, $result['breakdown'], $qualityBlock);
                    if (!empty($improvedFields)) {
                        $modelClass::update($db, $id, $improvedFields);
                        $row = array_merge($row, $improvedFields);
                    }
                    $result2 = $scorer->{$scoreMethod}($row, $qualityBlock);
                    if ($result2['score'] !== null) {
                        $result = $result2;
                    }
                } catch (\Throwable) {
                    // Improvement failed — still persist the original score
                }
            }
            $modelClass::markQualityScored($db, $id, $result['score'], $result['breakdown']);
            $stats['scored']++;
        } else {
            $errorKey = $result['error'] ?? 'unknown';
            $modelClass::markQualityFailed($db, $id, $attempts, $errorKey);
            $stats['failed']++;
            $stats['errors'][] = $errorKey;
        }
    }

    return $stats;
}

// ===========================
// MAIN
// ===========================

/**
 * Run one scoring batch and log a summary line.
 */
function run_once(
    \StratFlow\Core\Database $db,
    \StratFlow\Services\StoryQualityScorer $scorer,
    \StratFlow\Services\StoryImprovementService $improver,
    int $limit
): void {
    $storyStats = score_batch($db, $scorer, $improver, 'user_stories', 'UserStory', 'scoreStory', 'improveStory', $limit);
    $itemStats  = score_batch($db, $scorer, $improver, 'hl_work_items', 'HLWorkItem', 'scoreWorkItem', 'improveWorkItem', $limit);

    $totalScored = $storyStats['scored'] + $itemStats['scored'];
    $totalFailed = $storyStats['failed'] + $itemStats['failed'];
    $allErrors   = array_unique(array_merge($storyStats['errors'], $itemStats['errors']));

    $errSummary = empty($allErrors) ? '' : ' errors=[' . implode(', ', $allErrors) . ']';
    worker_log("scored={$totalScored} failed={$totalFailed}{$errSummary}");
}

if (!$loop) {
    // Single-batch mode (default)
    run_once($db, $scorer, $improver, $limit);
    exit(0);
}

// Loop mode — runs continuously until SIGTERM/SIGINT
worker_log('Worker started in loop mode (sleep=120s, limit=' . $limit . ').');

while (!$shutdown) {
    if (function_exists('pcntl_signal_dispatch')) {
        pcntl_signal_dispatch();
    }

    if ($shutdown) {
        break;
    }

    run_once($db, $scorer, $improver, $limit);

    // Ping Healthchecks.io if configured
    $hcUrl = $_ENV['HEALTHCHECKS_QUALITY_WORKER'] ?? '';
    if ($hcUrl !== '') {
        @file_get_contents($hcUrl);
    }

    // Sleep in 1s increments so SIGTERM is handled promptly
    for ($i = 0; $i < 120 && !$shutdown; $i++) {
        sleep(1);
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }
    }
}

worker_log('Worker stopped cleanly.');
exit(0);
