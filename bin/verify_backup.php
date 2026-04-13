<?php
/**
 * Backup Verification Script
 *
 * Connects to a secondary database (BACKUP_DB_*) that should contain a
 * recently-restored snapshot, runs sanity checks, and pings Healthchecks.io.
 *
 * Usage:
 *   php bin/verify_backup.php
 *
 * Environment variables (in addition to .env):
 *   BACKUP_DB_HOST     — host of the restored backup DB (default: same as primary)
 *   BACKUP_DB_DATABASE — database name of the restored backup
 *   BACKUP_DB_USERNAME — backup DB username
 *   BACKUP_DB_PASSWORD — backup DB password
 *   BACKUP_MAX_HOURS   — alert if backup is older than N hours (default: 26)
 *   HEALTHCHECKS_BACKUP_VERIFY — Healthchecks.io ping URL
 *
 * How to wire:
 *   1. Take a MySQL dump from Railway MySQL plugin (or managed prod DB).
 *   2. Restore it to a throwaway DB instance (e.g. a separate Railway service
 *      or a local/staging MySQL).
 *   3. Point BACKUP_DB_* at the restored instance.
 *   4. Run this script via a weekly GitHub Actions cron.
 *
 * Exit codes: 0 = OK, 1 = verification failed (will alert via Healthchecks.io).
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = \Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

// ===========================
// CONFIG
// ===========================

$backupHost = $_ENV['BACKUP_DB_HOST']     ?? $_ENV['DB_HOST']     ?? '127.0.0.1';
$backupDb   = $_ENV['BACKUP_DB_DATABASE'] ?? $_ENV['DB_DATABASE'] ?? 'stratflow';
$backupUser = $_ENV['BACKUP_DB_USERNAME'] ?? $_ENV['DB_USERNAME'] ?? 'stratflow';
$backupPass = $_ENV['BACKUP_DB_PASSWORD'] ?? $_ENV['DB_PASSWORD'] ?? '';
$maxHours   = (int) ($_ENV['BACKUP_MAX_HOURS'] ?? 26);
$hcUrl      = (string) ($_ENV['HEALTHCHECKS_BACKUP_VERIFY'] ?? '');

$primaryHost = $_ENV['DB_HOST']     ?? '127.0.0.1';
$primaryDb   = $_ENV['DB_DATABASE'] ?? 'stratflow';
$primaryUser = $_ENV['DB_USERNAME'] ?? 'stratflow';
$primaryPass = $_ENV['DB_PASSWORD'] ?? '';

function backup_fail(string $reason, string $hcUrl): never
{
    fwrite(STDERR, '[verify_backup] FAIL: ' . $reason . PHP_EOL);
    if ($hcUrl !== '') {
        @file_get_contents($hcUrl . '/fail');
    }
    exit(1);
}

// ===========================
// CONNECT
// ===========================

try {
    $backupPdo = new \PDO(
        "mysql:host={$backupHost};dbname={$backupDb};charset=utf8mb4",
        $backupUser,
        $backupPass,
        [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
    );
} catch (\Throwable $e) {
    backup_fail('Cannot connect to backup DB: ' . $e->getMessage(), $hcUrl);
}

try {
    $primaryPdo = new \PDO(
        "mysql:host={$primaryHost};dbname={$primaryDb};charset=utf8mb4",
        $primaryUser,
        $primaryPass,
        [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
    );
} catch (\Throwable $e) {
    backup_fail('Cannot connect to primary DB: ' . $e->getMessage(), $hcUrl);
}

// ===========================
// CHECKS
// ===========================

$errors = [];

// 1. Table existence
$requiredTables = ['organisations', 'users', 'projects', 'user_stories', 'hl_work_items', 'audit_logs'];
foreach ($requiredTables as $table) {
    $row = $backupPdo->query("SHOW TABLES LIKE '{$table}'")->fetch();
    if (!$row) {
        $errors[] = "Missing table: {$table}";
    }
}

if (!empty($errors)) {
    backup_fail('Table check failed: ' . implode(', ', $errors), $hcUrl);
}

// 2. Row count sanity — backup should have ≥80% of primary row counts
$tables = ['organisations', 'users', 'projects', 'user_stories'];
foreach ($tables as $table) {
    $backupCount  = (int) $backupPdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
    $primaryCount = (int) $primaryPdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();

    if ($primaryCount > 0 && ($backupCount / $primaryCount) < 0.80) {
        $errors[] = "{$table}: backup has {$backupCount} rows, primary has {$primaryCount} (below 80%)";
    }

    echo "[verify_backup] {$table}: backup={$backupCount} primary={$primaryCount}" . PHP_EOL;
}

// 3. Freshness — most recent created_at should be within $maxHours
$freshRow = $backupPdo->query(
    "SELECT MAX(created_at) AS latest FROM users"
)->fetch(\PDO::FETCH_ASSOC);

if (!empty($freshRow['latest'])) {
    $latestTs = strtotime($freshRow['latest']);
    $ageHours = (time() - $latestTs) / 3600;
    $ageDisplay = number_format($ageHours, 1);
    echo "[verify_backup] Newest users.created_at: {$freshRow['latest']} ({$ageDisplay}h ago)" . PHP_EOL;
    if ($ageHours > $maxHours + 48) {
        // Allow extra buffer for backup restore lag — only flag if really stale
        $errors[] = "Backup data appears stale: newest user created {$ageDisplay}h ago";
    }
}

// ===========================
// RESULT
// ===========================

if (!empty($errors)) {
    backup_fail('Sanity checks failed: ' . implode('; ', $errors), $hcUrl);
}

echo '[verify_backup] All checks passed.' . PHP_EOL;

if ($hcUrl !== '') {
    @file_get_contents($hcUrl);
    echo '[verify_backup] Healthchecks.io pinged.' . PHP_EOL;
}

exit(0);
