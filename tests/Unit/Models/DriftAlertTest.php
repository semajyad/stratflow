<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Models;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\Database;
use StratFlow\Models\DriftAlert;

/**
 * DriftAlertTest
 *
 * Unit tests for the DriftAlert model — all DB calls mocked.
 */
class DriftAlertTest extends TestCase
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
        $db->method('lastInsertId')->willReturn('55');
        return $db;
    }

    private function alertRow(): array
    {
        return [
            'id'           => 55,
            'project_id'   => 1,
            'alert_type'   => 'scope_creep',
            'severity'     => 'warning',
            'details_json' => '{"extra_stories":3}',
            'status'       => 'active',
        ];
    }

    // ===========================
    // CREATE
    // ===========================

    #[Test]
    public function createReturnsInsertedId(): void
    {
        $db = $this->makeDb();
        $id = DriftAlert::create($db, [
            'project_id' => 1,
            'alert_type' => 'scope_creep',
            'details_json' => '{}',
        ]);
        $this->assertSame(55, $id);
    }

    #[Test]
    public function createUsesDefaultSeverityWarning(): void
    {
        $capturedParams = null;
        $stmt = $this->createMock(\PDOStatement::class);
        $db   = $this->createMock(Database::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params) use ($stmt, &$capturedParams): \PDOStatement {
                $capturedParams = $params;
                return $stmt;
            }
        );
        $db->method('lastInsertId')->willReturn('1');

        DriftAlert::create($db, ['project_id' => 1, 'alert_type' => 'drift', 'details_json' => '{}']);
        $this->assertSame('warning', $capturedParams[':severity']);
    }

    #[Test]
    public function createUsesDefaultStatusActive(): void
    {
        $capturedParams = null;
        $stmt = $this->createMock(\PDOStatement::class);
        $db   = $this->createMock(Database::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params) use ($stmt, &$capturedParams): \PDOStatement {
                $capturedParams = $params;
                return $stmt;
            }
        );
        $db->method('lastInsertId')->willReturn('1');

        DriftAlert::create($db, ['project_id' => 1, 'alert_type' => 'drift', 'details_json' => '{}']);
        $this->assertSame('active', $capturedParams[':status']);
    }

    #[Test]
    public function createEncodesArrayDetailsJson(): void
    {
        $capturedParams = null;
        $stmt = $this->createMock(\PDOStatement::class);
        $db   = $this->createMock(Database::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params) use ($stmt, &$capturedParams): \PDOStatement {
                $capturedParams = $params;
                return $stmt;
            }
        );
        $db->method('lastInsertId')->willReturn('1');

        DriftAlert::create($db, [
            'project_id'   => 1,
            'alert_type'   => 'scope_creep',
            'details_json' => ['extra_stories' => 3],
        ]);
        $this->assertSame('{"extra_stories":3}', $capturedParams[':details_json']);
    }

    // ===========================
    // FIND ACTIVE BY PROJECT ID
    // ===========================

    #[Test]
    public function findActiveByProjectIdReturnsArray(): void
    {
        $db   = $this->makeDb($this->alertRow());
        $rows = DriftAlert::findActiveByProjectId($db, 1);
        $this->assertIsArray($rows);
        $this->assertCount(1, $rows);
        $this->assertSame('scope_creep', $rows[0]['alert_type']);
    }

    #[Test]
    public function findActiveByProjectIdReturnsEmptyArrayWhenNone(): void
    {
        $db   = $this->makeDb(null);
        $rows = DriftAlert::findActiveByProjectId($db, 99);
        $this->assertSame([], $rows);
    }

    // ===========================
    // FIND BY PROJECT ID
    // ===========================

    #[Test]
    public function findByProjectIdReturnsAllStatuses(): void
    {
        $db   = $this->makeDb($this->alertRow());
        $rows = DriftAlert::findByProjectId($db, 1);
        $this->assertIsArray($rows);
        $this->assertCount(1, $rows);
    }

    // ===========================
    // FIND BY ID
    // ===========================

    #[Test]
    public function findByIdReturnsMappedRowWhenFound(): void
    {
        $db  = $this->makeDb($this->alertRow());
        $row = DriftAlert::findById($db, 55);
        $this->assertIsArray($row);
        $this->assertSame(55, $row['id']);
        $this->assertSame('scope_creep', $row['alert_type']);
    }

    #[Test]
    public function findByIdReturnsNullWhenNotFound(): void
    {
        $db  = $this->makeDb(null);
        $row = DriftAlert::findById($db, 999);
        $this->assertNull($row);
    }

    // ===========================
    // UPDATE STATUS
    // ===========================

    #[Test]
    public function updateStatusCallsQueryWithNewStatus(): void
    {
        $capturedParams = null;
        $stmt = $this->createMock(\PDOStatement::class);
        $db   = $this->createMock(Database::class);
        $db->expects($this->once())->method('query')->willReturnCallback(
            function (string $sql, array $params) use ($stmt, &$capturedParams): \PDOStatement {
                $capturedParams = $params;
                return $stmt;
            }
        );
        DriftAlert::updateStatus($db, 55, 'resolved');
        $this->assertSame('resolved', $capturedParams[':status']);
        $this->assertSame(55, $capturedParams[':id']);
    }

    // ===========================
    // COUNT ACTIVE
    // ===========================

    #[Test]
    public function countActiveByProjectIdReturnsInteger(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(['cnt' => '3']);
        $db = $this->createMock(Database::class);
        $db->method('query')->willReturn($stmt);

        $count = DriftAlert::countActiveByProjectId($db, 1);
        $this->assertSame(3, $count);
    }

    #[Test]
    public function countActiveByProjectIdReturnsZeroWhenNone(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);
        $db = $this->createMock(Database::class);
        $db->method('query')->willReturn($stmt);

        $count = DriftAlert::countActiveByProjectId($db, 99);
        $this->assertSame(0, $count);
    }
}
