<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Models;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\Database;
use StratFlow\Models\Sprint;

/**
 * SprintTest
 *
 * Unit tests for the Sprint model — all DB calls mocked.
 */
class SprintTest extends TestCase
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
        $db->method('lastInsertId')->willReturn('5');
        return $db;
    }

    private function sprintRow(): array
    {
        return [
            'id'            => 5,
            'project_id'    => 1,
            'name'          => 'Sprint 1',
            'start_date'    => '2026-01-01',
            'end_date'      => '2026-01-14',
            'team_capacity' => 40,
        ];
    }

    // ===========================
    // CREATE
    // ===========================

    #[Test]
    public function createReturnsInsertedId(): void
    {
        $db = $this->makeDb();
        $id = Sprint::create($db, [
            'project_id' => 1,
            'name'       => 'Sprint 1',
        ]);
        $this->assertSame(5, $id);
    }

    #[Test]
    public function createBindsAllFields(): void
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
        $db->method('lastInsertId')->willReturn('3');

        Sprint::create($db, [
            'project_id'    => 2,
            'name'          => 'Sprint 2',
            'start_date'    => '2026-02-01',
            'end_date'      => '2026-02-14',
            'team_capacity' => 30,
        ]);

        $this->assertSame(2, $capturedParams[':project_id']);
        $this->assertSame('Sprint 2', $capturedParams[':name']);
        $this->assertSame(30, $capturedParams[':team_capacity']);
    }

    // ===========================
    // FIND BY PROJECT ID
    // ===========================

    #[Test]
    public function findByProjectIdReturnsArray(): void
    {
        $db   = $this->makeDb($this->sprintRow());
        $rows = Sprint::findByProjectId($db, 1);
        $this->assertIsArray($rows);
        $this->assertCount(1, $rows);
        $this->assertSame('Sprint 1', $rows[0]['name']);
    }

    #[Test]
    public function findByProjectIdReturnsEmptyArrayWhenNone(): void
    {
        $db   = $this->makeDb(null);
        $rows = Sprint::findByProjectId($db, 99);
        $this->assertSame([], $rows);
    }

    // ===========================
    // FIND BY ID
    // ===========================

    #[Test]
    public function findByIdReturnsMappedRowWhenFound(): void
    {
        $db  = $this->makeDb($this->sprintRow());
        $row = Sprint::findById($db, 5);
        $this->assertIsArray($row);
        $this->assertSame(5, $row['id']);
        $this->assertSame('Sprint 1', $row['name']);
    }

    #[Test]
    public function findByIdReturnsNullWhenNotFound(): void
    {
        $db  = $this->makeDb(null);
        $row = Sprint::findById($db, 999);
        $this->assertNull($row);
    }

    // ===========================
    // UPDATE
    // ===========================

    #[Test]
    public function updateWithAllowedColumnCallsQuery(): void
    {
        $queryCalled = false;
        $stmt = $this->createMock(\PDOStatement::class);
        $db   = $this->createMock(Database::class);
        $db->expects($this->once())->method('query')->willReturnCallback(
            function () use ($stmt, &$queryCalled): \PDOStatement {
                $queryCalled = true;
                return $stmt;
            }
        );
        Sprint::update($db, 5, ['name' => 'Sprint 1 Updated']);
        $this->assertTrue($queryCalled);
    }

    #[Test]
    public function updateWithDisallowedColumnSkipsQuery(): void
    {
        $db = $this->createMock(Database::class);
        $db->expects($this->never())->method('query');
        Sprint::update($db, 5, ['project_id' => 99]);
    }

    // ===========================
    // DELETE
    // ===========================

    #[Test]
    public function deleteCallsQueryWithCorrectId(): void
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
        Sprint::delete($db, 5);
        $this->assertSame(5, $capturedParams[':id']);
    }
}
