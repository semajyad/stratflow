<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Models;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\Database;
use StratFlow\Models\AuditLog;

/**
 * AuditLogTest
 *
 * Unit tests for the AuditLog model — all DB calls mocked.
 */
class AuditLogTest extends TestCase
{
    // ===========================
    // HELPERS
    // ===========================

    private function makeDb(?array $fetchRow = null): Database
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn($fetchRow ?? false);
        $stmt->method('fetchAll')->willReturn($fetchRow ? [$fetchRow] : []);

        $db = $this->createMock(Database::class);
        $db->method('query')->willReturn($stmt);
        return $db;
    }

    private function logRow(): array
    {
        return [
            'id'         => 1,
            'user_id'    => 99,
            'event_type' => 'login_success',
            'ip_address' => '127.0.0.1',
            'full_name'  => 'Alice',
            'email'      => 'alice@example.com',
            'created_at' => '2026-01-01 12:00:00',
        ];
    }

    // ===========================
    // FIND BY USER ID
    // ===========================

    #[Test]
    public function findByUserIdReturnsArray(): void
    {
        $db   = $this->makeDb($this->logRow());
        $rows = AuditLog::findByUserId($db, 99);
        $this->assertIsArray($rows);
        $this->assertCount(1, $rows);
        $this->assertSame('login_success', $rows[0]['event_type']);
    }

    #[Test]
    public function findByUserIdReturnsEmptyArrayWhenNone(): void
    {
        $db   = $this->makeDb(null);
        $rows = AuditLog::findByUserId($db, 999);
        $this->assertSame([], $rows);
    }

    #[Test]
    public function findByUserIdPassesUserIdParam(): void
    {
        $capturedParams = null;
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetchAll')->willReturn([]);
        $db = $this->createMock(Database::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params) use ($stmt, &$capturedParams): \PDOStatement {
                $capturedParams = $params;
                return $stmt;
            }
        );
        AuditLog::findByUserId($db, 42);
        $this->assertSame(42, $capturedParams['uid']);
    }

    // ===========================
    // FIND BY EVENT TYPE
    // ===========================

    #[Test]
    public function findByEventTypeReturnsMatchingRows(): void
    {
        $db   = $this->makeDb($this->logRow());
        $rows = AuditLog::findByEventType($db, 'login_success');
        $this->assertIsArray($rows);
        $this->assertCount(1, $rows);
    }

    #[Test]
    public function findByEventTypePassesTypeParam(): void
    {
        $capturedParams = null;
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetchAll')->willReturn([]);
        $db = $this->createMock(Database::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params) use ($stmt, &$capturedParams): \PDOStatement {
                $capturedParams = $params;
                return $stmt;
            }
        );
        AuditLog::findByEventType($db, 'logout');
        $this->assertSame('logout', $capturedParams['type']);
    }

    #[Test]
    public function findByEventTypeReturnsEmptyWhenNone(): void
    {
        $db   = $this->makeDb(null);
        $rows = AuditLog::findByEventType($db, 'unknown_event');
        $this->assertSame([], $rows);
    }

    // ===========================
    // FIND RECENT
    // ===========================

    #[Test]
    public function findRecentReturnsArray(): void
    {
        $db   = $this->makeDb($this->logRow());
        $rows = AuditLog::findRecent($db);
        $this->assertIsArray($rows);
        $this->assertCount(1, $rows);
    }

    #[Test]
    public function findRecentReturnsEmptyWhenNone(): void
    {
        $db   = $this->makeDb(null);
        $rows = AuditLog::findRecent($db, 50);
        $this->assertSame([], $rows);
    }

    #[Test]
    public function findRecentPassesLimitParam(): void
    {
        $capturedParams = null;
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetchAll')->willReturn([]);
        $db = $this->createMock(Database::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params) use ($stmt, &$capturedParams): \PDOStatement {
                $capturedParams = $params;
                return $stmt;
            }
        );
        AuditLog::findRecent($db, 25);
        $this->assertSame(25, $capturedParams['lim']);
    }

    // ===========================
    // FIND FILTERED
    // ===========================

    #[Test]
    public function findFilteredReturnsAllRowsWithNoFilters(): void
    {
        $db   = $this->makeDb($this->logRow());
        $rows = AuditLog::findFiltered($db);
        $this->assertIsArray($rows);
    }

    #[Test]
    public function findFilteredWithOrgIdAppendsOrgParam(): void
    {
        $capturedParams = null;
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetchAll')->willReturn([]);
        $db = $this->createMock(Database::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params) use ($stmt, &$capturedParams): \PDOStatement {
                $capturedParams = $params;
                return $stmt;
            }
        );
        AuditLog::findFiltered($db, orgId: 5);
        $this->assertSame(5, $capturedParams[':org_id']);
    }

    #[Test]
    public function findFilteredWithEventTypeAppendsEventTypeParam(): void
    {
        $capturedParams = null;
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetchAll')->willReturn([]);
        $db = $this->createMock(Database::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params) use ($stmt, &$capturedParams): \PDOStatement {
                $capturedParams = $params;
                return $stmt;
            }
        );
        AuditLog::findFiltered($db, eventType: 'login_success');
        $this->assertSame('login_success', $capturedParams[':event_type']);
    }

    #[Test]
    public function findFilteredWithDateRangeAppendsDateParams(): void
    {
        $capturedParams = null;
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetchAll')->willReturn([]);
        $db = $this->createMock(Database::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params) use ($stmt, &$capturedParams): \PDOStatement {
                $capturedParams = $params;
                return $stmt;
            }
        );
        AuditLog::findFiltered($db, dateFrom: '2026-01-01', dateTo: '2026-01-31');
        $this->assertArrayHasKey(':date_from', $capturedParams);
        $this->assertArrayHasKey(':date_to', $capturedParams);
        $this->assertStringContainsString('2026-01-01', $capturedParams[':date_from']);
        $this->assertStringContainsString('2026-01-31', $capturedParams[':date_to']);
    }
}
