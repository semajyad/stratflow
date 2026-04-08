<?php

declare(strict_types=1);

namespace StratFlow\Core;

use PDO;
use PDOStatement;

/**
 * PDO Database Wrapper (Singleton)
 *
 * Provides a thin wrapper around PDO with secure defaults:
 * exception error mode, associative fetch, real prepared statements.
 */
class Database
{
    private static ?Database $instance = null;
    private PDO $pdo;

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
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
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
     * @param string $sql    SQL statement with optional placeholders
     * @param array  $params Bound parameter values
     * @return PDOStatement
     */
    public function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
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
