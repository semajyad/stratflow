<?php

declare(strict_types=1);

namespace StratFlow\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\Database;
use StratFlow\Models\HLWorkItem;
use StratFlow\Models\Sprint;
use StratFlow\Models\SprintStory;
use StratFlow\Models\UserStory;

/**
 * UserStorySprintIntegrationTest
 *
 * Integration tests for UserStory ↔ Sprint assignments and related data
 * integrity against the real Docker MySQL database.
 * Verifies business invariants: sprint assignments persist, status changes
 * are durable, velocity aggregates correctly, and cascade rules are correct.
 */
class UserStorySprintIntegrationTest extends TestCase
{
    // ===========================
    // SETUP / TEARDOWN
    // ===========================

    private static Database $db;
    private static int $orgId;
    private static int $userId;
    private static int $projectId;

    public static function setUpBeforeClass(): void
    {
        self::$db = new Database(getTestDbConfig());

        // Clean up any leftovers from a previous failed run (FK-safe order)
        self::$db->query(
            "DELETE p FROM projects p JOIN organisations o ON p.org_id = o.id WHERE o.name = ?",
            ['Test Org - UserStorySprintIntegrationTest']
        );
        self::$db->query(
            "DELETE u FROM users u JOIN organisations o ON u.org_id = o.id WHERE o.name = ?",
            ['Test Org - UserStorySprintIntegrationTest']
        );
        self::$db->query("DELETE FROM organisations WHERE name = ?", ['Test Org - UserStorySprintIntegrationTest']);

        self::$db->query(
            "INSERT INTO organisations (name) VALUES (?)",
            ['Test Org - UserStorySprintIntegrationTest']
        );
        self::$orgId = (int) self::$db->lastInsertId();

        self::$db->query(
            "INSERT INTO users (org_id, email, password_hash, full_name, role) VALUES (?, ?, ?, ?, ?)",
            [self::$orgId, 'testuser_ussi@test.invalid', password_hash('pass', PASSWORD_DEFAULT), 'Test User', 'user']
        );
        self::$userId = (int) self::$db->lastInsertId();

        self::$db->query(
            "INSERT INTO projects (org_id, name, created_by) VALUES (?, ?, ?)",
            [self::$orgId, 'Test Project - UserStorySprintIntegrationTest', self::$userId]
        );
        self::$projectId = (int) self::$db->lastInsertId();
    }

    public static function tearDownAfterClass(): void
    {
        // FK-safe: sprint_stories cascade with sprints; stories deleted explicitly
        UserStory::deleteByProjectId(self::$db, self::$projectId);
        self::$db->query("DELETE FROM sprints WHERE project_id = ?", [self::$projectId]);
        self::$db->query("DELETE FROM hl_work_items WHERE project_id = ?", [self::$projectId]);
        self::$db->query("DELETE FROM projects WHERE org_id = ?", [self::$orgId]);
        self::$db->query("DELETE FROM users WHERE org_id = ?", [self::$orgId]);
        self::$db->query("DELETE FROM organisations WHERE id = ?", [self::$orgId]);
    }

    protected function tearDown(): void
    {
        // Clean up all test data after each test (sprint_stories cascade with sprints)
        UserStory::deleteByProjectId(self::$db, self::$projectId);
        self::$db->query("DELETE FROM sprints WHERE project_id = ?", [self::$projectId]);
        self::$db->query("DELETE FROM hl_work_items WHERE project_id = ?", [self::$projectId]);
    }

    // ===========================
    // TESTS
    // ===========================

    #[Test]
    public function testAssigningStoryToSprintCreatesSprintStoryRow(): void
    {
        $sprintId = Sprint::create(self::$db, [
            'project_id' => self::$projectId,
            'name'       => 'Sprint 1',
            'start_date' => '2025-06-01',
            'end_date'   => '2025-06-14',
        ]);

        $storyId = UserStory::create(self::$db, [
            'project_id'      => self::$projectId,
            'priority_number' => 1,
            'title'           => 'Story assigned to sprint',
        ]);

        SprintStory::assign(self::$db, $sprintId, $storyId);

        $stmt = self::$db->query(
            "SELECT * FROM sprint_stories WHERE sprint_id = ? AND user_story_id = ?",
            [$sprintId, $storyId]
        );
        $row = $stmt->fetch();

        $this->assertNotFalse($row, 'sprint_stories row should be created on assign()');
        $this->assertSame($sprintId, (int) $row['sprint_id']);
        $this->assertSame($storyId, (int) $row['user_story_id']);
    }

    #[Test]
    public function testStoryStatusProgressionPreservesData(): void
    {
        $storyId = UserStory::create(self::$db, [
            'project_id'      => self::$projectId,
            'priority_number' => 1,
            'title'           => 'Status progression story',
            'status'          => 'backlog',
        ]);

        $statuses = ['in_progress', 'in_review', 'done'];

        foreach ($statuses as $status) {
            UserStory::update(self::$db, $storyId, ['status' => $status]);

            $row = UserStory::findById(self::$db, $storyId);
            $this->assertSame(
                $status,
                $row['status'],
                "Status should be '{$status}' immediately after update"
            );
        }

        // Verify final persisted state is the last status set
        $final = UserStory::findById(self::$db, $storyId);
        $this->assertSame('done', $final['status'], 'Final status should be the last one written');
    }

