#!/usr/bin/env php
<?php
/**
 * Database Initialization Script
 *
 * Runs the schema SQL against the configured database.
 * Safe to run multiple times — uses CREATE TABLE IF NOT EXISTS.
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

    // Run migrations (each statement individually, skip duplicates)
    $migrationDir = __DIR__ . '/../database/migrations/';
    if (is_dir($migrationDir)) {
        $files = glob($migrationDir . '*.sql');
        sort($files);
        foreach ($files as $file) {
            $name = basename($file);
            echo "Running migration: {$name}\n";
            $sql = file_get_contents($file);
            // Split on semicolons, run each statement separately so
            // "duplicate column" errors don't block subsequent statements
            $statements = array_filter(array_map('trim', explode(';', $sql)));
            foreach ($statements as $stmt) {
                if ($stmt === '' || str_starts_with($stmt, '--')) continue;
                try {
                    $pdo->exec($stmt);
                } catch (PDOException $e) {
                    // 1060 = Duplicate column, 1061 = Duplicate key — safe to skip
                    if (in_array($e->errorInfo[1] ?? 0, [1060, 1061])) {
                        echo "  Skipped (already applied): {$e->errorInfo[2]}\n";
                    } else {
                        echo "  Warning: {$e->getMessage()}\n";
                    }
                }
            }
        }
        echo "All migrations applied.\n";
    }

    // Check table count
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "Tables present: " . count($tables) . " (" . implode(', ', $tables) . ")\n";

    echo "\nDatabase initialization complete.\n";
} catch (PDOException $e) {
    echo "Database error: " . $e->getMessage() . "\n";
    exit(1);
}
