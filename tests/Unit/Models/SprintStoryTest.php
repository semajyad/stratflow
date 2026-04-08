<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Models;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\Database;
use StratFlow\Models\Sprint;
use StratFlow\Models\SprintStory;
use StratFlow\Models\UserStory;

/**
 * SprintStoryTest
 *
 * Tests the sprint_stories junction table model against the real Docker MySQL database.
 * setUp creates a test org + user + project + sprint + user stories; tearDown removes all.
 */
class SprintStoryTest extends TestCase
{
    // ===========================
    // SETUP / TEARDOWN
    // ===========================

    private static Database $db;
    private static int $orgId;
    private static int $projectId;
    private static int $sprintId;
    private static int $storyId1;
    private static int $storyId2;

    public static function setUpBeforeClass(): void
    {
        self::$db = new Database([
            'host'     => 'mysql',
            'port'     => '3306',
            'database' => 'stratflow',
            'username' => 'stratflow',
            'password' => 'stratflow_secret',
        ]);

        // Clean up any leftover data from a previous failed run
        self::$db->query(
            "DELETE p FROM projects p
             JOIN organisations o ON p.org_id = o.id
             WHERE o.name = 'Test Org - SprintStoryTest'"
        );
        self::$db->query(
            "DELETE u FROM users u
             JOIN organisations o ON u.org_id = o.id
             WHERE o.name = 'Test Org - SprintStoryTest'"
        );
        self::$db->query("DELETE FROM organisations WHERE name = 'Test Org - SprintStoryTest'");

        self::$db->query(
            "INSERT INTO organisations (name) VALUES (?)",
            ['Test Org - SprintStoryTest']
        );
        self::$orgId = (int) self::$db->lastInsertId();

        self::$db->query(
            "INSERT INTO users (org_id, email, password_hash, full_name, role)
             VALUES (?, ?, ?, ?, ?)",
            [self::$orgId, 'testuser_ss@test.invalid', password_hash('pass', PASSWORD_DEFAULT), 'Test User', 'user']
        );
        $userId = (int) self::$db->lastInsertId();

        self::$db->query(
            "INSERT INTO projects (org_id, name, created_by) VALUES (?, ?, ?)",
            [self::$orgId, 'Test Project - SprintStoryTest', $userId]
        );
        self::$projectId = (int) self::$db->lastInsertId();

        // Shared sprint for all tests
        self::$sprintId = Sprint::create(self::$db, [
            'project_id'    => self::$projectId,
            'name'          => 'Sprint 1 - SprintStoryTest',
            'team_capacity' => 30,
        ]);

        // Shared user stories for all tests
        self::$storyId1 = UserStory::create(self::$db, [
            'project_id'      => self::$projectId,
            'priority_number' => 1,
            'title'           => 'Story 1 - SprintStoryTest',
            'size'            => 5,
        ]);
        self::$storyId2 = UserStory::create(self::$db, [
            'project_id'      => self::$projectId,
            'priority_number' => 2,
            'title'           => 'Story 2 - SprintStoryTest',
            'size'            => 8,
        ]);
    }

    public static function tearDownAfterClass(): void
    {
        // FK-safe order: sprint_stories -> user_stories -> sprints -> projects -> users -> orgs
        SprintStory::deleteBySprintId(self::$db, self::$sprintId);
        UserStory::deleteByProjectId(self::$db, self::$projectId);
        Sprint::delete(self::$db, self::$sprintId);
        self::$db->query("DELETE FROM projects WHERE org_id = ?", [self::$orgId]);
        self::$db->query("DELETE FROM users WHERE org_id = ?", [self::$orgId]);
        self::$db->query("DELETE FROM organisations WHERE id = ?", [self::$orgId]);
    }

    protected function tearDown(): void
    {
        // Unassign all stories from the shared sprint after each test
        SprintStory::deleteBySprintId(self::$db, self::$sprintId);
    }

    // ===========================
    // ASSIGN
    // ===========================

    #[Test]
    public function testAssignCreatesLink(): void
    {
        SprintStory::assign(self::$db, self::$sprintId, self::$storyId1);

        $stories = SprintStory::findBySprintId(self::$db, self::$sprintId);

        $this->assertCount(1, $stories);
        $this->assertSame((string) self::$storyId1, (string) $stories[0]['id']);
    }

    // ===========================
    // READ
    // ===========================

    #[Test]
    public function testFindBySprintIdReturnsStories(): void
    {
        SprintStory::assign(self::$db, self::$sprintId, self::$storyId1);
        SprintStory::assign(self::$db, self::$sprintId, self::$storyId2);

        $stories = SprintStory::findBySprintId(self::$db, self::$sprintId);

        $this->assertCount(2, $stories);
    }

    #[Test]
    public function testFindSprintForStory(): void
    {
        SprintStory::assign(self::$db, self::$sprintId, self::$storyId1);

        $sprint = SprintStory::findSprintForStory(self::$db, self::$storyId1);

        $this->assertNotNull($sprint);
        $this->assertSame((string) self::$sprintId, (string) $sprint['id']);
    }

    // ===========================
    // UNASSIGN
    // ===========================

    #[Test]
    public function testUnassignRemovesLink(): void
    {
        SprintStory::assign(self::$db, self::$sprintId, self::$storyId1);
        SprintStory::assign(self::$db, self::$sprintId, self::$storyId2);

        SprintStory::unassign(self::$db, self::$sprintId, self::$storyId1);

        $stories = SprintStory::findBySprintId(self::$db, self::$sprintId);
        $this->assertCount(1, $stories);
        $this->assertSame('Story 2 - SprintStoryTest', $stories[0]['title']);
    }

    // ===========================
    // LOAD CALCULATION
    // ===========================

    #[Test]
    public function testGetSprintLoadCalculatesTotal(): void
    {
        // storyId1 has size=5, storyId2 has size=8 → total=13
        SprintStory::assign(self::$db, self::$sprintId, self::$storyId1);
        SprintStory::assign(self::$db, self::$sprintId, self::$storyId2);

        $load = SprintStory::getSprintLoad(self::$db, self::$sprintId);

        $this->assertSame(13, $load);
    }

    // ===========================
    // DELETE
    // ===========================

    #[Test]
    public function testDeleteBySprintIdClearsAll(): void
    {
        SprintStory::assign(self::$db, self::$sprintId, self::$storyId1);
        SprintStory::assign(self::$db, self::$sprintId, self::$storyId2);

        SprintStory::deleteBySprintId(self::$db, self::$sprintId);

        $stories = SprintStory::findBySprintId(self::$db, self::$sprintId);
        $this->assertCount(0, $stories);
    }
}
