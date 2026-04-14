<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Models;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\Database;
use StratFlow\Models\UserStory;

/**
 * UserStoryTest
 *
 * Unit tests for UserStory model — all DB calls mocked.
 */
class UserStoryTest extends TestCase
{
    // ===========================
    // HELPERS
    // ===========================

    private function makeDb(?array $fetchRow = null, int $rowCount = 0): Database
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn($fetchRow ?? false);
        $stmt->method('fetchAll')->willReturn($fetchRow ? [$fetchRow] : []);
        $stmt->method('rowCount')->willReturn($fetchRow ? 1 : $rowCount);

        $db = $this->createMock(Database::class);
        $db->method('query')->willReturn($stmt);
        $db->method('lastInsertId')->willReturn('42');
        return $db;
    }

    private function storyRow(): array
    {
        return [
            'id'             => 42,
            'project_id'     => 1,
            'title'          => 'As a user I want to login',
            'priority_number' => 1,
            'status'         => 'backlog',
            'parent_title'   => null,
        ];
    }

    // ===========================
    // CREATE
    // ===========================

    #[Test]
    public function createReturnsInsertedId(): void
    {
        $db = $this->makeDb();
        $id = UserStory::create($db, [
            'project_id'     => 1,
            'priority_number' => 1,
            'title'          => 'Login story',
        ]);
        $this->assertSame(42, $id);
    }

    #[Test]
    public function createUsesDefaultStatusBacklog(): void
    {
        $queryCalled = false;
        $capturedParams = null;

        $stmt = $this->createMock(\PDOStatement::class);
        $db   = $this->createMock(Database::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params = []) use ($stmt, &$queryCalled, &$capturedParams): \PDOStatement {
                $queryCalled = true;
                $capturedParams = $params;
                return $stmt;
            }
        );
        $db->method('lastInsertId')->willReturn('7');

        UserStory::create($db, ['project_id' => 1, 'priority_number' => 2, 'title' => 'Story']);

        $this->assertTrue($queryCalled);
        $this->assertSame('backlog', $capturedParams[':status']);
    }

    // ===========================
    // FIND BY PROJECT ID
    // ===========================

    #[Test]
    public function findByProjectIdReturnsArray(): void
    {
        $db = $this->makeDb($this->storyRow());
        $rows = UserStory::findByProjectId($db, 1);
        $this->assertIsArray($rows);
        $this->assertCount(1, $rows);
        $this->assertSame(42, $rows[0]['id']);
    }

    #[Test]
    public function findByProjectIdReturnsEmptyArrayWhenNone(): void
    {
        $db = $this->makeDb(null);
        $rows = UserStory::findByProjectId($db, 99);
        $this->assertSame([], $rows);
    }

    // ===========================
    // FIND BY PARENT ID
    // ===========================

    #[Test]
    public function findByParentIdReturnsMatchingStories(): void
    {
        $db = $this->makeDb($this->storyRow());
        $rows = UserStory::findByParentId($db, 5);
        $this->assertCount(1, $rows);
    }

    // ===========================
    // FIND BY ID
    // ===========================

    #[Test]
    public function findByIdReturnsMappedRowWhenFound(): void
    {
        $db = $this->makeDb($this->storyRow());
        $row = UserStory::findById($db, 42);
        $this->assertIsArray($row);
        $this->assertSame(42, $row['id']);
        $this->assertSame('As a user I want to login', $row['title']);
    }

    #[Test]
    public function findByIdReturnsNullWhenNotFound(): void
    {
        $db = $this->makeDb(null);
        $row = UserStory::findById($db, 999);
        $this->assertNull($row);
    }

    // ===========================
    // FIND UNALLOCATED
    // ===========================

    #[Test]
    public function findUnallocatedReturnsArray(): void
    {
        $db = $this->makeDb($this->storyRow());
        $rows = UserStory::findUnallocated($db, 1);
        $this->assertIsArray($rows);
    }

    // ===========================
    // COUNT
    // ===========================

    #[Test]
    public function countByProjectIdReturnsInteger(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(['cnt' => '5']);
        $db = $this->createMock(Database::class);
        $db->method('query')->willReturn($stmt);

        $count = UserStory::countByProjectId($db, 1);
        $this->assertSame(5, $count);
    }

    #[Test]
    public function countByProjectIdReturnsZeroWhenNoRows(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);
        $db = $this->createMock(Database::class);
        $db->method('query')->willReturn($stmt);

        $count = UserStory::countByProjectId($db, 99);
        $this->assertSame(0, $count);
    }

    // ===========================
    // UPDATE
    // ===========================

    #[Test]
    public function updateWithValidColumnCallsQuery(): void
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
        UserStory::update($db, 1, ['title' => 'Updated title']);
        $this->assertTrue($queryCalled);
    }

    #[Test]
    public function updateWithNoAllowedColumnsSkipsQuery(): void
    {
        $db = $this->createMock(Database::class);
        $db->expects($this->never())->method('query');
        UserStory::update($db, 1, ['nonexistent_column' => 'value']);
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
        UserStory::delete($db, 7);
        $this->assertSame(7, $capturedParams[':id']);
    }

    #[Test]
    public function deleteByProjectIdCallsQueryWithProjectId(): void
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
        UserStory::deleteByProjectId($db, 3);
        $this->assertSame(3, $capturedParams[':project_id']);
    }

    // ===========================
    // QUALITY STATE HELPERS
    // ===========================

    #[Test]
    public function markQualityPendingCallsUpdateWithPendingStatus(): void
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
        UserStory::markQualityPending($db, 1);
        $this->assertSame('pending', $capturedParams[':quality_status']);
        $this->assertSame(0, $capturedParams[':quality_attempts']);
    }

    #[Test]
    public function markQualityScoredCallsUpdateWithScoredStatus(): void
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
        UserStory::markQualityScored($db, 1, 85, ['invest' => 4]);
        $this->assertSame('scored', $capturedParams[':quality_status']);
        $this->assertSame(85, $capturedParams[':quality_score']);
    }

    #[Test]
    public function markQualityFailedRecordsAttemptsAndError(): void
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
        UserStory::markQualityFailed($db, 1, 3, 'exc:RuntimeException');
        $this->assertSame('failed', $capturedParams[':quality_status']);
        $this->assertSame(3, $capturedParams[':quality_attempts']);
        $this->assertSame('exc:RuntimeException', $capturedParams[':quality_error']);
    }
}
