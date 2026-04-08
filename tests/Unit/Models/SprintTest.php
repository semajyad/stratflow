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
 * SprintTest
 *
 * Tests CRUD operations for the Sprint model against the real Docker MySQL database.
 * setUp creates a test org + user + project; tearDown removes all test data.
 */
class SprintTest extends TestCase
{
    // ===========================
    // SETUP / TEARDOWN
    // ===========================

    private static Database $db;
    private static int $orgId;
    private static int $projectId;

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
             WHERE o.name = 'Test Org - SprintTest'"
        );
        self::$db->query(
            "DELETE u FROM users u
             JOIN organisations o ON u.org_id = o.id
             WHERE o.name = 'Test Org - SprintTest'"
        );
        self::$db->query("DELETE FROM organisations WHERE name = 'Test Org - SprintTest'");

        self::$db->query(
            "INSERT INTO organisations (name) VALUES (?)",
            ['Test Org - SprintTest']
        );
        self::$orgId = (int) self::$db->lastInsertId();

        self::$db->query(
            "INSERT INTO users (org_id, email, password_hash, full_name, role)
             VALUES (?, ?, ?, ?, ?)",
            [self::$orgId, 'testuser_sprint@test.invalid', password_hash('pass', PASSWORD_DEFAULT), 'Test User', 'user']
        );
        $userId = (int) self::$db->lastInsertId();

        self::$db->query(
            "INSERT INTO projects (org_id, name, created_by) VALUES (?, ?, ?)",
            [self::$orgId, 'Test Project - SprintTest', $userId]
        );
        self::$projectId = (int) self::$db->lastInsertId();
    }

    public static function tearDownAfterClass(): void
    {
        // FK-safe order: sprint_stories -> user_stories -> sprints -> projects -> users -> orgs
        UserStory::deleteByProjectId(self::$db, self::$projectId);
        self::$db->query(
            "DELETE s FROM sprints s WHERE s.project_id = ?",
            [self::$projectId]
        );
        self::$db->query("DELETE FROM projects WHERE org_id = ?", [self::$orgId]);
        self::$db->query("DELETE FROM users WHERE org_id = ?", [self::$orgId]);
        self::$db->query("DELETE FROM organisations WHERE id = ?", [self::$orgId]);
    }

    protected function tearDown(): void
    {
        // Clean sprints (CASCADE removes sprint_stories) and user stories after each test
        UserStory::deleteByProjectId(self::$db, self::$projectId);
        self::$db->query("DELETE FROM sprints WHERE project_id = ?", [self::$projectId]);
    }

    // ===========================
    // CREATE
    // ===========================

    #[Test]
    public function testCreateReturnsPositiveId(): void
    {
        $id = Sprint::create(self::$db, [
            'project_id' => self::$projectId,
            'name'       => 'Sprint 1',
        ]);

        $this->assertGreaterThan(0, $id);
    }

    // ===========================
    // READ
    // ===========================

    #[Test]
    public function testFindByProjectIdReturnsAll(): void
    {
        Sprint::create(self::$db, ['project_id' => self::$projectId, 'name' => 'Sprint A', 'start_date' => '2025-01-01']);
        Sprint::create(self::$db, ['project_id' => self::$projectId, 'name' => 'Sprint B', 'start_date' => '2025-01-15']);
        Sprint::create(self::$db, ['project_id' => self::$projectId, 'name' => 'Sprint C', 'start_date' => '2025-01-29']);

        $sprints = Sprint::findByProjectId(self::$db, self::$projectId);

        $this->assertCount(3, $sprints);
    }

    #[Test]
    public function testFindByIdReturnsCreatedSprint(): void
    {
        $id = Sprint::create(self::$db, [
            'project_id'    => self::$projectId,
            'name'          => 'Sprint Alpha',
            'start_date'    => '2025-03-01',
            'end_date'      => '2025-03-14',
            'team_capacity' => 20,
        ]);

        $sprint = Sprint::findById(self::$db, $id);

        $this->assertNotNull($sprint);
        $this->assertSame('Sprint Alpha', $sprint['name']);
        $this->assertSame('20', (string) $sprint['team_capacity']);
    }

    #[Test]
    public function testFindByIdReturnsNullForMissing(): void
    {
        $sprint = Sprint::findById(self::$db, 999999999);

        $this->assertNull($sprint);
    }

    // ===========================
    // UPDATE
    // ===========================

    #[Test]
    public function testUpdateChangesFields(): void
    {
        $id = Sprint::create(self::$db, [
            'project_id'    => self::$projectId,
            'name'          => 'Old name',
            'team_capacity' => 10,
        ]);

        Sprint::update(self::$db, $id, ['name' => 'New name', 'team_capacity' => 15]);

        $sprint = Sprint::findById(self::$db, $id);
        $this->assertSame('New name', $sprint['name']);
        $this->assertSame('15', (string) $sprint['team_capacity']);
    }

    // ===========================
    // DELETE
    // ===========================

    #[Test]
    public function testDeleteRemovesSprint(): void
    {
        $id = Sprint::create(self::$db, [
            'project_id' => self::$projectId,
            'name'       => 'To be deleted',
        ]);

        Sprint::delete(self::$db, $id);

        $this->assertNull(Sprint::findById(self::$db, $id));
    }

    #[Test]
    public function testDeleteCascadesSprintStories(): void
    {
        $sprintId = Sprint::create(self::$db, [
            'project_id' => self::$projectId,
            'name'       => 'Sprint with stories',
        ]);

        $storyId = UserStory::create(self::$db, [
            'project_id'      => self::$projectId,
            'priority_number' => 1,
            'title'           => 'Story in sprint',
        ]);

        SprintStory::assign(self::$db, $sprintId, $storyId);

        // Deleting the sprint should cascade to sprint_stories
        Sprint::delete(self::$db, $sprintId);

        $stories = SprintStory::findBySprintId(self::$db, $sprintId);
        $this->assertCount(0, $stories);
    }
}
