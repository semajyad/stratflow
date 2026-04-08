<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\Database;

/**
 * DatabaseTest
 *
 * Tests PDO connection, query execution, and prepared statement binding
 * against the real Docker MySQL instance (host=mysql, db=stratflow).
 */
class DatabaseTest extends TestCase
{
    // ===========================
    // CONFIG
    // ===========================

    private static array $config;

    // ===========================
    // CONNECTION
    // ===========================

    public static function setUpBeforeClass(): void
    {
        self::$config = getTestDbConfig();
    }

    #[Test]
    public function testConnectionSucceeds(): void
    {
        $db = new Database(self::$config);

        // If connection failed, constructor would throw — reaching here means success
        $this->assertInstanceOf(Database::class, $db);
    }

    #[Test]
    public function testGetPdoReturnsPdoInstance(): void
    {
        $db  = new Database(self::$config);
        $pdo = $db->getPdo();

        $this->assertInstanceOf(\PDO::class, $pdo);
    }

    // ===========================
    // QUERY EXECUTION
    // ===========================

    #[Test]
    public function testQueryReturnsStatement(): void
    {
        $db   = new Database(self::$config);
        $stmt = $db->query('SELECT 1 AS val');

        $this->assertInstanceOf(\PDOStatement::class, $stmt);
    }

    #[Test]
    public function testQueryFetchesExpectedRow(): void
    {
        $db  = new Database(self::$config);
        $row = $db->query('SELECT 42 AS answer')->fetch();

        $this->assertIsArray($row);
        $this->assertSame('42', (string) $row['answer']);
    }

    #[Test]
    public function testQueryWithBoundParams(): void
    {
        $db  = new Database(self::$config);
        $row = $db->query('SELECT ? AS num', [99])->fetch();

        $this->assertSame('99', (string) $row['num']);
    }

    #[Test]
    public function testQueryAgainstRealTable(): void
    {
        $db   = new Database(self::$config);
        $stmt = $db->query('SELECT COUNT(*) AS cnt FROM organisations');
        $row  = $stmt->fetch();

        $this->assertArrayHasKey('cnt', $row);
        $this->assertIsNumeric($row['cnt']);
    }

    #[Test]
    public function testInvalidSqlThrowsException(): void
    {
        $this->expectException(\PDOException::class);

        $db = new Database(self::$config);
        $db->query('THIS IS NOT SQL');
    }
}
