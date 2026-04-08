<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Models;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\Database;
use StratFlow\Models\HLWorkItem;
use StratFlow\Models\UserStory;

/**
 * UserStoryTest
 *
 * Tests CRUD operations for the UserStory model against the real Docker MySQL database.
 * setUp creates a test org + user + project + HL work item; tearDown removes all test data.
 */
class UserStoryTest extends TestCase
{
    // ===========================
    // SETUP / TEARDOWN
    // ===========================

    private static Database $db;
    private static int $orgId;
    private static int $projectId;
    private static int $hlItemId;

    public static function setUpBeforeClass(): void
    {
        self::$db = new Database(getTestDbConfig());

        // Clean up any leftover data from a previous failed run
        self::$db->query(
            "DELETE p FROM projects p
             JOIN organisations o ON p.org_id = o.id
             WHERE o.name = 'Test Org - UserStoryTest'"
        );
        self::$db->query(
            "DELETE u FROM users u
             JOIN organisations o ON u.org_id = o.id
             WHERE o.name = 'Test Org - UserStoryTest'"
        );
        self::$db->query("DELETE FROM organisations WHERE name = 'Test Org - UserStoryTest'");

        self::$db->query(
            "INSERT INTO organisations (name) VALUES (?)",
            ['Test Org - UserStoryTest']
        );
        self::$orgId = (int) self::$db->lastInsertId();

        self::$db->query(
            "INSERT INTO users (org_id, email, password_hash, full_name, role)
             VALUES (?, ?, ?, ?, ?)",
            [self::$orgId, 'testuser_us@test.invalid', password_hash('pass', PASSWORD_DEFAULT), 'Test User', 'user']
        );
        $userId = (int) self::$db->lastInsertId();

        self::$db->query(
            "INSERT INTO projects (org_id, name, created_by) VALUES (?, ?, ?)",
            [self::$orgId, 'Test Project - UserStoryTest', $userId]
        );
        self::$projectId = (int) self::$db->lastInsertId();

        // Create an HL work item to use as parent
        self::$hlItemId = HLWorkItem::create(self::$db, [
            'project_id'      => self::$projectId,
            'priority_number' => 1,
            'title'           => 'Parent HL Item - UserStoryTest',
        ]);
    }

    public static function tearDownAfterClass(): void
    {
        // FK-safe order: user_stories -> hl_work_items -> projects -> users -> orgs
        UserStory::deleteByProjectId(self::$db, self::$projectId);
        HLWorkItem::deleteByProjectId(self::$db, self::$projectId);
        self::$db->query("DELETE FROM projects WHERE org_id = ?", [self::$orgId]);
        self::$db->query("DELETE FROM users WHERE org_id = ?", [self::$orgId]);
        self::$db->query("DELETE FROM organisations WHERE id = ?", [self::$orgId]);
    }

    protected function tearDown(): void
    {
        UserStory::deleteByProjectId(self::$db, self::$projectId);
    }

    // ===========================
    // CREATE
    // ===========================

    #[Test]
    public function testCreateReturnsPositiveId(): void
    {
        $id = UserStory::create(self::$db, [
            'project_id'      => self::$projectId,
            'priority_number' => 1,
            'title'           => 'As a user, I want to log in',
        ]);

        $this->assertGreaterThan(0, $id);
    }

    // ===========================
    // READ
    // ===========================

    #[Test]
    public function testFindByIdReturnsCreatedStory(): void
    {
        $id = UserStory::create(self::$db, [
            'project_id'      => self::$projectId,
            'priority_number' => 1,
            'title'           => 'As a user, I want to see my dashboard',
            'description'     => 'So I can track progress',
        ]);

        $story = UserStory::findById(self::$db, $id);

        $this->assertNotNull($story);
        $this->assertSame('As a user, I want to see my dashboard', $story['title']);
        $this->assertSame('So I can track progress', $story['description']);
    }

    #[Test]
    public function testFindByProjectIdReturnsAll(): void
    {
        UserStory::create(self::$db, ['project_id' => self::$projectId, 'priority_number' => 1, 'title' => 'Story A']);
        UserStory::create(self::$db, ['project_id' => self::$projectId, 'priority_number' => 2, 'title' => 'Story B']);
        UserStory::create(self::$db, ['project_id' => self::$projectId, 'priority_number' => 3, 'title' => 'Story C']);

        $stories = UserStory::findByProjectId(self::$db, self::$projectId);

        $this->assertCount(3, $stories);
    }

