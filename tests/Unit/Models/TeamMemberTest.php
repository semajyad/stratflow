<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Models;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\Database;
use StratFlow\Models\TeamMember;

/**
 * TeamMemberTest
 *
 * Unit tests for the TeamMember junction model — all DB calls mocked.
 */
class TeamMemberTest extends TestCase
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
    // ADD MEMBER
    // ===========================

    #[Test]
    public function addMemberCallsQueryWithTeamAndUserId(): void
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
        TeamMember::addMember($db, 4, 12);
        $this->assertSame(4, $capturedParams[':team_id']);
        $this->assertSame(12, $capturedParams[':user_id']);
    }

    // ===========================
    // REMOVE MEMBER
    // ===========================

    #[Test]
    public function removeMemberCallsQueryWithTeamAndUserId(): void
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
        TeamMember::removeMember($db, 4, 12);
        $this->assertSame(4, $capturedParams[':team_id']);
        $this->assertSame(12, $capturedParams[':user_id']);
    }

    // ===========================
    // FIND BY TEAM ID
    // ===========================

    #[Test]
    public function findByTeamIdReturnsMemberArray(): void
    {
        $memberRow = ['id' => 12, 'full_name' => 'Alice', 'email' => 'alice@example.com', 'role' => 'user', 'is_active' => 1];
        $db        = $this->makeDb($memberRow);
        $rows      = TeamMember::findByTeamId($db, 4);
        $this->assertIsArray($rows);
        $this->assertCount(1, $rows);
        $this->assertSame('Alice', $rows[0]['full_name']);
    }

    #[Test]
    public function findByTeamIdReturnsEmptyArrayWhenNoMembers(): void
    {
        $db   = $this->makeDb(null);
        $rows = TeamMember::findByTeamId($db, 99);
        $this->assertSame([], $rows);
    }

    // ===========================
    // FIND TEAMS FOR USER
    // ===========================

    #[Test]
    public function findTeamsForUserReturnsTeamArray(): void
    {
        $teamRow = ['id' => 4, 'org_id' => 1, 'name' => 'Platform Team'];
        $db      = $this->makeDb($teamRow);
        $rows    = TeamMember::findTeamsForUser($db, 12);
        $this->assertIsArray($rows);
        $this->assertCount(1, $rows);
        $this->assertSame('Platform Team', $rows[0]['name']);
    }

    #[Test]
    public function findTeamsForUserReturnsEmptyArrayWhenUserInNoTeam(): void
    {
        $db   = $this->makeDb(null);
        $rows = TeamMember::findTeamsForUser($db, 999);
        $this->assertSame([], $rows);
    }

    // ===========================
    // QUERY PARAMETERS
    // ===========================

    #[Test]
    public function findByTeamIdPassesCorrectTeamIdParam(): void
    {
        $capturedParams = null;
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetchAll')->willReturn([]);
        $db = $this->createMock(Database::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params) use ($stmt, &$capturedParams): \PDOStatement {
                $capturedParams = $params;
                return $stmt;
            }
        );
        TeamMember::findByTeamId($db, 7);
        $this->assertSame(7, $capturedParams[':team_id']);
    }

    #[Test]
    public function findTeamsForUserPassesCorrectUserIdParam(): void
    {
        $capturedParams = null;
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetchAll')->willReturn([]);
        $db = $this->createMock(Database::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params) use ($stmt, &$capturedParams): \PDOStatement {
                $capturedParams = $params;
                return $stmt;
            }
        );
        TeamMember::findTeamsForUser($db, 42);
        $this->assertSame(42, $capturedParams[':user_id']);
    }
}
