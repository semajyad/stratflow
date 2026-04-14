<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Models;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\Database;
use StratFlow\Models\Team;

/**
 * TeamTest
 *
 * Unit tests for the Team model — all DB calls mocked.
 */
class TeamTest extends TestCase
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
        $db->method('lastInsertId')->willReturn('8');
        return $db;
    }

    private function teamRow(): array
    {
        return [
            'id'           => 8,
            'org_id'       => 1,
            'name'         => 'Platform Team',
            'description'  => 'Handles infrastructure',
            'capacity'     => 30,
            'member_count' => 3,
        ];
    }

    // ===========================
    // CREATE
    // ===========================

    #[Test]
    public function createReturnsInsertedId(): void
    {
        $db = $this->makeDb();
        $id = Team::create($db, ['org_id' => 1, 'name' => 'Platform Team']);
        $this->assertSame(8, $id);
    }

    #[Test]
    public function createUsesDefaultCapacityZero(): void
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

        Team::create($db, ['org_id' => 1, 'name' => 'Team']);
        $this->assertSame(0, $capturedParams[':capacity']);
    }

    // ===========================
    // FIND BY ORG ID
    // ===========================

    #[Test]
    public function findByOrgIdReturnsArray(): void
    {
        $db   = $this->makeDb($this->teamRow());
        $rows = Team::findByOrgId($db, 1);
        $this->assertIsArray($rows);
        $this->assertCount(1, $rows);
        $this->assertSame('Platform Team', $rows[0]['name']);
    }

    #[Test]
    public function findByOrgIdReturnsEmptyArrayWhenNone(): void
    {
        $db   = $this->makeDb(null);
        $rows = Team::findByOrgId($db, 99);
        $this->assertSame([], $rows);
    }

    // ===========================
    // FIND BY ID
    // ===========================

    #[Test]
    public function findByIdReturnsMappedRowWhenFound(): void
    {
        $db  = $this->makeDb($this->teamRow());
        $row = Team::findById($db, 8);
        $this->assertIsArray($row);
        $this->assertSame(8, $row['id']);
        $this->assertSame('Platform Team', $row['name']);
    }

    #[Test]
    public function findByIdReturnsNullWhenNotFound(): void
    {
        $db  = $this->makeDb(null);
        $row = Team::findById($db, 999);
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
        Team::update($db, 8, ['name' => 'Platform Team Renamed']);
        $this->assertTrue($queryCalled);
    }

    #[Test]
    public function updateWithDisallowedColumnSkipsQuery(): void
    {
        $db = $this->createMock(Database::class);
        $db->expects($this->never())->method('query');
        Team::update($db, 8, ['org_id' => 2]);
    }

    #[Test]
    public function updateWithEmptyDataSkipsQuery(): void
    {
        $db = $this->createMock(Database::class);
        $db->expects($this->never())->method('query');
        Team::update($db, 8, []);
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
        Team::delete($db, 8);
        $this->assertSame(8, $capturedParams[':id']);
    }
}
