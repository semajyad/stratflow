<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Models;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\Database;
use StratFlow\Models\Risk;

/**
 * RiskTest
 *
 * Unit tests for the Risk model — all DB calls mocked.
 */
class RiskTest extends TestCase
{
    // ===========================
    // HELPERS
    // ===========================

    private function makeDb(?array $fetchRow = null): Database
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn($fetchRow ?? false);
        $stmt->method('fetchAll')->willReturn($fetchRow ? [$fetchRow] : []);
        $stmt->method('rowCount')->willReturn($fetchRow ? 1 : 0);

        $db = $this->createMock(Database::class);
        $db->method('query')->willReturn($stmt);
        $db->method('lastInsertId')->willReturn('10');
        return $db;
    }

    private function riskRow(): array
    {
        return [
            'id'         => 10,
            'project_id' => 1,
            'title'      => 'Key person dependency',
            'likelihood' => 4,
            'impact'     => 5,
            'mitigation' => null,
            'priority'   => null,
        ];
    }

    // ===========================
    // CREATE
    // ===========================

    #[Test]
    public function createReturnsInsertedId(): void
    {
        $db = $this->makeDb();
        $id = Risk::create($db, [
            'project_id' => 1,
            'title'      => 'Supply chain risk',
        ]);
        $this->assertSame(10, $id);
    }

    #[Test]
    public function createUsesDefaultLikelihoodAndImpact(): void
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

        Risk::create($db, ['project_id' => 1, 'title' => 'Risk']);

        $this->assertSame(3, $capturedParams[':likelihood']);
        $this->assertSame(3, $capturedParams[':impact']);
    }

    // ===========================
    // FIND BY PROJECT ID
    // ===========================

    #[Test]
    public function findByProjectIdReturnsArray(): void
    {
        $db   = $this->makeDb($this->riskRow());
        $rows = Risk::findByProjectId($db, 1);
        $this->assertIsArray($rows);
        $this->assertCount(1, $rows);
        $this->assertSame(10, $rows[0]['id']);
    }

    #[Test]
    public function findByProjectIdReturnsEmptyArrayWhenNone(): void
    {
        $db   = $this->makeDb(null);
        $rows = Risk::findByProjectId($db, 99);
        $this->assertSame([], $rows);
    }

    // ===========================
    // FIND BY ID
    // ===========================

    #[Test]
    public function findByIdReturnsMappedRowWhenFound(): void
    {
        $db  = $this->makeDb($this->riskRow());
        $row = Risk::findById($db, 10);
        $this->assertIsArray($row);
        $this->assertSame(10, $row['id']);
        $this->assertSame('Key person dependency', $row['title']);
    }

    #[Test]
    public function findByIdReturnsNullWhenNotFound(): void
    {
        $db  = $this->makeDb(null);
        $row = Risk::findById($db, 999);
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
        Risk::update($db, 10, ['title' => 'Updated risk title']);
        $this->assertTrue($queryCalled);
    }

    #[Test]
    public function updateWithDisallowedColumnSkipsQuery(): void
    {
        $db = $this->createMock(Database::class);
        $db->expects($this->never())->method('query');
        Risk::update($db, 10, ['project_id' => 99]); // not in UPDATABLE_COLUMNS
    }

    #[Test]
    public function updateWithEmptyDataSkipsQuery(): void
    {
        $db = $this->createMock(Database::class);
        $db->expects($this->never())->method('query');
        Risk::update($db, 10, []);
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
        Risk::delete($db, 10);
        $this->assertSame(10, $capturedParams[':id']);
    }

    #[Test]
    public function deleteByProjectIdScopesToProject(): void
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
        Risk::deleteByProjectId($db, 5);
        $this->assertSame(5, $capturedParams[':project_id']);
    }
}
