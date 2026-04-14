<?php

declare(strict_types=1);

namespace StratFlow\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\Database;
use StratFlow\Models\Team;
use StratFlow\Models\TeamMember;

/**
 * TeamMemberTest
 *
 * Tests the `team_members` junction table operations against the real Docker MySQL database.
 * setUp inserts a test org, user, and team; tearDown removes all test data in FK-safe order.
 */
class TeamMemberTest extends TestCase
{
    // ===========================
    // SETUP / TEARDOWN
    // ===========================

    private static Database $db;
    private static int $orgId;
    private static int $userId;
    private static int $teamId;

    public static function setUpBeforeClass(): void
    {
        self::$db = new Database(getTestDbConfig());

        // Clean up any leftover data from a previous failed run
        self::$db->query(
            "DELETE u FROM users u
             JOIN organisations o ON u.org_id = o.id
             WHERE o.name = 'Test Org - TeamMemberTest'"
        );
        self::$db->query("DELETE FROM organisations WHERE name = 'Test Org - TeamMemberTest'");

        // Create test organisation
        self::$db->query(
            "INSERT INTO organisations (name) VALUES (?)",
            ['Test Org - TeamMemberTest']
        );
        self::$orgId = (int) self::$db->lastInsertId();

        // Create a test user
        self::$db->query(
            "INSERT INTO users (org_id, email, password_hash, full_name, role)
             VALUES (?, ?, ?, ?, ?)",
            [self::$orgId, 'teammember_test@test.invalid', password_hash('pass', PASSWORD_DEFAULT), 'Test User', 'user']
        );
        self::$userId = (int) self::$db->lastInsertId();

        // Create a test team
        self::$teamId = Team::create(self::$db, [
            'org_id' => self::$orgId,
            'name'   => 'Test Team - TeamMemberTest',
        ]);
    }

    public static function tearDownAfterClass(): void
    {
        // team_members and teams CASCADE-delete when user/org is deleted
        self::$db->query("DELETE FROM teams WHERE org_id = ?", [self::$orgId]);
        self::$db->query("DELETE FROM users WHERE org_id = ?", [self::$orgId]);
        self::$db->query("DELETE FROM organisations WHERE id = ?", [self::$orgId]);
    }

    protected function tearDown(): void
    {
        // Remove all memberships for the test team to avoid state leaking between tests
        self::$db->query("DELETE FROM team_members WHERE team_id = ?", [self::$teamId]);
    }

    // ===========================
    // ADD MEMBER
    // ===========================

    #[Test]
    public function testAddMemberInsertsRecord(): void
    {
        TeamMember::addMember(self::$db, self::$teamId, self::$userId);

        $members = TeamMember::findByTeamId(self::$db, self::$teamId);
        $this->assertCount(1, $members);
        $this->assertSame((string) self::$userId, (string) $members[0]['id']);
    }

    #[Test]
    public function testDuplicateAddIsIgnoredSilently(): void
    {
        TeamMember::addMember(self::$db, self::$teamId, self::$userId);
        TeamMember::addMember(self::$db, self::$teamId, self::$userId); // duplicate — INSERT IGNORE

        $members = TeamMember::findByTeamId(self::$db, self::$teamId);
        $this->assertCount(1, $members);
    }

    // ===========================
    // FIND BY TEAM
    // ===========================

    #[Test]
    public function testFindByTeamIdReturnsEmptyWhenNoMembers(): void
    {
        $members = TeamMember::findByTeamId(self::$db, self::$teamId);
        $this->assertCount(0, $members);
    }

    // ===========================
    // FIND TEAMS FOR USER
    // ===========================

    #[Test]
    public function testFindTeamsForUserReturnsTeamsUserBelongsTo(): void
    {
        TeamMember::addMember(self::$db, self::$teamId, self::$userId);

        $teams = TeamMember::findTeamsForUser(self::$db, self::$userId);

        $this->assertCount(1, $teams);
        $this->assertSame('Test Team - TeamMemberTest', $teams[0]['name']);
    }

    // ===========================
    // REMOVE MEMBER
    // ===========================

    #[Test]
    public function testRemoveMemberDeletesRecord(): void
    {
        TeamMember::addMember(self::$db, self::$teamId, self::$userId);
        TeamMember::removeMember(self::$db, self::$teamId, self::$userId);

        $members = TeamMember::findByTeamId(self::$db, self::$teamId);
        $this->assertCount(0, $members);
    }
}
