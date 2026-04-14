<?php

declare(strict_types=1);

namespace StratFlow\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\Database;
use StratFlow\Models\HLWorkItem;

/**
 * HLWorkItemTest
 *
 * Tests CRUD operations against the real Docker MySQL database.
 * setUp inserts a test org + project; tearDown removes all test data.
 */
class HLWorkItemTest extends TestCase
{
    // ===========================
    // SETUP / TEARDOWN
    // ===========================

    private static Database $db;
    private static int $orgId;
    private static int $projectId;

    public static function setUpBeforeClass(): void
    {
        self::$db = new Database(getTestDbConfig());

        // Clean up any leftover data from a previous failed run (FK-safe order)
        self::$db->query(
            "DELETE p FROM projects p
             JOIN organisations o ON p.org_id = o.id
             WHERE o.name = 'Test Org - HLWorkItemTest'"
        );
        self::$db->query(
            "DELETE u FROM users u
             JOIN organisations o ON u.org_id = o.id
             WHERE o.name = 'Test Org - HLWorkItemTest'"
        );
        self::$db->query("DELETE FROM organisations WHERE name = 'Test Org - HLWorkItemTest'");

        // Insert a test organisation
        self::$db->query(
            "INSERT INTO organisations (name) VALUES (?)",
            ['Test Org - HLWorkItemTest']
        );
        self::$orgId = (int) self::$db->lastInsertId();

        // Insert a test user (required for project FK)
        self::$db->query(
            "INSERT INTO users (org_id, email, password_hash, full_name, role)
             VALUES (?, ?, ?, ?, ?)",
            [self::$orgId, 'testuser_hlwi@test.invalid', password_hash('pass', PASSWORD_DEFAULT), 'Test User', 'user']
        );
        $userId = (int) self::$db->lastInsertId();

        // Insert a test project
        self::$db->query(
            "INSERT INTO projects (org_id, name, created_by) VALUES (?, ?, ?)",
            [self::$orgId, 'Test Project - HLWorkItemTest', $userId]
        );
        self::$projectId = (int) self::$db->lastInsertId();
    }

    public static function tearDownAfterClass(): void
    {
        // Delete in FK-safe order:
        // - projects.created_by RESTRICT on users, so delete projects first
        // - users.org_id RESTRICT on organisations, so delete users after projects
        // - hl_work_items are CASCADE-deleted when the project is deleted
        self::$db->query("DELETE FROM projects WHERE org_id = ?", [self::$orgId]);
        self::$db->query("DELETE FROM users WHERE org_id = ?", [self::$orgId]);
        self::$db->query("DELETE FROM organisations WHERE id = ?", [self::$orgId]);
    }

    protected function tearDown(): void
    {
        // Clean up work items after each test to avoid state leaking between tests
        HLWorkItem::deleteByProjectId(self::$db, self::$projectId);
    }

    // ===========================
    // CREATE
    // ===========================

    #[Test]
    public function testCreateReturnsPositiveId(): void
    {
        $id = HLWorkItem::create(self::$db, [
            'project_id'      => self::$projectId,
            'priority_number' => 1,
            'title'           => 'Test work item',
        ]);

        $this->assertGreaterThan(0, $id);
    }

    #[Test]
    public function testCreatedItemCanBeFoundById(): void
    {
        $id   = HLWorkItem::create(self::$db, [
            'project_id'      => self::$projectId,
            'priority_number' => 1,
            'title'           => 'My work item',
            'description'     => 'Some description',
        ]);

        $item = HLWorkItem::findById(self::$db, $id);

        $this->assertNotNull($item);
        $this->assertSame('My work item', $item['title']);
        $this->assertSame('Some description', $item['description']);
    }

    #[Test]
    public function testCreateWithOptionalFieldsStoredCorrectly(): void
    {
        $id = HLWorkItem::create(self::$db, [
            'project_id'         => self::$projectId,
            'priority_number'    => 2,
            'title'              => 'Item with extras',
            'strategic_context'  => 'Strategic context here',
            'okr_title'          => 'Grow revenue',
            'owner'              => 'Alice',
            'estimated_sprints'  => 4,
        ]);

        $item = HLWorkItem::findById(self::$db, $id);

        $this->assertSame('Strategic context here', $item['strategic_context']);
        $this->assertSame('Grow revenue', $item['okr_title']);
        $this->assertSame('Alice', $item['owner']);
        $this->assertSame('4', (string) $item['estimated_sprints']);
    }

    // ===========================
    // READ
    // ===========================

