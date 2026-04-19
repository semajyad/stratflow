#!/usr/bin/env php
<?php
/**
 * Database Initialization Script
 *
 * Runs the schema SQL against the configured database, then applies any
 * unapplied migrations via MigrationRunner (ledger-tracked, checksum-verified).
 *
 * Safe to run multiple times — the ledger ensures each migration runs once.
 *
 * Usage: php scripts/init-db.php
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

    echo "Connected to database: {$config['db']['database']}@{$config['db']['host']}\n";

    // Run full schema (idempotent — IF NOT EXISTS)
    $schema = file_get_contents(__DIR__ . '/../database/schema.sql');
    $pdo->exec($schema);
    echo "Schema applied successfully.\n";

    // Run migrations via the ledger-tracked MigrationRunner.
    // Each migration runs exactly once; checksums detect post-apply tampering.
    $migrationDir = __DIR__ . '/../database/migrations/';
    if (is_dir($migrationDir)) {
        $runner = new \StratFlow\Core\MigrationRunner($pdo, $migrationDir);
        $runner->run();
        echo "All migrations applied.\n";
    }

    // Check table count
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "Tables present: " . count($tables) . " (" . implode(', ', $tables) . ")\n";

    echo "\nDatabase initialization complete.\n";
} catch (\Throwable $e) {
    echo "Database error: " . $e->getMessage() . "\n";
    exit(1);
}
