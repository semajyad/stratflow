<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Models;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\Database;
use StratFlow\Models\KeyResult;

/**
 * KeyResultTest
 *
 * Unit tests for the KeyResult model — all DB calls mocked.
 */
class KeyResultTest extends TestCase
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
        $db->method('lastInsertId')->willReturn('15');
        return $db;
    }

    private function krRow(): array
    {
        return [
            'id'              => 15,
            'org_id'          => 1,
            'hl_work_item_id' => 10,
            'title'           => 'Reduce load time to < 2s',
            'unit'            => 'seconds',
            'status'          => 'not_started',
            'display_order'   => 0,
        ];
    }

    // ===========================
    // CREATE
    // ===========================

    #[Test]
    public function createReturnsInsertedId(): void
    {
        $db = $this->makeDb();
        $id = KeyResult::create($db, [
            'org_id'          => 1,
            'hl_work_item_id' => 10,
            'title'           => 'Reduce load time',
        ]);
        $this->assertSame(15, $id);
    }

    #[Test]
    public function createUsesDefaultStatusNotStarted(): void
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

        KeyResult::create($db, ['org_id' => 1, 'hl_work_item_id' => 10, 'title' => 'KR']);
        $this->assertSame('not_started', $capturedParams[':status']);
    }

    #[Test]
    public function createConvertsNumericStringsToFloat(): void
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

        KeyResult::create($db, [
            'org_id'          => 1,
            'hl_work_item_id' => 10,
            'title'           => 'KR',
            'baseline_value'  => '0',
            'target_value'    => '100',
        ]);
        $this->assertSame(0.0, $capturedParams[':baseline_value']);
        $this->assertSame(100.0, $capturedParams[':target_value']);
    }

    #[Test]
    public function createMapsEmptyStringValuesToNull(): void
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

        KeyResult::create($db, [
            'org_id'          => 1,
            'hl_work_item_id' => 10,
            'title'           => 'KR',
            'baseline_value'  => '',
            'target_value'    => '',
        ]);
        $this->assertNull($capturedParams[':baseline_value']);
        $this->assertNull($capturedParams[':target_value']);
    }

    // ===========================
    // FIND BY WORK ITEM ID
    // ===========================

    #[Test]
    public function findByWorkItemIdReturnsArray(): void
    {
        $db   = $this->makeDb($this->krRow());
        $rows = KeyResult::findByWorkItemId($db, 10, 1);
        $this->assertIsArray($rows);
        $this->assertCount(1, $rows);
    }

    #[Test]
    public function findByWorkItemIdReturnsEmptyArrayWhenNone(): void
    {
        $db   = $this->makeDb(null);
        $rows = KeyResult::findByWorkItemId($db, 99, 1);
        $this->assertSame([], $rows);
    }

    // ===========================
    // FIND BY WORK ITEM IDS (bulk)
    // ===========================

    #[Test]
    public function findByWorkItemIdsReturnsEmptyForEmptyInput(): void
    {
        $db     = $this->createMock(Database::class);
        $db->expects($this->never())->method('query');
        $result = KeyResult::findByWorkItemIds($db, [], 1);
        $this->assertSame([], $result);
    }

    #[Test]
    public function findByWorkItemIdsGroupsByWorkItemId(): void
    {
        $rows = [
            ['id' => 1, 'hl_work_item_id' => 10, 'title' => 'KR 1', 'display_order' => 0],
            ['id' => 2, 'hl_work_item_id' => 10, 'title' => 'KR 2', 'display_order' => 1],
            ['id' => 3, 'hl_work_item_id' => 11, 'title' => 'KR 3', 'display_order' => 0],
        ];
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetchAll')->willReturn($rows);
        $db = $this->createMock(Database::class);
        $db->method('query')->willReturn($stmt);

        $grouped = KeyResult::findByWorkItemIds($db, [10, 11], 1);
        $this->assertArrayHasKey(10, $grouped);
        $this->assertArrayHasKey(11, $grouped);
        $this->assertCount(2, $grouped[10]);
        $this->assertCount(1, $grouped[11]);
    }

    // ===========================
    // FIND BY ID
    // ===========================

    #[Test]
    public function findByIdReturnsMappedRowWhenFound(): void
    {
        $db  = $this->makeDb($this->krRow());
        $row = KeyResult::findById($db, 15, 1);
        $this->assertIsArray($row);
        $this->assertSame(15, $row['id']);
    }

    #[Test]
    public function findByIdReturnsNullWhenNotFound(): void
    {
        $db  = $this->makeDb(null);
        $row = KeyResult::findById($db, 999, 1);
        $this->assertNull($row);
    }

    // ===========================
    // FIND BY PROJECT OKRS
    // ===========================

    #[Test]
    public function findByProjectOkrsReturnsArray(): void
    {
        $db   = $this->makeDb($this->krRow());
        $rows = KeyResult::findByProjectOkrs($db, 1, 1);
        $this->assertIsArray($rows);
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
        KeyResult::update($db, 15, 1, ['title' => 'Updated KR title']);
        $this->assertTrue($queryCalled);
    }

    #[Test]
    public function updateWithNoAllowedColumnsSkipsQuery(): void
    {
        $db = $this->createMock(Database::class);
        $db->expects($this->never())->method('query');
        KeyResult::update($db, 15, 1, ['hl_work_item_id' => 99]);
    }

    // ===========================
    // DELETE
    // ===========================

    #[Test]
    public function deleteCallsQueryWithIdAndOrgId(): void
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
        KeyResult::delete($db, 15, 1);
        $this->assertSame(15, $capturedParams[':id']);
        $this->assertSame(1, $capturedParams[':oid']);
    }
}
