<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Models;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\Database;
use StratFlow\Models\StoryGitLink;

/**
 * StoryGitLinkTest
 *
 * Unit tests for the StoryGitLink model — all DB calls mocked.
 */
class StoryGitLinkTest extends TestCase
{
    // ===========================
    // HELPERS
    // ===========================

    private function makeDb(?array $fetchRow = null, ?array $fetchAllRows = null, int $rowCount = 0): Database
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn($fetchRow ?? false);
        $stmt->method('fetchAll')->willReturn($fetchAllRows ?? ($fetchRow ? [$fetchRow] : []));
        $stmt->method('fetchColumn')->willReturn(isset($fetchRow['cnt']) ? $fetchRow['cnt'] : 0);
        $stmt->method('rowCount')->willReturn($rowCount);

        $db = $this->createMock(Database::class);
        $db->method('query')->willReturn($stmt);
        $db->method('lastInsertId')->willReturn('5');
        return $db;
    }

    private function makePdoDb(?array $fetchRow = null, ?array $fetchAllRows = null, int $rowCount = 0): Database
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn($fetchRow ?? false);
        $stmt->method('fetchAll')->willReturn($fetchAllRows ?? ($fetchRow ? [$fetchRow] : []));
        $stmt->method('fetchColumn')->willReturn(isset($fetchRow['cnt']) ? $fetchRow['cnt'] : 0);
        $stmt->method('rowCount')->willReturn($rowCount);
        $stmt->method('execute')->willReturn(true);

        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturn($stmt);

        $db = $this->createMock(Database::class);
        $db->method('getPdo')->willReturn($pdo);
        $db->method('query')->willReturn($stmt);
        $db->method('lastInsertId')->willReturn('5');
        return $db;
    }

    private function gitLinkRow(): array
    {
        return [
            'id'         => 1,
            'local_type' => 'user_story',
            'local_id'   => 42,
            'provider'   => 'github',
            'ref_type'   => 'pull_request',
            'ref_url'    => 'https://github.com/org/repo/pull/123',
            'ref_label'  => '#123',
            'status'     => 'open',
            'author'     => 'alice',
            'created_at' => '2025-01-15 10:30:00',
            'updated_at' => '2025-01-15 10:30:00',
        ];
    }

    private function gitLinkRow2(): array
    {
        return [
            'id'         => 2,
            'local_type' => 'user_story',
            'local_id'   => 42,
            'provider'   => 'gitlab',
            'ref_type'   => 'commit',
            'ref_url'    => 'https://gitlab.com/org/project/-/commit/abc123',
            'ref_label'  => 'abc123',
            'status'     => 'unknown',
            'author'     => 'bob',
            'created_at' => '2025-01-14 14:00:00',
            'updated_at' => '2025-01-14 14:00:00',
        ];
    }

    // ===========================
    // READ: findByLocalItem
    // ===========================

    #[Test]
    public function findByLocalItemReturnsArray(): void
    {
        $rows = [$this->gitLinkRow(), $this->gitLinkRow2()];
        $db   = $this->makeDb(null, $rows);
        $result = StoryGitLink::findByLocalItem($db, 'user_story', 42);
        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertSame(1, $result[0]['id']);
        $this->assertSame(2, $result[1]['id']);
    }

    #[Test]
    public function findByLocalItemReturnsEmptyWhenNone(): void
    {
        $db   = $this->makeDb(null, []);
        $result = StoryGitLink::findByLocalItem($db, 'user_story', 999);
        $this->assertSame([], $result);
    }

    #[Test]
    public function findByLocalItemPassesCorrectParameters(): void
    {
        $capturedParams = null;
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetchAll')->willReturn([]);
        $db   = $this->createMock(Database::class);
        $db->expects($this->once())->method('query')->willReturnCallback(
            function (string $sql, array $params) use ($stmt, &$capturedParams): \PDOStatement {
                $capturedParams = $params;
                return $stmt;
            }
        );
        StoryGitLink::findByLocalItem($db, 'hl_work_item', 77);
        $this->assertSame('hl_work_item', $capturedParams[':local_type']);
        $this->assertSame(77, $capturedParams[':local_id']);
    }

    // ===========================
    // READ: findById
    // ===========================

    #[Test]
    public function findByIdReturnsMappedRowWhenFound(): void
    {
        $db  = $this->makeDb($this->gitLinkRow());
        $row = StoryGitLink::findById($db, 1);
        $this->assertIsArray($row);
        $this->assertSame(1, $row['id']);
        $this->assertSame('https://github.com/org/repo/pull/123', $row['ref_url']);
    }

    #[Test]
    public function findByIdReturnsNullWhenNotFound(): void
    {
        $db  = $this->makeDb(null);
        $row = StoryGitLink::findById($db, 999);
        $this->assertNull($row);
    }

    #[Test]
    public function findByIdPassesCorrectId(): void
    {
        $capturedParams = null;
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);
        $db   = $this->createMock(Database::class);
        $db->expects($this->once())->method('query')->willReturnCallback(
            function (string $sql, array $params) use ($stmt, &$capturedParams): \PDOStatement {
                $capturedParams = $params;
                return $stmt;
            }
        );
        StoryGitLink::findById($db, 42);
        $this->assertSame(42, $capturedParams[':id']);
    }

    // ===========================
    // READ: findByRefUrl
    // ===========================

    #[Test]
    public function findByRefUrlReturnsMappedRowWhenFound(): void
    {
        $db  = $this->makeDb($this->gitLinkRow());
        $row = StoryGitLink::findByRefUrl($db, 'https://github.com/org/repo/pull/123');
        $this->assertIsArray($row);
        $this->assertSame('https://github.com/org/repo/pull/123', $row['ref_url']);
    }

    #[Test]
    public function findByRefUrlReturnsNullWhenNotFound(): void
    {
        $db  = $this->makeDb(null);
        $row = StoryGitLink::findByRefUrl($db, 'https://nonexistent.url');
        $this->assertNull($row);
    }

    #[Test]
    public function findByRefUrlPassesCorrectUrl(): void
    {
        $capturedParams = null;
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);
        $db   = $this->createMock(Database::class);
        $db->expects($this->once())->method('query')->willReturnCallback(
            function (string $sql, array $params) use ($stmt, &$capturedParams): \PDOStatement {
                $capturedParams = $params;
                return $stmt;
            }
        );
        StoryGitLink::findByRefUrl($db, 'https://example.com/pr/42');
        $this->assertSame('https://example.com/pr/42', $capturedParams[':ref_url']);
    }

    // ===========================
    // READ: countByLocalItem
    // ===========================

    #[Test]
    public function countByLocalItemReturnsIntCount(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetchColumn')->willReturn(5);
        $db   = $this->createMock(Database::class);
        $db->method('query')->willReturn($stmt);
        $count = StoryGitLink::countByLocalItem($db, 'user_story', 42);
        $this->assertSame(5, $count);
        $this->assertIsInt($count);
    }

    #[Test]
    public function countByLocalItemReturnsZeroWhenNone(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetchColumn')->willReturn(0);
        $db   = $this->createMock(Database::class);
        $db->method('query')->willReturn($stmt);
        $count = StoryGitLink::countByLocalItem($db, 'user_story', 999);
        $this->assertSame(0, $count);
    }

    #[Test]
    public function countByLocalItemPassesCorrectParameters(): void
    {
        $capturedParams = null;
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetchColumn')->willReturn(3);
        $db   = $this->createMock(Database::class);
        $db->expects($this->once())->method('query')->willReturnCallback(
            function (string $sql, array $params) use ($stmt, &$capturedParams): \PDOStatement {
                $capturedParams = $params;
                return $stmt;
            }
        );
        StoryGitLink::countByLocalItem($db, 'hl_work_item', 15);
        $this->assertSame('hl_work_item', $capturedParams[':local_type']);
        $this->assertSame(15, $capturedParams[':local_id']);
    }

    // ===========================
    // READ: findByLocalItemsBulk
    // ===========================

    #[Test]
    public function findByLocalItemsBulkReturnsEmptyWhenNoIds(): void
    {
        $db = $this->createMock(Database::class);
        $db->expects($this->never())->method('query');
        $result = StoryGitLink::findByLocalItemsBulk($db, 'user_story', []);
        $this->assertSame([], $result);
    }

    #[Test]
    public function findByLocalItemsBulkReturnsMappedRows(): void
    {
        $rows = [$this->gitLinkRow(), $this->gitLinkRow2()];
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetchAll')->willReturn($rows);
        $db = $this->createMock(Database::class);
        $db->expects($this->once())->method('query')->willReturnCallback(
            function (string $sql, array $params) use ($stmt): \PDOStatement {
                $this->assertSame(['user_story', 42, 43], $params);
                return $stmt;
            }
        );
        $result = StoryGitLink::findByLocalItemsBulk($db, 'user_story', [42, 43]);
        $this->assertIsArray($result);
        $this->assertArrayHasKey(42, $result);
        $this->assertCount(2, $result[42]);
    }

    #[Test]
    public function findByLocalItemsBulkGroupsByLocalId(): void
    {
        $rows = [
            array_merge($this->gitLinkRow(), ['local_id' => 10]),
            array_merge($this->gitLinkRow2(), ['local_id' => 20]),
        ];
        $db   = $this->makeDb(null, $rows);
        $result = StoryGitLink::findByLocalItemsBulk($db, 'user_story', [10, 20]);
        $this->assertArrayHasKey(10, $result);
        $this->assertArrayHasKey(20, $result);
        $this->assertCount(1, $result[10]);
        $this->assertCount(1, $result[20]);
    }

    #[Test]
    public function findByLocalItemsBulkReturnsEmptyMapWhenNoMatches(): void
    {
        $db   = $this->makeDb(null, []);
        $result = StoryGitLink::findByLocalItemsBulk($db, 'user_story', [999, 1000]);
        $this->assertSame([], $result);
    }

    // ===========================
    // READ: countsByLocalIds
    // ===========================

    #[Test]
    public function countsByLocalIdsReturnsEmptyWhenNoIds(): void
    {
        $db = $this->createMock(Database::class);
        $db->expects($this->never())->method('query');
        $result = StoryGitLink::countsByLocalIds($db, 'user_story', []);
        $this->assertSame([], $result);
    }

    #[Test]
    public function countsByLocalIdsReturnsCountMap(): void
    {
        $rows = [
            ['local_id' => '42', 'cnt' => '3'],
            ['local_id' => '43', 'cnt' => '1'],
        ];
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetchAll')->willReturn($rows);
        $db = $this->createMock(Database::class);
        $db->expects($this->once())->method('query')->willReturnCallback(
            function (string $sql, array $params) use ($stmt): \PDOStatement {
                $this->assertSame(['user_story', 42, 43], $params);
                return $stmt;
            }
        );
        $result = StoryGitLink::countsByLocalIds($db, 'user_story', [42, 43]);
        $this->assertIsArray($result);
        $this->assertSame(3, $result[42]);
        $this->assertSame(1, $result[43]);
    }

    #[Test]
    public function countsByLocalIdsReturnsZeroForMissingIds(): void
    {
        $rows = [
            ['local_id' => '42', 'cnt' => '5'],
        ];
        $db   = $this->makeDb(null, $rows);
        $result = StoryGitLink::countsByLocalIds($db, 'user_story', [42, 43]);
        $this->assertArrayHasKey(42, $result);
        $this->assertSame(5, $result[42]);
        $this->assertArrayNotHasKey(43, $result);
    }

    #[Test]
    public function countsByLocalIdsReturnsEmptyMapWhenNoMatches(): void
    {
        $db   = $this->makeDb(null, []);
        $result = StoryGitLink::countsByLocalIds($db, 'user_story', [999, 1000]);
        $this->assertSame([], $result);
    }

    // ===========================
    // CREATE
    // ===========================

    #[Test]
    public function createReturnsInsertedId(): void
    {
        $db = $this->makeDb();
        $id = StoryGitLink::create($db, [
            'local_type' => 'user_story',
            'local_id'   => 42,
            'provider'   => 'github',
            'ref_type'   => 'pull_request',
            'ref_url'    => 'https://github.com/org/repo/pull/123',
        ]);
        $this->assertSame(5, $id);
        $this->assertIsInt($id);
    }

    #[Test]
    public function createReturnsZeroForDuplicate(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $db   = $this->createMock(Database::class);
        $db->method('query')->willReturn($stmt);
        $db->method('lastInsertId')->willReturn('0');
        $id = StoryGitLink::create($db, [
            'local_type' => 'user_story',
            'local_id'   => 42,
            'provider'   => 'github',
            'ref_type'   => 'pull_request',
            'ref_url'    => 'https://github.com/org/repo/pull/123',
        ]);
        $this->assertSame(0, $id);
    }

    #[Test]
    public function createWithAllOptionalFields(): void
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
        $db->method('lastInsertId')->willReturn('10');

        StoryGitLink::create($db, [
            'local_type' => 'user_story',
            'local_id'   => 42,
            'provider'   => 'github',
            'ref_type'   => 'pull_request',
            'ref_url'    => 'https://github.com/org/repo/pull/123',
            'ref_label'  => '#123',
            'status'     => 'open',
            'author'     => 'alice',
        ]);

        $this->assertSame('user_story', $capturedParams[':local_type']);
        $this->assertSame(42, $capturedParams[':local_id']);
        $this->assertSame('github', $capturedParams[':provider']);
        $this->assertSame('pull_request', $capturedParams[':ref_type']);
        $this->assertSame('https://github.com/org/repo/pull/123', $capturedParams[':ref_url']);
        $this->assertSame('#123', $capturedParams[':ref_label']);
        $this->assertSame('open', $capturedParams[':status']);
        $this->assertSame('alice', $capturedParams[':author']);
    }

    #[Test]
    public function createWithoutOptionalFieldsUsesDefaults(): void
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
        $db->method('lastInsertId')->willReturn('10');

        StoryGitLink::create($db, [
            'local_type' => 'user_story',
            'local_id'   => 42,
            'provider'   => 'github',
            'ref_type'   => 'pull_request',
            'ref_url'    => 'https://github.com/org/repo/pull/123',
        ]);

        $this->assertNull($capturedParams[':ref_label']);
        $this->assertSame('unknown', $capturedParams[':status']);
        $this->assertNull($capturedParams[':author']);
    }

    // ===========================
    // UPDATE
    // ===========================

    #[Test]
    public function updateStatusReturnsTrueWhenRowUpdated(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('rowCount')->willReturn(1);
        $db   = $this->createMock(Database::class);
        $db->method('query')->willReturn($stmt);
        $result = StoryGitLink::updateStatus($db, 1, 'merged');
        $this->assertTrue($result);
    }

    #[Test]
    public function updateStatusReturnsFalseWhenNoRowUpdated(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('rowCount')->willReturn(0);
        $db   = $this->createMock(Database::class);
        $db->method('query')->willReturn($stmt);
        $result = StoryGitLink::updateStatus($db, 999, 'merged');
        $this->assertFalse($result);
    }

    #[Test]
    public function updateStatusPassesCorrectParameters(): void
    {
        $capturedParams = null;
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('rowCount')->willReturn(1);
        $db   = $this->createMock(Database::class);
        $db->expects($this->once())->method('query')->willReturnCallback(
            function (string $sql, array $params) use ($stmt, &$capturedParams): \PDOStatement {
                $capturedParams = $params;
                return $stmt;
            }
        );
        StoryGitLink::updateStatus($db, 42, 'closed');
        $this->assertSame('closed', $capturedParams[':status']);
        $this->assertSame(42, $capturedParams[':id']);
    }

    // ===========================
    // DELETE
    // ===========================

    #[Test]
    public function deleteByIdReturnsTrueWhenRowDeleted(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('rowCount')->willReturn(1);
        $db   = $this->createMock(Database::class);
        $db->method('query')->willReturn($stmt);
        $result = StoryGitLink::deleteById($db, 1, 'user_story', 42);
        $this->assertTrue($result);
    }

    #[Test]
    public function deleteByIdReturnsFalseWhenNoRowDeleted(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('rowCount')->willReturn(0);
        $db   = $this->createMock(Database::class);
        $db->method('query')->willReturn($stmt);
        $result = StoryGitLink::deleteById($db, 999, 'user_story', 42);
        $this->assertFalse($result);
    }

    #[Test]
    public function deleteByIdPassesCorrectParameters(): void
    {
        $capturedParams = null;
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('rowCount')->willReturn(1);
        $db   = $this->createMock(Database::class);
        $db->expects($this->once())->method('query')->willReturnCallback(
            function (string $sql, array $params) use ($stmt, &$capturedParams): \PDOStatement {
                $capturedParams = $params;
                return $stmt;
            }
        );
        StoryGitLink::deleteById($db, 5, 'hl_work_item', 77);
        $this->assertSame(5, $capturedParams[':id']);
        $this->assertSame('hl_work_item', $capturedParams[':local_type']);
        $this->assertSame(77, $capturedParams[':local_id']);
    }

    #[Test]
    public function deleteByIdScopesToLocalItem(): void
    {
        $capturedParams = null;
        $capturedSql = null;
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('rowCount')->willReturn(0);
        $db   = $this->createMock(Database::class);
        $db->expects($this->once())->method('query')->willReturnCallback(
            function (string $sql, array $params) use ($stmt, &$capturedParams, &$capturedSql): \PDOStatement {
                $capturedSql = $sql;
                $capturedParams = $params;
                return $stmt;
            }
        );
        StoryGitLink::deleteById($db, 1, 'user_story', 42);
        $this->assertStringContainsString('local_type', $capturedSql);
        $this->assertStringContainsString('local_id', $capturedSql);
    }
}