    #[Test]
    public function testSprintVelocityAggregatesFromLinkedStories(): void
    {
        $sprintId = Sprint::create(self::$db, [
            'project_id'    => self::$projectId,
            'name'          => 'Velocity Sprint',
            'team_capacity' => 30,
        ]);

        // 2 done, 1 in-progress
        $storyDone1 = UserStory::create(self::$db, [
            'project_id'      => self::$projectId,
            'priority_number' => 1,
            'title'           => 'Done story 1',
            'size'            => 5,
            'status'          => 'done',
        ]);
        $storyDone2 = UserStory::create(self::$db, [
            'project_id'      => self::$projectId,
            'priority_number' => 2,
            'title'           => 'Done story 2',
            'size'            => 8,
            'status'          => 'done',
        ]);
        $storyInProgress = UserStory::create(self::$db, [
            'project_id'      => self::$projectId,
            'priority_number' => 3,
            'title'           => 'In-progress story',
            'size'            => 3,
            'status'          => 'in_progress',
        ]);

        SprintStory::assign(self::$db, $sprintId, $storyDone1);
        SprintStory::assign(self::$db, $sprintId, $storyDone2);
        SprintStory::assign(self::$db, $sprintId, $storyInProgress);

        // Query velocity directly from DB (same logic as Sprint::findByProjectId uses)
        $stmt = self::$db->query("SELECT
                COUNT(*) AS total_stories,
                SUM(CASE WHEN us.status = 'done' THEN 1 ELSE 0 END) AS done_count,
                SUM(CASE WHEN us.status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress_count,
                SUM(COALESCE(us.size, 0)) AS total_size,
                SUM(CASE WHEN us.status = 'done' THEN COALESCE(us.size, 0) ELSE 0 END) AS completed_size
             FROM sprint_stories ss
             JOIN user_stories us ON ss.user_story_id = us.id
             WHERE ss.sprint_id = ?", [$sprintId]);

        $metrics = $stmt->fetch();

        $this->assertSame(3, (int) $metrics['total_stories'], 'All 3 stories should be counted');
        $this->assertSame(2, (int) $metrics['done_count'], '2 stories should be done');
        $this->assertSame(1, (int) $metrics['in_progress_count'], '1 story should be in-progress');
        $this->assertSame(16, (int) $metrics['total_size'], 'Total size should be 5+8+3=16');
        $this->assertSame(13, (int) $metrics['completed_size'], 'Completed size should be 5+8=13');
    }

    #[Test]
    public function testDeletingSprintDoesNotDeleteStories(): void
    {
        $sprintId = Sprint::create(self::$db, [
            'project_id' => self::$projectId,
            'name'       => 'Sprint to delete',
        ]);

        $storyId = UserStory::create(self::$db, [
            'project_id'      => self::$projectId,
            'priority_number' => 1,
            'title'           => 'Story that should survive sprint deletion',
        ]);

        SprintStory::assign(self::$db, $sprintId, $storyId);

        Sprint::delete(self::$db, $sprintId);

        // Sprint is gone
        $this->assertNull(Sprint::findById(self::$db, $sprintId), 'Sprint should be deleted');

        // Story persists independently
        $story = UserStory::findById(self::$db, $storyId);
        $this->assertNotNull($story, 'User story should survive sprint deletion');
        $this->assertSame('Story that should survive sprint deletion', $story['title']);

        // Sprint-story link is cascade-deleted (this is correct FK behaviour)
        $linked = SprintStory::findBySprintId(self::$db, $sprintId);
        $this->assertCount(0, $linked, 'sprint_stories rows should be cascade-deleted with the sprint');
    }

    #[Test]
    public function testStoryCreatesWithHlWorkItemParentForeignKey(): void
    {
        $hlItemId = HLWorkItem::create(self::$db, [
            'project_id'      => self::$projectId,
            'priority_number' => 1,
            'title'           => 'Parent HL Work Item',
        ]);

        $storyId = UserStory::create(self::$db, [
            'project_id'        => self::$projectId,
            'parent_hl_item_id' => $hlItemId,
            'priority_number'   => 1,
            'title'             => 'Child story with HL parent',
        ]);

        $story = UserStory::findById(self::$db, $storyId);

        $this->assertNotNull($story, 'Story should be created with a valid HL parent FK');
        $this->assertSame($hlItemId, (int) $story['parent_hl_item_id']);
        $this->assertSame('Parent HL Work Item', $story['parent_title'], 'JOIN should surface parent title');
    }

    #[Test]
    public function testConcurrentUpdatesToSameStoryLastWriteWins(): void
    {
        $storyId = UserStory::create(self::$db, [
            'project_id'      => self::$projectId,
            'priority_number' => 1,
            'title'           => 'Concurrent update story',
        ]);

        // Simulate two sequential writes — last one wins (no optimistic locking in this model)
        UserStory::update(self::$db, $storyId, ['title' => 'First write', 'size' => 3]);
        UserStory::update(self::$db, $storyId, ['title' => 'Second write', 'size' => 8]);

        $story = UserStory::findById(self::$db, $storyId);

        $this->assertSame('Second write', $story['title'], 'Last write should win on title');
        $this->assertSame(8, (int) $story['size'], 'Last write should win on size');
    }
}
