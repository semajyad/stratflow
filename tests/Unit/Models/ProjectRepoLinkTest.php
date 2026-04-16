<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Models;

use PHPUnit\Framework\TestCase;
use StratFlow\Models\ProjectRepoLink;

class ProjectRepoLinkTest extends TestCase
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

    public function testCreateInsertsNewLink(): void
    {
        $db = $this->makeDb();
        $db->method('lastInsertId')->willReturn('100');

        $result = ProjectRepoLink::create($db, 10, 20, 1, 5);

        $this->assertSame(100, $result);
    }

    public function testCreateReturnsZeroOnDuplicate(): void
    {
        $db = $this->makeDb();
        $db->method('lastInsertId')->willReturn('0');

        $result = ProjectRepoLink::create($db, 10, 20, 1);

        $this->assertSame(0, $result);
    }

    public function testFindRepoIdsByProjectReturnsArray(): void
    {
        $rows = [
            ['integration_repo_id' => 5],
            ['integration_repo_id' => 7],
            ['integration_repo_id' => 9],
        ];
        $db = $this->makeDb(false, $rows);

        $result = ProjectRepoLink::findRepoIdsByProject($db, 10, 1);

        $this->assertIsArray($result);
        $this->assertCount(3, $result);
        $this->assertContains(5, $result);
        $this->assertContains(7, $result);
    }

    public function testFindProjectIdsByRepoReturnsArray(): void
    {
        $rows = [
            ['project_id' => 10],
            ['project_id' => 15],
            ['project_id' => 20],
        ];
        $db = $this->makeDb(false, $rows);

        $result = ProjectRepoLink::findProjectIdsByRepo($db, 5, 1);

        $this->assertIsArray($result);
        $this->assertCount(3, $result);
        $this->assertContains(10, $result);
    }

    public function testDeleteRemovesSpecificLink(): void
    {
        $db = $this->createMock(\StratFlow\Core\Database::class);
        $stmt = $this->createMock(\PDOStatement::class);

        // Verify that query() is called with a DELETE statement for the specific link
        $db->expects($this->once())
            ->method('query')
            ->with($this->stringContains('DELETE'))
            ->willReturn($stmt);

        ProjectRepoLink::delete($db, 10, 20, 1);

        $this->assertTrue(true);
    }

    public function testDeleteAllForProjectRemovesAllRepoLinks(): void
    {
        $db = $this->createMock(\StratFlow\Core\Database::class);
        $stmt = $this->createMock(\PDOStatement::class);

        // Verify that query() is called with a DELETE statement for project repos
        $db->expects($this->once())
            ->method('query')
            ->with($this->stringContains('DELETE'))
            ->willReturn($stmt);

        ProjectRepoLink::deleteAllForProject($db, 10, 1);

        $this->assertTrue(true);
    }
}
