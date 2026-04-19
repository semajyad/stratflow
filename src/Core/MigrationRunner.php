<?php

declare(strict_types=1);

namespace StratFlow\Core;

/**
 * Applies database migrations exactly once, tracked by a schema_migrations ledger.
 *
 * Each migration file is identified by filename and a SHA-256 checksum of its
 * content. Once recorded in the ledger the migration never runs again. If a
 * previously-applied migration file is modified on disk the runner throws to
 * prevent silent drift between the migration history and the schema.
 *
 * On first deploy against a database that was previously managed without a
 * ledger, a migration that fails with MySQL error 1060 (duplicate column),
 * 1061 (duplicate key), or 1826 (duplicate FK) is treated as already applied
 * and back-filled into the ledger. This allows a one-time safe transition for
 * existing databases.
 */
final class MigrationRunner
{
    /** MySQL error codes that indicate a migration was already applied. */
    private const ALREADY_APPLIED_CODES = [1060, 1061, 1826];

    public function __construct(
        private readonly \PDO $pdo,
        private readonly string $migrationDir,
    ) {}

    public function run(): void
    {
        $this->ensureLedgerTable();

        $applied = $this->getApplied();

        $files = glob(rtrim($this->migrationDir, '/') . '/*.sql') ?: [];
        sort($files);

        foreach ($files as $file) {
            $name     = basename($file);
            $content  = (string) file_get_contents($file);
            $checksum = hash('sha256', $content);

            if (isset($applied[$name])) {
                if ($applied[$name] !== $checksum) {
                    throw new \RuntimeException(
                        "Migration {$name} checksum mismatch — file was modified after being applied. "
                        . "Recorded: {$applied[$name]}, current: {$checksum}"
                    );
                }
                continue;
            }

            $this->applyMigration($name, $content, $checksum);
        }
    }

    private function ensureLedgerTable(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS schema_migrations (
                filename   VARCHAR(255) NOT NULL,
                checksum   CHAR(64)     NOT NULL,
                applied_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (filename)
            )
        ");
    }

    /** @return array<string,string> filename => checksum */
    private function getApplied(): array
    {
        $stmt = $this->pdo->query('SELECT filename, checksum FROM schema_migrations');
        $rows = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);
        return $rows ?: [];
    }

    private function applyMigration(string $name, string $content, string $checksum): void
    {
        $sql        = preg_replace('/^\s*--.*$/m', '', $content) ?? '';
        $statements = array_filter(array_map('trim', explode(';', $sql)));

        foreach ($statements as $stmt) {
            try {
                $result = $this->pdo->query($stmt);
                if ($result instanceof \PDOStatement) {
                    $result->closeCursor();
                }
            } catch (\PDOException $e) {
                $code = (int) ($e->errorInfo[1] ?? 0);
                if (in_array($code, self::ALREADY_APPLIED_CODES, true)) {
                    // Back-fill: migration was applied before the ledger existed.
                    $this->recordApplied($name, $checksum);
                    return;
                }
                throw new \RuntimeException(
                    "Migration {$name} failed: {$e->getMessage()}",
                    0,
                    $e
                );
            }
        }

        $this->recordApplied($name, $checksum);
    }

    private function recordApplied(string $name, string $checksum): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO schema_migrations (filename, checksum) VALUES (:filename, :checksum)'
        );
        $stmt->execute([':filename' => $name, ':checksum' => $checksum]);
    }
}
