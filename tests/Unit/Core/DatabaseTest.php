<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Core;

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
}
