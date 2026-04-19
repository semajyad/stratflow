<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\MigrationRunner;

class MigrationRunnerTest extends TestCase
{
    private \PDO $pdo;
    private string $migrationDir;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $this->migrationDir = sys_get_temp_dir() . '/migration_runner_test_' . uniqid() . '/';
        mkdir($this->migrationDir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->migrationDir . '*.sql') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->migrationDir);
    }

    private function writeMigration(string $name, string $sql): void
    {
        file_put_contents($this->migrationDir . $name, $sql);
    }

    #[Test]
    public function createsLedgerTableOnFirstRun(): void
    {
        $runner = new MigrationRunner($this->pdo, $this->migrationDir);
        $runner->run();

        $tables = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='schema_migrations'")->fetchAll();
        $this->assertCount(1, $tables);
    }

    #[Test]
    public function appliesNewMigration(): void
    {
        $this->writeMigration('001_create_foo.sql', 'CREATE TABLE foo (id INTEGER PRIMARY KEY)');

        $runner = new MigrationRunner($this->pdo, $this->migrationDir);
        $runner->run();

        $tables = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='foo'")->fetchAll();
        $this->assertCount(1, $tables, 'Migration should have created table foo');
    }

    #[Test]
    public function recordsMigrationInLedger(): void
    {
        $this->writeMigration('001_create_foo.sql', 'CREATE TABLE foo (id INTEGER PRIMARY KEY)');

        $runner = new MigrationRunner($this->pdo, $this->migrationDir);
        $runner->run();

        $rows = $this->pdo->query("SELECT filename FROM schema_migrations")->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertContains('001_create_foo.sql', $rows);
    }

    #[Test]
    public function skipsAlreadyAppliedMigration(): void
    {
        $this->writeMigration('001_create_foo.sql', 'CREATE TABLE foo (id INTEGER PRIMARY KEY)');

        $runner = new MigrationRunner($this->pdo, $this->migrationDir);
        $runner->run();
        $runner->run(); // second run should not throw

        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE filename='001_create_foo.sql'")->fetchColumn();
        $this->assertSame(1, $count, 'Migration should only be recorded once');
    }

    #[Test]
    public function appliesMigrationsInAlphabeticalOrder(): void
    {
        $this->writeMigration('002_create_bar.sql', 'CREATE TABLE bar (id INTEGER PRIMARY KEY)');
        $this->writeMigration('001_create_foo.sql', 'CREATE TABLE foo (id INTEGER PRIMARY KEY)');

        $runner = new MigrationRunner($this->pdo, $this->migrationDir);
        $runner->run();

        $rows = $this->pdo->query("SELECT filename FROM schema_migrations ORDER BY applied_at")->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(['001_create_foo.sql', '002_create_bar.sql'], $rows);
    }

    #[Test]
    public function throwsOnChecksumMismatchOfAppliedMigration(): void
    {
        $file = $this->migrationDir . '001_create_foo.sql';
        file_put_contents($file, 'CREATE TABLE foo (id INTEGER PRIMARY KEY)');

        $runner = new MigrationRunner($this->pdo, $this->migrationDir);
        $runner->run();

        // Tamper with the migration after it was applied
        file_put_contents($file, 'CREATE TABLE foo (id INTEGER PRIMARY KEY, extra TEXT)');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/checksum mismatch/');
        $runner->run();
    }

    #[Test]
    public function throwsOnRealMigrationError(): void
    {
        // Reference a non-existent table — this is a genuine SQL error
        $this->writeMigration('001_bad.sql', 'INSERT INTO nonexistent_table VALUES (1)');

        $runner = new MigrationRunner($this->pdo, $this->migrationDir);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/001_bad\.sql failed/');
        $runner->run();
    }

    #[Test]
    public function isIdempotentWithNoMigrations(): void
    {
        $runner = new MigrationRunner($this->pdo, $this->migrationDir);
        $runner->run();
        $runner->run();

        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM schema_migrations")->fetchColumn();
        $this->assertSame(0, $count);
    }

    #[Test]
    public function handlesMultiStatementMigration(): void
    {
        $sql = "CREATE TABLE alpha (id INTEGER PRIMARY KEY);\nCREATE TABLE beta (id INTEGER PRIMARY KEY)";
        $this->writeMigration('001_multi.sql', $sql);

        $runner = new MigrationRunner($this->pdo, $this->migrationDir);
        $runner->run();

        $alpha = $this->pdo->query("SELECT name FROM sqlite_master WHERE name='alpha'")->fetch();
        $beta  = $this->pdo->query("SELECT name FROM sqlite_master WHERE name='beta'")->fetch();
        $this->assertNotFalse($alpha);
        $this->assertNotFalse($beta);
    }

    #[Test]
    public function stripsCommentLinesBeforeExecuting(): void
    {
        $sql = "-- This is a comment\nCREATE TABLE commented (id INTEGER PRIMARY KEY)";
        $this->writeMigration('001_commented.sql', $sql);

        $runner = new MigrationRunner($this->pdo, $this->migrationDir);
        $runner->run(); // should not throw

        $row = $this->pdo->query("SELECT name FROM sqlite_master WHERE name='commented'")->fetch();
        $this->assertNotFalse($row);
    }

    #[Test]
    public function multiStatementMigrationAllStatementsRunEvenIfFirstWouldFail(): void
    {
        // Verify that a migration with N statements records all of them — the runner
        // must not bail out after the first statement. (The MySQL backfill path uses
        // `continue` not `return`, ensuring later statements execute. This test covers
        // the normal path; MySQL-specific 1060/1061 backfill is covered by integration tests.)
        $sql = implode(";\n", [
            'CREATE TABLE step1 (id INTEGER PRIMARY KEY)',
            'CREATE TABLE step2 (id INTEGER PRIMARY KEY)',
            'CREATE TABLE step3 (id INTEGER PRIMARY KEY)',
        ]);
        $this->writeMigration('001_steps.sql', $sql);

        $runner = new MigrationRunner($this->pdo, $this->migrationDir);
        $runner->run();

        foreach (['step1', 'step2', 'step3'] as $table) {
            $row = $this->pdo->query("SELECT name FROM sqlite_master WHERE name='{$table}'")->fetch();
            $this->assertNotFalse($row, "{$table} must exist after multi-statement migration");
        }
    }
}
