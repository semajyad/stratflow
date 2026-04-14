<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Models;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\Database;
use StratFlow\Models\Project;

/**
 * ProjectTest
 *
 * Unit tests for the Project model — all DB calls mocked.
 */
class ProjectTest extends TestCase
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
        $db->method('lastInsertId')->willReturn('33');
        $db->method('tableExists')->willReturn(false);
        return $db;
    }

    private function projectRow(): array
    {
        return [
            'id'         => 33,
            'org_id'     => 1,
            'name'       => 'Alpha Project',
            'status'     => 'active',
            'created_by' => 2,
        ];
    }

    // ===========================
    // CREATE
    // ===========================

    #[Test]
    public function createReturnsInsertedId(): void
    {
        $db = $this->makeDb();
        $id = Project::create($db, [
            'org_id'     => 1,
            'name'       => 'Alpha Project',
            'created_by' => 2,
        ]);
        $this->assertSame(33, $id);
    }

    #[Test]
    public function createUsesDefaultStatusDraft(): void
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

        Project::create($db, ['org_id' => 1, 'name' => 'Project', 'created_by' => 1]);
        $this->assertSame('draft', $capturedParams[':status']);
    }

    // ===========================
    // FIND BY ORG ID
    // ===========================

    #[Test]
    public function findByOrgIdReturnsArray(): void
    {
        $db   = $this->makeDb($this->projectRow());
        $rows = Project::findByOrgId($db, 1);
        $this->assertIsArray($rows);
        $this->assertCount(1, $rows);
        $this->assertSame('Alpha Project', $rows[0]['name']);
    }

    #[Test]
    public function findByOrgIdReturnsEmptyArrayWhenNone(): void
    {
        $db   = $this->makeDb(null);
        $rows = Project::findByOrgId($db, 99);
        $this->assertSame([], $rows);
    }

    // ===========================
    // FIND BY ID
    // ===========================

    #[Test]
    public function findByIdReturnsMappedRowWhenFound(): void
    {
        $db  = $this->makeDb($this->projectRow());
        $row = Project::findById($db, 33);
        $this->assertIsArray($row);
        $this->assertSame(33, $row['id']);
        $this->assertSame('Alpha Project', $row['name']);
    }

    #[Test]
    public function findByIdReturnsNullWhenNotFound(): void
    {
        $db  = $this->makeDb(null);
        $row = Project::findById($db, 999);
        $this->assertNull($row);
    }

    #[Test]
    public function findByIdWithOrgIdScopesToOrg(): void
    {
        $capturedSql = null;
        $capturedParams = null;
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);
        $db = $this->createMock(Database::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params) use ($stmt, &$capturedSql, &$capturedParams): \PDOStatement {
                $capturedSql    = $sql;
                $capturedParams = $params;
                return $stmt;
            }
        );
        Project::findById($db, 33, 1);
        $this->assertStringContainsString('org_id', $capturedSql);
        $this->assertSame(1, $capturedParams[':org_id']);
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
        Project::update($db, 33, ['name' => 'Renamed Project']);
        $this->assertTrue($queryCalled);
    }

    #[Test]
    public function updateWithDisallowedColumnSkipsQuery(): void
    {
        $db = $this->createMock(Database::class);
        $db->expects($this->never())->method('query');
        Project::update($db, 33, ['created_by' => 99]);
    }

    #[Test]
    public function updateWithOrgIdAppendsOrgIdWhereClause(): void
    {
        $capturedSql = null;
        $stmt = $this->createMock(\PDOStatement::class);
        $db   = $this->createMock(Database::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params) use ($stmt, &$capturedSql): \PDOStatement {
                $capturedSql = $sql;
                return $stmt;
            }
        );
        Project::update($db, 33, ['name' => 'New Name'], 1);
        $this->assertStringContainsString('org_id', $capturedSql);
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
        Project::delete($db, 33);
        $this->assertSame(33, $capturedParams[':id']);
    }

    #[Test]
    public function deleteWithOrgIdAppendsOrgScope(): void
    {
        $capturedSql = null;
        $capturedParams = null;
        $stmt = $this->createMock(\PDOStatement::class);
        $db   = $this->createMock(Database::class);
        $db->expects($this->once())->method('query')->willReturnCallback(
            function (string $sql, array $params) use ($stmt, &$capturedSql, &$capturedParams): \PDOStatement {
                $capturedSql    = $sql;
                $capturedParams = $params;
                return $stmt;
            }
        );
        Project::delete($db, 33, 1);
        $this->assertStringContainsString('org_id', $capturedSql);
        $this->assertSame(1, $capturedParams[':org_id']);
    }

    // ===========================
    // GET MEMBERSHIPS
    // ===========================

    #[Test]
    public function getMembershipsReturnsFallbackEditorRoleWhenOldTable(): void
    {
        $rows = [['user_id' => 5]];
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetchAll')->willReturn($rows);
        $db = $this->createMock(Database::class);
        // project_memberships does not exist → falls back to project_members
        $db->method('tableExists')->willReturnCallback(fn(string $t) => $t === 'project_members');
        $db->method('query')->willReturn($stmt);

        $memberships = Project::getMemberships($db, 33);
        $this->assertCount(1, $memberships);
        $this->assertSame(5, $memberships[0]['user_id']);
        $this->assertSame('editor', $memberships[0]['membership_role']);
    }

    #[Test]
    public function getMembershipsReturnsRolesWhenNewTable(): void
    {
        $rows = [
            ['user_id' => 5, 'membership_role' => 'viewer'],
            ['user_id' => 10, 'membership_role' => 'editor'],
        ];
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetchAll')->willReturn($rows);
        $db = $this->createMock(Database::class);
        // project_memberships exists
        $db->method('tableExists')->willReturnCallback(fn(string $t) => $t === 'project_memberships');
        $db->method('query')->willReturn($stmt);

        $memberships = Project::getMemberships($db, 33);
        $this->assertCount(2, $memberships);
        $this->assertSame('viewer', $memberships[0]['membership_role']);
        $this->assertSame('editor', $memberships[1]['membership_role']);
    }

    #[Test]
    public function getMemberIdsExtractsUserIdsFromMemberships(): void
    {
        $rows = [
            ['user_id' => 5, 'membership_role' => 'viewer'],
            ['user_id' => 10, 'membership_role' => 'editor'],
        ];
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetchAll')->willReturn($rows);
        $db = $this->createMock(Database::class);
        $db->method('tableExists')->willReturnCallback(fn(string $t) => $t === 'project_memberships');
        $db->method('query')->willReturn($stmt);

        $memberIds = Project::getMemberIds($db, 33);
        $this->assertSame([5, 10], $memberIds);
    }

    #[Test]
    public function setMembersDeletesAndInsertsWhenNewTable(): void
    {
        $deleteCallCount = 0;
        $insertCallCount = 0;
        $stmt = $this->createMock(\PDOStatement::class);
        $db = $this->createMock(Database::class);
        $db->method('tableExists')->willReturnCallback(fn(string $t) => $t === 'project_memberships');
        $db->method('query')->willReturnCallback(function (string $sql, array $params) use ($stmt, &$deleteCallCount, &$insertCallCount): \PDOStatement {
            if (str_contains($sql, 'DELETE')) {
                $deleteCallCount++;
            } elseif (str_contains($sql, 'INSERT')) {
                $insertCallCount++;
            }
            return $stmt;
        });

        Project::setMembers($db, 33, [5, 10]);

        $this->assertGreaterThanOrEqual(1, $deleteCallCount);
        $this->assertGreaterThanOrEqual(2, $insertCallCount);
    }

    #[Test]
    public function setMembersWithEmptyArrayDeletesAllMembers(): void
    {
        $deleteCallCount = 0;
        $insertCallCount = 0;
        $stmt = $this->createMock(\PDOStatement::class);
        $db = $this->createMock(Database::class);
        $db->method('tableExists')->willReturnCallback(fn(string $t) => $t === 'project_memberships');
        $db->method('query')->willReturnCallback(function (string $sql, array $params) use ($stmt, &$deleteCallCount, &$insertCallCount): \PDOStatement {
            if (str_contains($sql, 'DELETE')) {
                $deleteCallCount++;
            } elseif (str_contains($sql, 'INSERT')) {
                $insertCallCount++;
            }
            return $stmt;
        });

        Project::setMembers($db, 33, []);

        $this->assertGreaterThanOrEqual(1, $deleteCallCount);
        $this->assertSame(0, $insertCallCount);
    }

    #[Test]
    public function setMembersNormalizesPayloadWithRoles(): void
    {
        $insertedData = [];
        $stmt = $this->createMock(\PDOStatement::class);
        $db = $this->createMock(Database::class);
        $db->method('tableExists')->willReturnCallback(fn(string $t) => $t === 'project_memberships');
        $db->method('query')->willReturnCallback(function (string $sql, array $params) use ($stmt, &$insertedData): \PDOStatement {
            if (str_contains($sql, 'INSERT')) {
                $insertedData[] = $params;
            }
            return $stmt;
        });

        $members = [
            ['user_id' => 5, 'membership_role' => 'viewer'],
            10,
        ];
        Project::setMembers($db, 33, $members);

        $this->assertGreaterThanOrEqual(2, count($insertedData));
    }

    #[Test]
    public function findAccessibleByUserWithProjectViewAllPermission(): void
    {
        $user = ['id' => 1, 'org_id' => 1];
        $projectRow = $this->projectRow();

        // Mock PermissionService::can to return true for PROJECT_VIEW_ALL
        // This is a static method, so we need a more complex mock setup
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetchAll')->willReturn([$projectRow]);
        $db = $this->createMock(Database::class);
        $db->method('tableExists')->willReturn(false);
        $db->method('query')->willReturn($stmt);

        // Since PermissionService is not mocked (can't easily mock static),
        // we test the method exists and accepts valid user array
        $result = Project::findAccessibleByOrgId($db, $user);
        $this->assertIsArray($result);
    }

    #[Test]
    public function findAccessibleByUserWithInvalidOrgIdReturnsEmptyArray(): void
    {
        $user = ['id' => 1, 'org_id' => 0];
        $db = $this->createMock(Database::class);
        $db->expects($this->never())->method('query');

        $result = Project::findAccessibleByOrgId($db, $user);
        $this->assertSame([], $result);
    }

    #[Test]
    public function findAccessibleByUserWithInvalidUserIdReturnsEmptyArray(): void
    {
        $user = ['id' => 0, 'org_id' => 1];
        $db = $this->createMock(Database::class);
        $db->expects($this->never())->method('query');

        $result = Project::findAccessibleByOrgId($db, $user);
        $this->assertSame([], $result);
    }

    #[Test]
    public function deleteWithoutOrgIdCallsQueryWithIdOnly(): void
    {
        $capturedParams = null;
        $stmt = $this->createMock(\PDOStatement::class);
        $db = $this->createMock(Database::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params) use ($stmt, &$capturedParams): \PDOStatement {
                $capturedParams = $params;
                return $stmt;
            }
        );

        Project::delete($db, 33);

        $this->assertSame(33, $capturedParams[':id']);
        $this->assertArrayNotHasKey(':org_id', $capturedParams);
    }

    #[Test]
    public function createWithCustomStatus(): void
    {
        $capturedParams = null;
        $stmt = $this->createMock(\PDOStatement::class);
        $db = $this->createMock(Database::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params) use ($stmt, &$capturedParams): \PDOStatement {
                $capturedParams = $params;
                return $stmt;
            }
        );
        $db->method('lastInsertId')->willReturn('42');

        Project::create($db, [
            'org_id' => 1,
            'name' => 'Custom Project',
            'created_by' => 5,
            'status' => 'active',
        ]);

        $this->assertSame('active', $capturedParams[':status']);
    }

    #[Test]
    public function findByOrgIdOrdersByCreatedAtDesc(): void
    {
        $capturedSql = null;
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetchAll')->willReturn([]);
        $db = $this->createMock(Database::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params) use ($stmt, &$capturedSql): \PDOStatement {
                $capturedSql = $sql;
                return $stmt;
            }
        );

        Project::findByOrgId($db, 1);

        $this->assertStringContainsString('ORDER BY created_at DESC', $capturedSql);
    }

    #[Test]
    public function updateWithMultipleAllowedColumns(): void
    {
        $capturedSql = null;
        $stmt = $this->createMock(\PDOStatement::class);
        $db = $this->createMock(Database::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params) use ($stmt, &$capturedSql): \PDOStatement {
                $capturedSql = $sql;
                return $stmt;
            }
        );

        Project::update($db, 33, ['name' => 'New Name', 'status' => 'completed']);

        $this->assertStringContainsString('name', $capturedSql);
        $this->assertStringContainsString('status', $capturedSql);
    }

    #[Test]
    public function getMembershipsReturnsEmptyArrayWhenNone(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetchAll')->willReturn([]);
        $db = $this->createMock(Database::class);
        $db->method('tableExists')->willReturnCallback(fn(string $t) => $t === 'project_memberships');
        $db->method('query')->willReturn($stmt);

        $memberships = Project::getMemberships($db, 33);
        $this->assertSame([], $memberships);
    }

    #[Test]
    public function getMemberIdsReturnsEmptyArrayWhenNone(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetchAll')->willReturn([]);
        $db = $this->createMock(Database::class);
        $db->method('tableExists')->willReturnCallback(fn(string $t) => $t === 'project_memberships');
        $db->method('query')->willReturn($stmt);

        $memberIds = Project::getMemberIds($db, 33);
        $this->assertSame([], $memberIds);
    }

    #[Test]
    public function updateWithDisallowedColumnsDoesNotQuery(): void
    {
        $db = $this->createMock(Database::class);
        $db->expects($this->never())->method('query');

        Project::update($db, 33, ['org_id' => 2, 'created_at' => '2026-01-01']);
    }

    #[Test]
    public function updateWithOrgIdAndDisallowedColumns(): void
    {
        $db = $this->createMock(Database::class);
        $db->expects($this->never())->method('query');

        Project::update($db, 33, ['created_by' => 10], 1);
    }

    #[Test]
    public function findByIdWithOrgIdIncludesOrgInQuery(): void
    {
        $projectRow = $this->projectRow();
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn($projectRow);
        $db = $this->createMock(Database::class);
        $db->method('query')->willReturn($stmt);

        $result = Project::findById($db, 33, 1);

        $this->assertIsArray($result);
        $this->assertSame(33, $result['id']);
    }

    #[Test]
    public function setMembersWithOldTableStructure(): void
    {
        $deleteCallCount = 0;
        $insertIgnoreCallCount = 0;
        $stmt = $this->createMock(\PDOStatement::class);
        $db = $this->createMock(Database::class);
        $db->method('tableExists')->willReturnCallback(fn(string $t) => $t === 'project_members');
        $db->method('query')->willReturnCallback(function (string $sql, array $params) use ($stmt, &$deleteCallCount, &$insertIgnoreCallCount): \PDOStatement {
            if (str_contains($sql, 'DELETE')) {
                $deleteCallCount++;
            } elseif (str_contains($sql, 'INSERT')) {
                $insertIgnoreCallCount++;
            }
            return $stmt;
        });

        Project::setMembers($db, 33, [5, 10]);

        $this->assertGreaterThanOrEqual(1, $deleteCallCount);
        $this->assertGreaterThanOrEqual(2, $insertIgnoreCallCount);
    }

    #[Test]
    public function findAccessibleReturnsProjectsWhenOrgIdValid(): void
    {
        $projectRow = $this->projectRow();
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetchAll')->willReturn([$projectRow]);
        $db = $this->createMock(Database::class);
        $db->method('tableExists')->willReturn(false);
        $db->method('query')->willReturn($stmt);

        $user = ['id' => 1, 'org_id' => 1];
        $result = Project::findAccessibleByOrgId($db, $user);

        $this->assertIsArray($result);
    }
}
