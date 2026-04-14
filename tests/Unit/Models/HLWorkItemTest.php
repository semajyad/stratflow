<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Models;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\Database;
use StratFlow\Models\HLWorkItem;

/**
 * HLWorkItemTest
 *
 * Unit tests for the HLWorkItem model — all DB calls mocked.
 */
class HLWorkItemTest extends TestCase
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
        $db->method('lastInsertId')->willReturn('20');
        return $db;
    }

    private function workItemRow(): array
    {
        return [
            'id'             => 20,
            'project_id'     => 1,
            'title'          => 'Deploy to production',
            'priority_number' => 1,
            'status'         => 'backlog',
            'final_score'    => null,
        ];
    }

    // ===========================
    // CREATE
    // ===========================

    #[Test]
    public function createReturnsInsertedId(): void
    {
        $db = $this->makeDb();
        $id = HLWorkItem::create($db, [
            'project_id'     => 1,
            'priority_number' => 1,
            'title'          => 'Work item title',
        ]);
        $this->assertSame(20, $id);
    }

    #[Test]
    public function createUsesDefaultStatusBacklog(): void
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

        HLWorkItem::create($db, ['project_id' => 1, 'priority_number' => 1, 'title' => 'Item']);
        $this->assertSame('backlog', $capturedParams[':status']);
    }

    #[Test]
    public function createUsesDefaultEstimatedSprints(): void
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

        HLWorkItem::create($db, ['project_id' => 1, 'priority_number' => 1, 'title' => 'Item']);
        $this->assertSame(2, $capturedParams[':estimated_sprints']);
    }

    // ===========================
    // FIND BY PROJECT ID
    // ===========================

    #[Test]
    public function findByProjectIdReturnsArray(): void
    {
        $db   = $this->makeDb($this->workItemRow());
        $rows = HLWorkItem::findByProjectId($db, 1);
        $this->assertIsArray($rows);
        $this->assertCount(1, $rows);
        $this->assertSame(20, $rows[0]['id']);
    }

    #[Test]
    public function findByProjectIdReturnsEmptyArrayWhenNone(): void
    {
        $db   = $this->makeDb(null);
        $rows = HLWorkItem::findByProjectId($db, 99);
        $this->assertSame([], $rows);
    }

    // ===========================
    // FIND BY PROJECT ID RANKED
    // ===========================

    #[Test]
    public function findByProjectIdRankedByScoreReturnsArray(): void
    {
        $db   = $this->makeDb($this->workItemRow());
        $rows = HLWorkItem::findByProjectIdRankedByScore($db, 1);
        $this->assertIsArray($rows);
    }

    // ===========================
    // FIND BY ID
    // ===========================

    #[Test]
    public function findByIdReturnsMappedRowWhenFound(): void
    {
        $db  = $this->makeDb($this->workItemRow());
        $row = HLWorkItem::findById($db, 20);
        $this->assertIsArray($row);
        $this->assertSame(20, $row['id']);
        $this->assertSame('Deploy to production', $row['title']);
    }

    #[Test]
    public function findByIdReturnsNullWhenNotFound(): void
    {
        $db  = $this->makeDb(null);
        $row = HLWorkItem::findById($db, 999);
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
        HLWorkItem::update($db, 20, ['title' => 'Updated title']);
        $this->assertTrue($queryCalled);
    }

    #[Test]
    public function updateWithNoAllowedColumnsSkipsQuery(): void
    {
        $db = $this->createMock(Database::class);
        $db->expects($this->never())->method('query');
        HLWorkItem::update($db, 20, ['project_id' => 99]);
    }

    // ===========================
    // UPDATE SCORES
    // ===========================

    #[Test]
    public function updateScoresWithValidScoringColumnCallsQuery(): void
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
        HLWorkItem::updateScores($db, 20, ['final_score' => 85]);
        $this->assertTrue($queryCalled);
    }

    #[Test]
    public function updateScoresWithNoValidColumnsSkipsQuery(): void
    {
        $db = $this->createMock(Database::class);
        $db->expects($this->never())->method('query');
        HLWorkItem::updateScores($db, 20, ['fake_score' => 50]);
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
        HLWorkItem::delete($db, 20);
        $this->assertSame(20, $capturedParams[':id']);
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
        HLWorkItem::deleteByProjectId($db, 1);
        $this->assertSame(1, $capturedParams[':project_id']);
    }

    // ===========================
    // QUALITY STATE HELPERS
    // ===========================

    #[Test]
    public function markQualityPendingSetsPendingStatus(): void
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
        HLWorkItem::markQualityPending($db, 20);
        $this->assertSame('pending', $capturedParams[':quality_status']);
    }

    #[Test]
    public function markQualityScoredSetsScoredStatusAndScore(): void
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
        HLWorkItem::markQualityScored($db, 20, 72, null);
        $this->assertSame('scored', $capturedParams[':quality_status']);
        $this->assertSame(72, $capturedParams[':quality_score']);
    }
}
