<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Core;

use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use StratFlow\Core\Database;

class DatabaseTest extends TestCase
{
    #[Test]
    public function beginTransactionMethodExistsWithCorrectSignature(): void
    {
        $r = new ReflectionClass(Database::class);
        $this->assertTrue($r->hasMethod('beginTransaction'), 'Database must have beginTransaction()');
        $m = $r->getMethod('beginTransaction');
        $this->assertSame('bool', (string) $m->getReturnType());
        $this->assertCount(0, $m->getParameters());
    }

    #[Test]
    public function commitMethodExistsWithCorrectSignature(): void
    {
        $r = new ReflectionClass(Database::class);
        $this->assertTrue($r->hasMethod('commit'), 'Database must have commit()');
        $m = $r->getMethod('commit');
        $this->assertSame('bool', (string) $m->getReturnType());
        $this->assertCount(0, $m->getParameters());
    }

    #[Test]
    public function rollbackMethodExistsWithCorrectSignature(): void
    {
        $r = new ReflectionClass(Database::class);
        $this->assertTrue($r->hasMethod('rollback'), 'Database must have rollback()');
        $m = $r->getMethod('rollback');
        $this->assertSame('bool', (string) $m->getReturnType());
        $this->assertCount(0, $m->getParameters());
    }

    #[Test]
    public function queryMethodIsPublic(): void
    {
        $r = new ReflectionClass(Database::class);
        $this->assertTrue($r->hasMethod('query'));
        $this->assertTrue($r->getMethod('query')->isPublic());
    }

    #[Test]
    public function lastInsertIdMethodIsPublic(): void
    {
        $r = new ReflectionClass(Database::class);
        $this->assertTrue($r->hasMethod('lastInsertId'));
        $this->assertTrue($r->getMethod('lastInsertId')->isPublic());
    }

    // ===========================
    // Query budget counter
    // ===========================

    private function makeSqliteDatabase(): Database
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE t (v INTEGER)');
        $db = (new ReflectionClass(Database::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(Database::class, 'pdo'))->setValue($db, $pdo);
        (new \ReflectionProperty(Database::class, 'queryCount'))->setValue($db, 0);
        return $db;
    }

    #[Test]
    public function queryCountStartsAtZero(): void
    {
        $this->assertSame(0, $this->makeSqliteDatabase()->getQueryCount());
    }

    #[Test]
    public function queryCountIncrementsPerExecution(): void
    {
        $db = $this->makeSqliteDatabase();
        $db->query('SELECT 1');
        $db->query('SELECT 1');
        $this->assertSame(2, $db->getQueryCount());
    }

    #[Test]
    public function resetQueryCountResetsToZero(): void
    {
        $db = $this->makeSqliteDatabase();
        $db->query('SELECT 1');
        $db->resetQueryCount();
        $this->assertSame(0, $db->getQueryCount());
    }
}
