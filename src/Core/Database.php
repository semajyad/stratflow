<?php

declare(strict_types=1);

namespace StratFlow\Core;

use PDO;
use PDOStatement;

/**
 * PDO Database Wrapper (Singleton)
 *
 * Provides a thin wrapper around PDO with secure defaults:
 * exception error mode, associative fetch, real prepared statements,
 * connection timeout, and slow query logging.
 */
class Database
{
    private static ?Database $instance = null;
    private PDO $pdo;

    /** @var float Slow query threshold in seconds */
    private const SLOW_QUERY_THRESHOLD = 2.0;

    /**
     * @param array $config DB config array with host, port, database, username, password
     */
    public function __construct(array $config)
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $config['host'],
            $config['port'],
            $config['database']
        );

        try {
            $this->pdo = new PDO($dsn, $config['username'], $config['password'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_TIMEOUT            => 5,  // Connection timeout (seconds)
            ]);

            // Set MySQL-level query execution timeout (30 seconds)
            $this->pdo->exec('SET SESSION max_execution_time = 30000');
        } catch (\Throwable $e) {
            // Re-throw as RuntimeException so callers always receive a consistent,
            // catchable Throwable regardless of the underlying driver error type
            // (e.g. caching_sha2_password auth failures may not surface as PDOException).
            throw new \RuntimeException(
                'Database connection failed: ' . $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }

        self::$instance = $this;
    }

    /**
     * Execute a prepared query with optional parameters.
     *
     * Logs queries that exceed the slow query threshold to error_log.
     *
     * @param string $sql    SQL statement with optional placeholders
     * @param array  $params Bound parameter values
     * @return PDOStatement
     */
    public function query(string $sql, array $params = []): PDOStatement
    {
        $start = microtime(true);

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $elapsed = microtime(true) - $start;
        if ($elapsed > self::SLOW_QUERY_THRESHOLD) {
            error_log(sprintf(
                '[StratFlow] Slow query (%.2fs): %s',
                $elapsed,
                substr($sql, 0, 200)
            ));
        }

        return $stmt;
    }

    /**
     * Return the ID of the last inserted row.
     */
    public function lastInsertId(): string
    {
        return $this->pdo->lastInsertId();
    }

    /**
     * Return the underlying PDO instance (for transactions, etc.).
     */
    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * Check whether a table exists in the current database.
     *
     * Useful for graceful degradation when the schema has not yet been
     * initialised (e.g. first deploy before manual DB setup).
     *
     * @param string $table Table name to check
     * @return bool
     */
    public function tableExists(string $table): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT 1 FROM information_schema.tables
                  WHERE table_schema = DATABASE()
                    AND table_name = ?
                  LIMIT 1'
            );
            $stmt->execute([$table]);
            return $stmt->fetchColumn() !== false;
        } catch (\PDOException) {
            return false;
        }
    }
}
