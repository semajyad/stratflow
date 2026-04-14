<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Models;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\Database;
use StratFlow\Models\RiskItemLink;

/**
 * RiskItemLinkTest
 *
 * Unit tests for the RiskItemLink model — all DB calls mocked.
 */
class RiskItemLinkTest extends TestCase
{
    // ===========================
    // HELPERS
    // ===========================

    private function makeDb(?array $fetchAllRows = null): Database
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);
        $stmt->method('fetchAll')->willReturn($fetchAllRows ?? []);
        $stmt->method('rowCount')->willReturn(0);

        $db = $this->createMock(Database::class);
        $db->method('query')->willReturn($stmt);
        return $db;
    }

    // ===========================
    // CREATE
    // ===========================

    #[Test]
    public function createLinksWithEmptyArraySkipsQuery(): void
    {
        $db = $this->createMock(Database::class);
        $db->expects($this->never())->method('query');
        RiskItemLink::createLinks($db, 1, []);
        // If we reach here, test passes (no query called)
    }

    #[Test]
    public function createLinksInsertsEachWorkItemId(): void
    {
        $queryCount = 0;
        $capturedQueries = [];

        $stmt = $this->createMock(\PDOStatement::class);
        $db = $this->createMock(Database::class);
        $db->expects($this->exactly(3))->method('query')->willReturnCallback(
            function (string $sql, array $params) use ($stmt, &$queryCount, &$capturedQueries): \PDOStatement {
                $queryCount++;
                $capturedQueries[] = $params;
                return $stmt;
            }
        );

        RiskItemLink::createLinks($db, 5, [10, 20, 30]);

        $this->assertSame(3, $queryCount);
        $this->assertSame(5, $capturedQueries[0][':risk_id']);
        $this->assertSame(10, $capturedQueries[0][':work_item_id']);
        $this->assertSame(5, $capturedQueries[1][':risk_id']);
        $this->assertSame(20, $capturedQueries[1][':work_item_id']);
        $this->assertSame(5, $capturedQueries[2][':risk_id']);
        $this->assertSame(30, $capturedQueries[2][':work_item_id']);
    }

    #[Test]
    public function createLinksConvertsWorkItemIdsToInt(): void
    {
        $capturedParams = [];
        $stmt = $this->createMock(\PDOStatement::class);
        $db = $this->createMock(Database::class);
        $db->expects($this->exactly(2))->method('query')->willReturnCallback(
            function (string $sql, array $params) use ($stmt, &$capturedParams): \PDOStatement {
                $capturedParams[] = $params;
                return $stmt;
            }
        );

        RiskItemLink::createLinks($db, 1, ['5', '10']);

        // Should have called query twice (for each work item)
        // Both calls should have work_item_id as int
        $this->assertIsInt($capturedParams[0][':work_item_id']);
        $this->assertIsInt($capturedParams[1][':work_item_id']);
        $this->assertSame(5, $capturedParams[0][':work_item_id']);
        $this->assertSame(10, $capturedParams[1][':work_item_id']);
    }

    #[Test]
    public function createLinksWithSingleWorkItemId(): void
    {
        $capturedParams = null;
        $stmt = $this->createMock(\PDOStatement::class);
        $db = $this->createMock(Database::class);
        $db->expects($this->once())->method('query')->willReturnCallback(
            function (string $sql, array $params) use ($stmt, &$capturedParams): \PDOStatement {
                $capturedParams = $params;
                return $stmt;
            }
        );

        RiskItemLink::createLinks($db, 7, [15]);

        $this->assertSame(7, $capturedParams[':risk_id']);
        $this->assertSame(15, $capturedParams[':work_item_id']);
    }

    // ===========================
    // FIND BY RISK ID
    // ===========================

    #[Test]
    public function findByRiskIdReturnsWorkItemIds(): void
    {
        $fetchAllRows = [
            ['work_item_id' => 10],
            ['work_item_id' => 20],
            ['work_item_id' => 30],
        ];
        $db = $this->makeDb($fetchAllRows);

        $result = RiskItemLink::findByRiskId($db, 5);

        $this->assertIsArray($result);
        $this->assertCount(3, $result);
        $this->assertSame([10, 20, 30], $result);
    }

    #[Test]
    public function findByRiskIdReturnsEmptyArrayWhenNone(): void
    {
        $db = $this->makeDb([]);

        $result = RiskItemLink::findByRiskId($db, 999);

        $this->assertIsArray($result);
        $this->assertSame([], $result);
    }

    #[Test]
    public function findByRiskIdCallsQueryWithCorrectRiskId(): void
    {
        $capturedParams = null;
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetchAll')->willReturn([]);

        $db = $this->createMock(Database::class);
        $db->expects($this->once())->method('query')->willReturnCallback(
            function (string $sql, array $params) use ($stmt, &$capturedParams): \PDOStatement {
                $capturedParams = $params;
                return $stmt;
            }
        );

        RiskItemLink::findByRiskId($db, 42);

        $this->assertSame(42, $capturedParams[':risk_id']);
    }

    #[Test]
    public function findByRiskIdWithSingleResult(): void
    {
        $fetchAllRows = [['work_item_id' => 5]];
        $db = $this->makeDb($fetchAllRows);

        $result = RiskItemLink::findByRiskId($db, 1);

        $this->assertCount(1, $result);
        $this->assertSame([5], $result);
    }

    // ===========================
    // FIND BY WORK ITEM ID
    // ===========================

    #[Test]
    public function findByWorkItemIdReturnsRiskIds(): void
    {
        $fetchAllRows = [
            ['risk_id' => 1],
            ['risk_id' => 2],
            ['risk_id' => 3],
        ];
        $db = $this->makeDb($fetchAllRows);

        $result = RiskItemLink::findByWorkItemId($db, 10);

        $this->assertIsArray($result);
        $this->assertCount(3, $result);
        $this->assertSame([1, 2, 3], $result);
    }

    #[Test]
    public function findByWorkItemIdReturnsEmptyArrayWhenNone(): void
    {
        $db = $this->makeDb([]);

        $result = RiskItemLink::findByWorkItemId($db, 999);

        $this->assertIsArray($result);
        $this->assertSame([], $result);
    }

    #[Test]
    public function findByWorkItemIdCallsQueryWithCorrectWorkItemId(): void
    {
        $capturedParams = null;
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetchAll')->willReturn([]);

        $db = $this->createMock(Database::class);
        $db->expects($this->once())->method('query')->willReturnCallback(
            function (string $sql, array $params) use ($stmt, &$capturedParams): \PDOStatement {
                $capturedParams = $params;
                return $stmt;
            }
        );

        RiskItemLink::findByWorkItemId($db, 55);

        $this->assertSame(55, $capturedParams[':work_item_id']);
    }

    #[Test]
    public function findByWorkItemIdWithSingleResult(): void
    {
        $fetchAllRows = [['risk_id' => 8]];
        $db = $this->makeDb($fetchAllRows);

        $result = RiskItemLink::findByWorkItemId($db, 1);

        $this->assertCount(1, $result);
        $this->assertSame([8], $result);
    }

    // ===========================
    // DELETE
    // ===========================

    #[Test]
    public function deleteByRiskIdCallsQuery(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $db = $this->createMock(Database::class);
        $db->expects($this->once())->method('query')->willReturn($stmt);

        RiskItemLink::deleteByRiskId($db, 10);

        // If we reach here, query was called
    }

    #[Test]
    public function deleteByRiskIdPassesCorrectRiskId(): void
    {
        $capturedParams = null;
        $stmt = $this->createMock(\PDOStatement::class);
        $db = $this->createMock(Database::class);
        $db->expects($this->once())->method('query')->willReturnCallback(
            function (string $sql, array $params) use ($stmt, &$capturedParams): \PDOStatement {
                $capturedParams = $params;
                return $stmt;
            }
        );

        RiskItemLink::deleteByRiskId($db, 42);

        $this->assertSame(42, $capturedParams[':risk_id']);
    }

    #[Test]
    public function deleteByRiskIdWithDifferentIds(): void
    {
        $capturedIds = [];
        $stmt = $this->createMock(\PDOStatement::class);
        $db = $this->createMock(Database::class);
        $db->expects($this->exactly(2))->method('query')->willReturnCallback(
            function (string $sql, array $params) use ($stmt, &$capturedIds): \PDOStatement {
                $capturedIds[] = $params[':risk_id'];
                return $stmt;
            }
        );

        RiskItemLink::deleteByRiskId($db, 5);
        RiskItemLink::deleteByRiskId($db, 15);

        $this->assertSame([5, 15], $capturedIds);
    }
}
