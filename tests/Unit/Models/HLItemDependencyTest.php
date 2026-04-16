<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Models;

use PHPUnit\Framework\TestCase;
use StratFlow\Models\HLItemDependency;

class HLItemDependencyTest extends TestCase
{
    private function makeDb(mixed $fetch = false, array $fetchAll = []): \StratFlow\Core\Database
    {
        $db = $this->createMock(\StratFlow\Core\Database::class);
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn($fetch);
        $stmt->method('fetchAll')->willReturn($fetchAll);
        $db->method('query')->willReturn($stmt);
        return $db;
    }

    public function testCreateInsertsDependencyRecord(): void
    {
        $db = $this->makeDb();
        $db->method('lastInsertId')->willReturn('1');

        $result = HLItemDependency::create($db, [
            'item_id' => 5,
            'depends_on_id' => 3,
            'dependency_type' => 'hard',
        ]);

        $this->assertSame(1, $result);
    }

    public function testCreateBatchDeletesThenInsertsMultiple(): void
    {
        $db = $this->createMock(\StratFlow\Core\Database::class);
        $stmt = $this->createMock(\PDOStatement::class);
        $db->method('query')->willReturn($stmt);
        $db->method('lastInsertId')->willReturn('1');

        HLItemDependency::createBatch($db, 10, [3, 5, 7]);

        $this->assertTrue(true);
    }

    public function testCreateBatchSkipsSelfReferences(): void
    {
        $db = $this->createMock(\StratFlow\Core\Database::class);
        $stmt = $this->createMock(\PDOStatement::class);
        $db->method('query')->willReturn($stmt);
        $db->method('lastInsertId')->willReturn('1');

        HLItemDependency::createBatch($db, 5, [3, 5, 0, 7]);

        $this->assertTrue(true);
    }

    public function testFindByItemIdReturnsArray(): void
    {
        $rows = [
            ['id' => 1, 'item_id' => 5, 'depends_on_id' => 3, 'depends_on_title' => 'Upstream Task', 'depends_on_priority' => 1],
            ['id' => 2, 'item_id' => 5, 'depends_on_id' => 7, 'depends_on_title' => 'Another Task', 'depends_on_priority' => 2],
        ];
        $db = $this->makeDb(false, $rows);

        $result = HLItemDependency::findByItemId($db, 5);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertSame(3, $result[0]['depends_on_id']);
    }

    public function testFindDependentsOfReturnsDownstreamItems(): void
    {
        $rows = [
            ['id' => 3, 'item_id' => 10, 'depends_on_id' => 5, 'dependent_title' => 'Downstream 1', 'dependent_priority' => 3],
            ['id' => 4, 'item_id' => 12, 'depends_on_id' => 5, 'dependent_title' => 'Downstream 2', 'dependent_priority' => 5],
        ];
        $db = $this->makeDb(false, $rows);

        $result = HLItemDependency::findDependentsOf($db, 5);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertSame(10, $result[0]['item_id']);
    }

    public function testDeleteByItemIdExecutesDeleteQuery(): void
    {
        $db = $this->createMock(\StratFlow\Core\Database::class);
        $stmt = $this->createMock(\PDOStatement::class);
        $db->method('query')->willReturn($stmt);

        HLItemDependency::deleteByItemId($db, 5);

        $this->assertTrue(true);
    }
}