    #[Test]
    public function testFindByIdReturnsNullForMissingRecord(): void
    {
        $item = HLWorkItem::findById(self::$db, 999999999);
        $this->assertNull($item);
    }

    #[Test]
    public function testFindByProjectIdReturnsAllItems(): void
    {
        HLWorkItem::create(self::$db, ['project_id' => self::$projectId, 'priority_number' => 1, 'title' => 'A']);
        HLWorkItem::create(self::$db, ['project_id' => self::$projectId, 'priority_number' => 2, 'title' => 'B']);
        HLWorkItem::create(self::$db, ['project_id' => self::$projectId, 'priority_number' => 3, 'title' => 'C']);

        $items = HLWorkItem::findByProjectId(self::$db, self::$projectId);

        $this->assertCount(3, $items);
    }

    #[Test]
    public function testFindByProjectIdOrdersByPriority(): void
    {
        HLWorkItem::create(self::$db, ['project_id' => self::$projectId, 'priority_number' => 3, 'title' => 'Third']);
        HLWorkItem::create(self::$db, ['project_id' => self::$projectId, 'priority_number' => 1, 'title' => 'First']);
        HLWorkItem::create(self::$db, ['project_id' => self::$projectId, 'priority_number' => 2, 'title' => 'Second']);

        $items = HLWorkItem::findByProjectId(self::$db, self::$projectId);

        $this->assertSame('First',  $items[0]['title']);
        $this->assertSame('Second', $items[1]['title']);
        $this->assertSame('Third',  $items[2]['title']);
    }

    #[Test]
    public function testFindByProjectIdReturnsEmptyForUnknownProject(): void
    {
        $items = HLWorkItem::findByProjectId(self::$db, 999999999);
        $this->assertCount(0, $items);
    }

    // ===========================
    // UPDATE
    // ===========================

    #[Test]
    public function testUpdateChangesTitle(): void
    {
        $id = HLWorkItem::create(self::$db, [
            'project_id'      => self::$projectId,
            'priority_number' => 1,
            'title'           => 'Original title',
        ]);

        HLWorkItem::update(self::$db, $id, ['title' => 'Updated title']);

        $item = HLWorkItem::findById(self::$db, $id);
        $this->assertSame('Updated title', $item['title']);
    }

    #[Test]
    public function testUpdateMultipleColumns(): void
    {
        $id = HLWorkItem::create(self::$db, [
            'project_id'      => self::$projectId,
            'priority_number' => 1,
            'title'           => 'Before',
            'owner'           => 'Bob',
        ]);

        HLWorkItem::update(self::$db, $id, ['title' => 'After', 'owner' => 'Carol']);

        $item = HLWorkItem::findById(self::$db, $id);
        $this->assertSame('After', $item['title']);
        $this->assertSame('Carol', $item['owner']);
    }

    // ===========================
    // DELETE
    // ===========================

    #[Test]
    public function testDeleteRemovesSingleItem(): void
    {
        $id = HLWorkItem::create(self::$db, [
            'project_id'      => self::$projectId,
            'priority_number' => 1,
            'title'           => 'To be deleted',
        ]);

        HLWorkItem::delete(self::$db, $id);

        $this->assertNull(HLWorkItem::findById(self::$db, $id));
    }

    #[Test]
    public function testDeleteByProjectIdRemovesAllItems(): void
    {
        HLWorkItem::create(self::$db, ['project_id' => self::$projectId, 'priority_number' => 1, 'title' => 'X']);
        HLWorkItem::create(self::$db, ['project_id' => self::$projectId, 'priority_number' => 2, 'title' => 'Y']);

        HLWorkItem::deleteByProjectId(self::$db, self::$projectId);

        $items = HLWorkItem::findByProjectId(self::$db, self::$projectId);
        $this->assertCount(0, $items);
    }

    #[Test]
    public function canStoreAndRetrieveQualityScore(): void
    {
        $id = HLWorkItem::create(self::$db, [
            'project_id'       => self::$projectId,
            'priority_number'  => 98,
            'title'            => 'Quality Score Test Item',
            'quality_score'    => 75,
            'quality_breakdown' => json_encode([
                'invest' => ['score' => 15, 'max' => 20, 'issues' => []],
            ]),
        ]);

        $row = HLWorkItem::findById(self::$db, $id);
        $this->assertNotNull($row);
        $this->assertSame(75, (int) $row['quality_score']);
        $this->assertStringContainsString('invest', $row['quality_breakdown']);

        HLWorkItem::delete(self::$db, $id);
    }
}
