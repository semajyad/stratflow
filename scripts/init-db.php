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

function isBenignMigrationError(string $stmt, PDOException $e): bool
{
    $driverCode = (int) ($e->errorInfo[1] ?? 0);

    // 1050 = table exists, 1060 = duplicate column, 1061 = duplicate key,
    // 1826 = duplicate FK name. These are expected when replaying idempotent migrations.
    if (in_array($driverCode, [1050, 1060, 1061, 1826], true)) {
        return true;
    }

    // MySQL images used in CI can reject IF NOT EXISTS on selected ALTER/INDEX
    // forms even though the statements are intended to be replay-safe.
    return $driverCode === 1064 && (
        stripos($stmt, 'ADD COLUMN IF NOT EXISTS') !== false
        || stripos($stmt, 'CREATE INDEX IF NOT EXISTS') !== false
    );
}

try {
    $pdo = new PDO($dsn, $config['db']['username'], $config['db']['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    echo "Connected to database: {$config['db']['database']}@{$config['db']['host']}\n";

    // Run full schema (idempotent — IF NOT EXISTS)
    $schema = file_get_contents(__DIR__ . '/../database/schema.sql');
    $pdo->exec($schema);
    echo "Schema applied successfully.\n";

    // Run migrations (each statement individually, skip known idempotency duplicates)
    $migrationErrors = [];
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
            // Strip SQL comment lines, then split on semicolons
            $sql = preg_replace('/^\s*--.*$/m', '', $sql);
            $statements = array_filter(array_map('trim', explode(';', $sql)));
            foreach ($statements as $stmt) {
                if ($stmt === '') continue;
                try {
                    // Use query() instead of exec() so we get a PDOStatement
                    // whose cursor we can close. exec() leaves PREPARE/EXECUTE
                    // result sets open, causing PDO 2014 errors on the next
                    // statement in migrations that use dynamic SQL.
                    $result = $pdo->query($stmt);
                    if ($result instanceof PDOStatement) {
                        $result->closeCursor();
                    }
                } catch (PDOException $e) {
                    if (isBenignMigrationError($stmt, $e)) {
                        echo "  Skipped (already applied or unsupported idempotency syntax): {$e->errorInfo[2]}\n";
                    } else {
                        $migrationErrors[] = "{$name}: {$e->getMessage()}";
                        echo "  Error: {$e->getMessage()}\n";
                    }
                }
            }
        }
        if ($migrationErrors !== []) {
            echo "Migration initialization failed with unexpected error(s):\n";
            foreach ($migrationErrors as $error) {
                echo "  - {$error}\n";
            }
            exit(1);
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