    #[Test]
    public function testFindByProjectIdOrdersByPriority(): void
    {
        UserStory::create(self::$db, ['project_id' => self::$projectId, 'priority_number' => 3, 'title' => 'Third']);
        UserStory::create(self::$db, ['project_id' => self::$projectId, 'priority_number' => 1, 'title' => 'First']);
        UserStory::create(self::$db, ['project_id' => self::$projectId, 'priority_number' => 2, 'title' => 'Second']);

        $stories = UserStory::findByProjectId(self::$db, self::$projectId);

        $this->assertSame('First',  $stories[0]['title']);
        $this->assertSame('Second', $stories[1]['title']);
        $this->assertSame('Third',  $stories[2]['title']);
    }

    #[Test]
    public function testFindByParentIdReturnsChildStories(): void
    {
        UserStory::create(self::$db, [
            'project_id'        => self::$projectId,
            'priority_number'   => 1,
            'title'             => 'Child Story',
            'parent_hl_item_id' => self::$hlItemId,
        ]);
        // Story without the parent — should not appear
        UserStory::create(self::$db, [
            'project_id'      => self::$projectId,
            'priority_number' => 2,
            'title'           => 'Orphan Story',
        ]);

        $children = UserStory::findByParentId(self::$db, self::$hlItemId);

        $this->assertCount(1, $children);
        $this->assertSame('Child Story', $children[0]['title']);
    }

    // ===========================
    // UPDATE
    // ===========================

    #[Test]
    public function testUpdateChangesFields(): void
    {
        $id = UserStory::create(self::$db, [
            'project_id'      => self::$projectId,
            'priority_number' => 1,
            'title'           => 'Before update',
            'team_assigned'   => 'Alpha',
        ]);

        UserStory::update(self::$db, $id, ['title' => 'After update', 'team_assigned' => 'Beta']);

        $story = UserStory::findById(self::$db, $id);
        $this->assertSame('After update', $story['title']);
        $this->assertSame('Beta', $story['team_assigned']);
    }

    // ===========================
    // BATCH UPDATE
    // ===========================

    #[Test]
    public function testBatchUpdatePriorityWorks(): void
    {
        $idA = UserStory::create(self::$db, ['project_id' => self::$projectId, 'priority_number' => 1, 'title' => 'Alpha']);
        $idB = UserStory::create(self::$db, ['project_id' => self::$projectId, 'priority_number' => 2, 'title' => 'Beta']);

        UserStory::batchUpdatePriority(self::$db, [
            ['id' => $idA, 'priority_number' => 10],
            ['id' => $idB, 'priority_number' => 20],
        ]);

        $storyA = UserStory::findById(self::$db, $idA);
        $storyB = UserStory::findById(self::$db, $idB);
        $this->assertSame('10', (string) $storyA['priority_number']);
        $this->assertSame('20', (string) $storyB['priority_number']);
    }

    // ===========================
    // COUNT
    // ===========================

    #[Test]
    public function testCountByProjectId(): void
    {
        UserStory::create(self::$db, ['project_id' => self::$projectId, 'priority_number' => 1, 'title' => 'One']);
        UserStory::create(self::$db, ['project_id' => self::$projectId, 'priority_number' => 2, 'title' => 'Two']);

        $count = UserStory::countByProjectId(self::$db, self::$projectId);

        $this->assertSame(2, $count);
    }

    // ===========================
    // DELETE
    // ===========================

    #[Test]
    public function testDeleteRemovesStory(): void
    {
        $id = UserStory::create(self::$db, [
            'project_id'      => self::$projectId,
            'priority_number' => 1,
            'title'           => 'To be deleted',
        ]);

        UserStory::delete(self::$db, $id);

        $this->assertNull(UserStory::findById(self::$db, $id));
    }

    #[Test]
    public function testDeleteByProjectIdRemovesAll(): void
    {
        UserStory::create(self::$db, ['project_id' => self::$projectId, 'priority_number' => 1, 'title' => 'X']);
        UserStory::create(self::$db, ['project_id' => self::$projectId, 'priority_number' => 2, 'title' => 'Y']);

        UserStory::deleteByProjectId(self::$db, self::$projectId);

        $stories = UserStory::findByProjectId(self::$db, self::$projectId);
        $this->assertCount(0, $stories);
    }
}
