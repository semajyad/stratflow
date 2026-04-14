<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Models;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\Database;
use StratFlow\Models\SprintStory;

/**
 * SprintStoryTest
 *
 * Unit tests for the SprintStory junction model — all DB calls mocked.
 */
class SprintStoryTest extends TestCase
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
        return $db;
    }

    // ===========================
    // ASSIGN
    // ===========================

    #[Test]
    public function assignCallsQueryWithSprintAndStoryIds(): void
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
        SprintStory::assign($db, 3, 7);
        $this->assertSame(3, $capturedParams[':sprint_id']);
        $this->assertSame(7, $capturedParams[':user_story_id']);
    }

    // ===========================
    // UNASSIGN
    // ===========================

    #[Test]
    public function unassignCallsQueryWithBothIds(): void
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
        SprintStory::unassign($db, 3, 7);
        $this->assertSame(3, $capturedParams[':sprint_id']);
        $this->assertSame(7, $capturedParams[':user_story_id']);
    }

    // ===========================
    // FIND BY SPRINT ID
    // ===========================

    #[Test]
    public function findBySprintIdReturnsStoriesArray(): void
    {
        $storyRow = ['id' => 7, 'title' => 'My story', 'priority_number' => 1, 'parent_title' => null];
        $db       = $this->makeDb($storyRow);
        $rows     = SprintStory::findBySprintId($db, 3);
        $this->assertIsArray($rows);
        $this->assertCount(1, $rows);
        $this->assertSame(7, $rows[0]['id']);
    }

    #[Test]
    public function findBySprintIdReturnsEmptyArrayWhenNone(): void
    {
        $db   = $this->makeDb(null);
        $rows = SprintStory::findBySprintId($db, 99);
        $this->assertSame([], $rows);
    }

    // ===========================
    // FIND SPRINT FOR STORY
    // ===========================

    #[Test]
    public function findSprintForStoryReturnsSprintRowWhenFound(): void
    {
        $sprintRow = ['id' => 3, 'name' => 'Sprint 1', 'project_id' => 1];
        $db        = $this->makeDb($sprintRow);
        $row       = SprintStory::findSprintForStory($db, 7);
        $this->assertIsArray($row);
        $this->assertSame(3, $row['id']);
    }

    #[Test]
    public function findSprintForStoryReturnsNullWhenUnallocated(): void
    {
        $db  = $this->makeDb(null);
        $row = SprintStory::findSprintForStory($db, 999);
        $this->assertNull($row);
    }

    // ===========================
    // DELETE BY SPRINT ID
    // ===========================

    #[Test]
    public function deleteBySprintIdCallsQueryWithSprintId(): void
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
        SprintStory::deleteBySprintId($db, 3);
        $this->assertSame(3, $capturedParams[':sprint_id']);
    }

    // ===========================
    // GET SPRINT LOAD
    // ===========================

    #[Test]
    public function getSprintLoadReturnsTotalLoad(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(['total_load' => '42']);
        $db = $this->createMock(Database::class);
        $db->method('query')->willReturn($stmt);

        $load = SprintStory::getSprintLoad($db, 3);
        $this->assertSame(42, $load);
    }

    #[Test]
    public function getSprintLoadReturnsZeroWhenEmpty(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);
        $db = $this->createMock(Database::class);
        $db->method('query')->willReturn($stmt);

        $load = SprintStory::getSprintLoad($db, 99);
        $this->assertSame(0, $load);
    }
}
