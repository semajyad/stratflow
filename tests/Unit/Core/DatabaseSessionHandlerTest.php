<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use StratFlow\Core\DatabaseSessionHandler;

/**
 * DatabaseSessionHandlerTest
 *
 * Tests session read/write/destroy/gc against a mock PDO so we exercise
 * the handler logic without a real database. A real-DB integration test
 * covers the full session lifecycle in tests/Integration/.
 */
class DatabaseSessionHandlerTest extends TestCase
{
    private \PDO             $pdo;
    private \PDOStatement    $stmt;
    private DatabaseSessionHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stmt = $this->createMock(\PDOStatement::class);
        $this->pdo  = $this->createMock(\PDO::class);
        $this->pdo->method('prepare')->willReturn($this->stmt);
        $this->handler = new DatabaseSessionHandler($this->pdo);
    }

    // ===========================
    // open / close
    // ===========================

    public function testOpenReturnsTrue(): void
    {
        $this->assertTrue($this->handler->open('/tmp', 'PHPSESSID'));
    }

    public function testCloseReturnsTrue(): void
    {
        $this->assertTrue($this->handler->close());
    }

    // ===========================
    // read
    // ===========================

    public function testReadReturnsDataWhenSessionExists(): void
    {
        $this->stmt->method('execute')->willReturn(true);
        $this->stmt->method('fetch')->willReturn(['data' => 'serialized-data']);

        $result = $this->handler->read('session-id-123');
        $this->assertSame('serialized-data', $result);
    }

    public function testReadReturnsEmptyStringWhenSessionMissing(): void
    {
        $this->stmt->method('execute')->willReturn(true);
        $this->stmt->method('fetch')->willReturn(false);

        $result = $this->handler->read('missing-id');
        $this->assertSame('', $result);
    }

    // ===========================
    // write
    // ===========================

    public function testWriteReturnsTrueOnSuccess(): void
    {
        $this->stmt->method('execute')->willReturn(true);
        $this->assertTrue($this->handler->write('session-id', 'session-data'));
    }

    // ===========================
    // destroy
    // ===========================

    public function testDestroyReturnsTrueOnSuccess(): void
    {
        $this->stmt->method('execute')->willReturn(true);
        $this->assertTrue($this->handler->destroy('session-id-123'));
    }

    // ===========================
    // gc
    // ===========================

    public function testGcReturnsTrueOrRowCount(): void
    {
        $this->stmt->method('execute')->willReturn(true);
        $this->stmt->method('rowCount')->willReturn(3);

        $result = $this->handler->gc(1440);
        // PHP 8 gc() returns int|false; any non-false value is valid
        $this->assertNotFalse($result);
    }
}
