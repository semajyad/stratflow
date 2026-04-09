<?php
declare(strict_types=1);

namespace StratFlow\Core;

/**
 * Database Session Handler
 *
 * Stores PHP sessions in MySQL/MariaDB so they persist across
 * container deployments (Railway, Docker, etc.). Implements the
 * SessionHandlerInterface for use with session_set_save_handler().
 *
 * Table: sessions (id, data, last_accessed)
 */
class DatabaseSessionHandler implements \SessionHandlerInterface
{
    private \PDO $pdo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        $stmt = $this->pdo->prepare(
            "SELECT data FROM sessions WHERE id = :id LIMIT 1"
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row ? $row['data'] : '';
    }

    public function write(string $id, string $data): bool
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO sessions (id, data, last_accessed) VALUES (:id, :data, :time)
             ON DUPLICATE KEY UPDATE data = :data2, last_accessed = :time2"
        );

        $now = time();
        return $stmt->execute([
            ':id'    => $id,
            ':data'  => $data,
            ':time'  => $now,
            ':data2' => $data,
            ':time2' => $now,
        ]);
    }

    public function destroy(string $id): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM sessions WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

    public function gc(int $max_lifetime): int|false
    {
        $stmt = $this->pdo->prepare(
            "DELETE FROM sessions WHERE last_accessed < :expire"
        );
        $stmt->execute([':expire' => time() - $max_lifetime]);

        return $stmt->rowCount();
    }
}
