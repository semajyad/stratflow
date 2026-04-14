<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Models;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\Database;
use StratFlow\Models\StrategicBaseline;

/**
 * StrategicBaselineTest
 *
 * Unit tests for the StrategicBaseline model — all DB calls mocked.
 */
class StrategicBaselineTest extends TestCase
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
        $db->method('lastInsertId')->willReturn('11');
        return $db;
    }

    private function baselineRow(): array
    {
        return [
            'id'            => 11,
            'project_id'    => 1,
            'snapshot_json' => '{"stories":5,"risks":2}',
            'created_at'    => '2026-01-01 09:00:00',
        ];
    }

    // ===========================
    // CREATE
    // ===========================

    #[Test]
    public function createReturnsInsertedId(): void
    {
        $db = $this->makeDb();
        $id = StrategicBaseline::create($db, [
            'project_id'    => 1,
            'snapshot_json' => '{}',
        ]);
        $this->assertSame(11, $id);
    }

    #[Test]
    public function createEncodesArraySnapshotJson(): void
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

        StrategicBaseline::create($db, [
            'project_id'    => 1,
            'snapshot_json' => ['stories' => 5, 'risks' => 2],
        ]);
        $this->assertSame('{"stories":5,"risks":2}', $capturedParams[':snapshot_json']);
    }

    #[Test]
    public function createPassesStringSnapshotJsonUnchanged(): void
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

        StrategicBaseline::create($db, [
            'project_id'    => 1,
            'snapshot_json' => '{"raw":"json"}',
        ]);
        $this->assertSame('{"raw":"json"}', $capturedParams[':snapshot_json']);
    }

    #[Test]
    public function createPassesProjectIdToQuery(): void
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

        StrategicBaseline::create($db, ['project_id' => 7, 'snapshot_json' => '{}']);
        $this->assertSame(7, $capturedParams[':project_id']);
    }

    // ===========================
    // FIND LATEST BY PROJECT ID
    // ===========================

    #[Test]
    public function findLatestByProjectIdReturnsRowWhenFound(): void
    {
        $db  = $this->makeDb($this->baselineRow());
        $row = StrategicBaseline::findLatestByProjectId($db, 1);
        $this->assertIsArray($row);
        $this->assertSame(11, $row['id']);
        $this->assertSame('{"stories":5,"risks":2}', $row['snapshot_json']);
    }

    #[Test]
    public function findLatestByProjectIdReturnsNullWhenNotFound(): void
    {
        $db  = $this->makeDb(null);
        $row = StrategicBaseline::findLatestByProjectId($db, 99);
        $this->assertNull($row);
    }

    #[Test]
    public function findLatestByProjectIdPassesProjectIdToQuery(): void
    {
        $capturedParams = null;
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);
        $db = $this->createMock(Database::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params) use ($stmt, &$capturedParams): \PDOStatement {
                $capturedParams = $params;
                return $stmt;
            }
        );
        StrategicBaseline::findLatestByProjectId($db, 12);
        $this->assertSame(12, $capturedParams[':project_id']);
    }

    // ===========================
    // FIND BY PROJECT ID
    // ===========================

    #[Test]
    public function findByProjectIdReturnsArray(): void
    {
        $db   = $this->makeDb($this->baselineRow());
        $rows = StrategicBaseline::findByProjectId($db, 1);
        $this->assertIsArray($rows);
        $this->assertCount(1, $rows);
        $this->assertSame(11, $rows[0]['id']);
    }

    #[Test]
    public function findByProjectIdReturnsEmptyArrayWhenNone(): void
    {
        $db   = $this->makeDb(null);
        $rows = StrategicBaseline::findByProjectId($db, 99);
        $this->assertSame([], $rows);
    }

    #[Test]
    public function findByProjectIdPassesProjectIdToQuery(): void
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
        StrategicBaseline::findByProjectId($db, 8);
        $this->assertSame(8, $capturedParams[':project_id']);
    }
}
