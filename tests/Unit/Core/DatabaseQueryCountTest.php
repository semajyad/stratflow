<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Core;

use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\Database;

#[CoversClass(Database::class)]
class DatabaseQueryCountTest extends TestCase
{
    private Database $db;

    protected function setUp(): void
    {
        parent::setUp();

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE t (v INTEGER)');
        $pdo->exec('INSERT INTO t VALUES (1)');

        // Inject the in-memory PDO into a fresh Database via reflection
        $this->db = $this->createDatabaseWithPdo($pdo);
    }

    private function createDatabaseWithPdo(PDO $pdo): Database
    {
        $db = (new \ReflectionClass(Database::class))->newInstanceWithoutConstructor();
        $pdoProp = new \ReflectionProperty(Database::class, 'pdo');
        $pdoProp->setValue($db, $pdo);
        $countProp = new \ReflectionProperty(Database::class, 'queryCount');
        $countProp->setValue($db, 0);
        return $db;
    }

    public function testInitialQueryCountIsZero(): void
    {
        $this->assertSame(0, $this->db->getQueryCount());
    }

    public function testQueryCountIncrementsPerQuery(): void
    {
        $this->db->query('SELECT * FROM t');
        $this->assertSame(1, $this->db->getQueryCount());

        $this->db->query('SELECT * FROM t');
        $this->assertSame(2, $this->db->getQueryCount());
    }

    public function testResetQueryCountResetsToZero(): void
    {
        $this->db->query('SELECT * FROM t');
        $this->db->query('SELECT * FROM t');
        $this->assertSame(2, $this->db->getQueryCount());

        $this->db->resetQueryCount();
        $this->assertSame(0, $this->db->getQueryCount());
    }

    public function testQueryCountIsIndependentAcrossInstances(): void
    {
        $pdo2 = new PDO('sqlite::memory:');
        $pdo2->exec('CREATE TABLE t (v INTEGER)');
        $db2 = $this->createDatabaseWithPdo($pdo2);

        $this->db->query('SELECT * FROM t');
        $this->assertSame(1, $this->db->getQueryCount());
        $this->assertSame(0, $db2->getQueryCount());
    }
}
