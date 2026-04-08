#!/usr/bin/env php
<?php
/**
 * Database Initialization Script
 *
 * Connects to MySQL using DATABASE_URL, then runs database/schema.sql
 * followed by any numbered migration files in database/migrations/.
 *
 * Safe to run multiple times — the schema uses CREATE TABLE IF NOT EXISTS
 * and migrations are applied with IF NOT EXISTS guards where possible.
 *
 * Usage: php database/init-db.php
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// 1. Resolve connection details from DATABASE_URL
// ---------------------------------------------------------------------------
$databaseUrl = getenv('DATABASE_URL');

if (empty($databaseUrl)) {
    echo "ERROR: DATABASE_URL environment variable is not set.\n";
    exit(1);
}

$parsed = parse_url($databaseUrl);

if ($parsed === false || empty($parsed['host'])) {
    echo "ERROR: Could not parse DATABASE_URL — expected format: mysql://user:pass@host:port/dbname\n";
    exit(1);
}

$host     = $parsed['host'];
$port     = (string) ($parsed['port'] ?? '3306');
$dbname   = ltrim($parsed['path'] ?? '', '/');
$username = $parsed['user'] ?? 'root';
$password = $parsed['pass'] ?? '';

if (empty($dbname)) {
    echo "ERROR: DATABASE_URL does not contain a database name.\n";
    exit(1);
}

// ---------------------------------------------------------------------------
// 2. Connect via PDO
// ---------------------------------------------------------------------------
$dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";

try {
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (PDOException $e) {
    echo "ERROR: Could not connect to database: " . $e->getMessage() . "\n";
    exit(1);
}

echo "Connected to database: {$dbname}@{$host}:{$port}\n";

// ---------------------------------------------------------------------------
// 3. Apply base schema (idempotent — CREATE TABLE IF NOT EXISTS)
// ---------------------------------------------------------------------------
$schemaFile = __DIR__ . '/schema.sql';

if (!file_exists($schemaFile)) {
    echo "ERROR: Schema file not found at {$schemaFile}\n";
    exit(1);
}

$schema = file_get_contents($schemaFile);

if ($schema === false || trim($schema) === '') {
    echo "ERROR: Schema file is empty or unreadable.\n";
    exit(1);
}

try {
    $pdo->exec($schema);
    echo "Schema applied successfully.\n";
} catch (PDOException $e) {
    echo "ERROR: Failed to apply schema: " . $e->getMessage() . "\n";
    exit(1);
}

// ---------------------------------------------------------------------------
// 4. Apply migrations in order (idempotent where guards exist)
// ---------------------------------------------------------------------------
$migrationDir = __DIR__ . '/migrations/';

if (is_dir($migrationDir)) {
    $files = glob($migrationDir . '*.sql');

    if (!empty($files)) {
        sort($files); // ensure numeric order (001_, 002_, …)

        foreach ($files as $file) {
            $name = basename($file);
            $sql  = file_get_contents($file);

            if ($sql === false || trim($sql) === '') {
                echo "Skipping empty migration: {$name}\n";
                continue;
            }

            try {
                $pdo->exec($sql);
                echo "Migration applied: {$name}\n";
            } catch (PDOException $e) {
                echo "ERROR: Migration {$name} failed: " . $e->getMessage() . "\n";
                exit(1);
            }
        }

        echo "All migrations applied.\n";
    } else {
        echo "No migration files found.\n";
    }
} else {
    echo "No migrations directory found — skipping.\n";
}

// ---------------------------------------------------------------------------
// 5. Confirm tables are present
// ---------------------------------------------------------------------------
$stmt   = $pdo->query('SHOW TABLES');
$tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

echo "Tables present (" . count($tables) . "): " . implode(', ', $tables) . "\n";
echo "\nDatabase initialization complete.\n";
