<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Core;

use PHPUnit\Framework\Attributes\Test;
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

    #[Test]
    public function checkAllowsWhenUnderLimit(): void
    {
        $db = $this->makeDb(count: 2);
        $this->assertTrue(RateLimiter::check($db, 'login', '1.2.3.4', 5, 300));
    }

    #[Test]
    public function checkBlocksWhenAtLimit(): void
    {
        $db = $this->makeDb(count: 5);
        $this->assertFalse(RateLimiter::check($db, 'login', '1.2.3.4', 5, 300));
    }

    #[Test]
    public function checkBlocksWhenOverLimit(): void
    {
        $db = $this->makeDb(count: 99);
        $this->assertFalse(RateLimiter::check($db, 'login', '1.2.3.4', 5, 300));
    }

    #[Test]
    public function checkFailsOpenWhenTableMissing(): void
    {
        $db = $this->makeDb(tableExists: false);
        $this->assertTrue(RateLimiter::check($db, 'login', '1.2.3.4', 5, 300));
    }

    #[Test]
    public function checkFailsOpenOnDbException(): void
    {
        $db = $this->createMock(Database::class);
        $db->method('tableExists')->willReturn(true);
        $db->method('query')->willThrowException(new \RuntimeException('DB down'));

        $this->assertTrue(RateLimiter::check($db, 'login', '1.2.3.4', 5, 300));
    }

    // ===========================
    // record()
    // ===========================

    #[Test]
    public function recordInsertsRowWhenTableExists(): void
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

    #[Test]
    public function recordSkipsWhenTableMissing(): void
    {
        $db = $this->createMock(Database::class);
        $db->method('tableExists')->willReturn(false);
        $db->expects($this->never())->method('query');

        RateLimiter::record($db, 'login', '1.2.3.4');
    }

    #[Test]
    public function recordSilentlyHandlesDbException(): void
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

    #[Test]
    public function cleanupDeletesOldRows(): void
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

    #[Test]
    public function cleanupSkipsWhenTableMissing(): void
    {
        $db = $this->createMock(Database::class);
        $db->method('tableExists')->willReturn(false);
        $db->expects($this->never())->method('query');

        RateLimiter::cleanup($db);
    }

    // ===========================
    // constants
    // ===========================

    #[Test]
    public function keyConstantsAreDefined(): void
    {
        $this->assertSame('password_reset', RateLimiter::PASSWORD_RESET);
        $this->assertSame('api_gemini', RateLimiter::API_GEMINI);
        $this->assertSame('file_upload', RateLimiter::FILE_UPLOAD);
        $this->assertSame('admin_action', RateLimiter::ADMIN_ACTION);
    }
}
