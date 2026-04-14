<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use StratFlow\Core\Database;
use StratFlow\Core\RateLimiter;

/**
 * RateLimiterTest
 *
 * Tests rate-limit check/record/cleanup logic using a mocked Database.
 * Covers: under-limit (allow), at-limit (block), missing table (fail-open),
 * and DB exception (fail-open).
 */
class RateLimiterTest extends TestCase
{
    // ===========================
    // HELPERS
    // ===========================

    private function makeDb(int $count = 0, bool $tableExists = true): Database
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(['cnt' => $count]);
        $stmt->method('fetchAll')->willReturn([]);

        $db = $this->createMock(Database::class);
        $db->method('tableExists')->willReturn($tableExists);
        $db->method('query')->willReturn($stmt);

        return $db;
    }

    // ===========================
    // check()
    // ===========================

    public function testCheckAllowsWhenUnderLimit(): void
    {
        $db = $this->makeDb(count: 2);
        $this->assertTrue(RateLimiter::check($db, 'login', '1.2.3.4', 5, 300));
    }

    public function testCheckBlocksWhenAtLimit(): void
    {
        $db = $this->makeDb(count: 5);
        $this->assertFalse(RateLimiter::check($db, 'login', '1.2.3.4', 5, 300));
    }

    public function testCheckBlocksWhenOverLimit(): void
    {
        $db = $this->makeDb(count: 99);
        $this->assertFalse(RateLimiter::check($db, 'login', '1.2.3.4', 5, 300));
    }

    public function testCheckFailsOpenWhenTableMissing(): void
    {
        $db = $this->makeDb(tableExists: false);
        $this->assertTrue(RateLimiter::check($db, 'login', '1.2.3.4', 5, 300));
    }

    public function testCheckFailsOpenOnDbException(): void
    {
        $db = $this->createMock(Database::class);
        $db->method('tableExists')->willReturn(true);
        $db->method('query')->willThrowException(new \RuntimeException('DB down'));

        $this->assertTrue(RateLimiter::check($db, 'login', '1.2.3.4', 5, 300));
    }

    // ===========================
    // record()
    // ===========================

    public function testRecordInsertsRowWhenTableExists(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $db   = $this->createMock(Database::class);
        $db->method('tableExists')->willReturn(true);
        $db->expects($this->once())
           ->method('query')
           ->with(
               $this->stringContains('INSERT INTO rate_limits'),
               $this->arrayHasKey('key')
           )
           ->willReturn($stmt);

        RateLimiter::record($db, RateLimiter::PASSWORD_RESET, '1.2.3.4');
    }

    public function testRecordSkipsWhenTableMissing(): void
    {
        $db = $this->createMock(Database::class);
        $db->method('tableExists')->willReturn(false);
        $db->expects($this->never())->method('query');

        RateLimiter::record($db, 'login', '1.2.3.4');
    }

    public function testRecordSilentlyHandlesDbException(): void
    {
        $db = $this->createMock(Database::class);
        $db->method('tableExists')->willReturn(true);
        $db->method('query')->willThrowException(new \RuntimeException('Insert failed'));

        // Must not throw
        RateLimiter::record($db, 'login', '1.2.3.4');
        $this->assertTrue(true);
    }

    // ===========================
    // cleanup()
    // ===========================

    public function testCleanupDeletesOldRows(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $db   = $this->createMock(Database::class);
        $db->method('tableExists')->willReturn(true);
        $db->expects($this->once())
           ->method('query')
           ->with($this->stringContains('DELETE FROM rate_limits'))
           ->willReturn($stmt);

        RateLimiter::cleanup($db);
    }

    public function testCleanupSkipsWhenTableMissing(): void
    {
        $db = $this->createMock(Database::class);
        $db->method('tableExists')->willReturn(false);
        $db->expects($this->never())->method('query');

        RateLimiter::cleanup($db);
    }

    // ===========================
    // constants
    // ===========================

    public function testKeyConstantsAreDefined(): void
    {
        $this->assertSame('password_reset', RateLimiter::PASSWORD_RESET);
        $this->assertSame('api_gemini', RateLimiter::API_GEMINI);
        $this->assertSame('file_upload', RateLimiter::FILE_UPLOAD);
        $this->assertSame('admin_action', RateLimiter::ADMIN_ACTION);
    }
}
