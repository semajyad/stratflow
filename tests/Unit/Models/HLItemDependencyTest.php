<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Models;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\Database;
use StratFlow\Models\HLItemDependency;
use StratFlow\Models\HLWorkItem;

/**
 * HLItemDependencyTest
 *
 * Tests CRUD operations on `hl_item_dependencies` against the real Docker MySQL database.
 * setUp inserts a test org, user, project, and three work items.
 * tearDown removes all test data in FK-safe order.
 */
class HLItemDependencyTest extends TestCase
{
    // ===========================
    // SETUP / TEARDOWN
    // ===========================

    private static Database $db;
    private static int $orgId;
    private static int $projectId;
    private static int $itemA;
    private static int $itemB;
    private static int $itemC;

    public static function setUpBeforeClass(): void
    {
        self::$db = new Database([
            'host'     => 'mysql',
            'port'     => '3306',
            'database' => 'stratflow',
            'username' => 'stratflow',
            'password' => 'stratflow_secret',
        ]);

        // Clean up any leftover data from a previous failed run (FK-safe order)
        self::$db->query(
            "DELETE p FROM projects p
             JOIN organisations o ON p.org_id = o.id
             WHERE o.name = 'Test Org - HLItemDependencyTest'"
        );
        self::$db->query(
            "DELETE u FROM users u
             JOIN organisations o ON u.org_id = o.id
             WHERE o.name = 'Test Org - HLItemDependencyTest'"
        );
        self::$db->query("DELETE FROM organisations WHERE name = 'Test Org - HLItemDependencyTest'");

        // Insert a test organisation
        self::$db->query(
            "INSERT INTO organisations (name) VALUES (?)",
            ['Test Org - HLItemDependencyTest']
        );
        self::$orgId = (int) self::$db->lastInsertId();

        // Insert a test user (required for project FK)
        self::$db->query(
            "INSERT INTO users (org_id, email, password_hash, full_name, role)
             VALUES (?, ?, ?, ?, ?)",
            [self::$orgId, 'testuser_hlid@test.invalid', password_hash('pass', PASSWORD_DEFAULT), 'Test User', 'user']
        );
        $userId = (int) self::$db->lastInsertId();

        // Insert a test project
        self::$db->query(
            "INSERT INTO projects (org_id, name, created_by) VALUES (?, ?, ?)",
            [self::$orgId, 'Test Project - HLItemDependencyTest', $userId]
        );
        self::$projectId = (int) self::$db->lastInsertId();

        // Insert three work items: A, B, C
        self::$itemA = HLWorkItem::create(self::$db, [
            'project_id'      => self::$projectId,
            'priority_number' => 1,
            'title'           => 'Item A',
        ]);
        self::$itemB = HLWorkItem::create(self::$db, [
            'project_id'      => self::$projectId,
            'priority_number' => 2,
            'title'           => 'Item B',
        ]);
        self::$itemC = HLWorkItem::create(self::$db, [
            'project_id'      => self::$projectId,
            'priority_number' => 3,
            'title'           => 'Item C',
        ]);
    }

    public static function tearDownAfterClass(): void
    {
        // Delete in FK-safe order
        self::$db->query(
            "DELETE d FROM hl_item_dependencies d
             JOIN hl_work_items w ON w.id = d.item_id
             WHERE w.project_id = ?",
            [self::$projectId]
        );
        self::$db->query("DELETE FROM hl_work_items WHERE project_id = ?", [self::$projectId]);
        self::$db->query("DELETE FROM projects WHERE org_id = ?", [self::$orgId]);
        self::$db->query("DELETE FROM users WHERE org_id = ?", [self::$orgId]);
        self::$db->query("DELETE FROM organisations WHERE id = ?", [self::$orgId]);
    }

    protected function tearDown(): void
    {
        // Remove all dependencies created during a test to prevent cross-test contamination
        HLItemDependency::deleteByItemId(self::$db, self::$itemA);
        HLItemDependency::deleteByItemId(self::$db, self::$itemB);
        HLItemDependency::deleteByItemId(self::$db, self::$itemC);
    }

    // ===========================
    // CREATE
    // ===========================

    #[Test]
    public function testCreateReturnsPositiveId(): void
    {
        // A depends on B
        $id = HLItemDependency::create(self::$db, [
            'item_id'       => self::$itemA,
            'depends_on_id' => self::$itemB,
        ]);

        $this->assertGreaterThan(0, $id);
    }

    #[Test]
    public function testCreateBatchCreatesMultiple(): void
    {
        // A depends on both B and C
        HLItemDependency::createBatch(self::$db, self::$itemA, [self::$itemB, self::$itemC]);

        $deps = HLItemDependency::findByItemId(self::$db, self::$itemA);

        $this->assertCount(2, $deps);
    }

    // ===========================
    // READ
    // ===========================

    #[Test]
    public function testFindByItemIdReturnsDependencies(): void
    {
        // A depends on B
        HLItemDependency::create(self::$db, [
            'item_id'       => self::$itemA,
            'depends_on_id' => self::$itemB,
        ]);

        $deps = HLItemDependency::findByItemId(self::$db, self::$itemA);

        $this->assertCount(1, $deps);
        $this->assertSame((string) self::$itemB, (string) $deps[0]['depends_on_id']);
    }

    #[Test]
    public function testFindDependentsOfReturnsDependent(): void
    {
        // A depends on B — so B's dependents should include A
        HLItemDependency::create(self::$db, [
            'item_id'       => self::$itemA,
            'depends_on_id' => self::$itemB,
        ]);

        $dependents = HLItemDependency::findDependentsOf(self::$db, self::$itemB);

        $this->assertCount(1, $dependents);
        $this->assertSame((string) self::$itemA, (string) $dependents[0]['item_id']);
    }

    // ===========================
    // DELETE
    // ===========================

    #[Test]
    public function testDeleteByItemIdRemovesDependencies(): void
    {
        // A depends on B and C
        HLItemDependency::create(self::$db, ['item_id' => self::$itemA, 'depends_on_id' => self::$itemB]);
        HLItemDependency::create(self::$db, ['item_id' => self::$itemA, 'depends_on_id' => self::$itemC]);

        HLItemDependency::deleteByItemId(self::$db, self::$itemA);

        $deps = HLItemDependency::findByItemId(self::$db, self::$itemA);
        $this->assertCount(0, $deps);
    }

    // ===========================
    // CONSTRAINTS
    // ===========================

    #[Test]
    public function testUniqueConstraintPreventsDuplicates(): void
    {
        // Insert A depends on B twice — ON DUPLICATE KEY UPDATE should not throw
        HLItemDependency::create(self::$db, ['item_id' => self::$itemA, 'depends_on_id' => self::$itemB]);
        HLItemDependency::create(self::$db, ['item_id' => self::$itemA, 'depends_on_id' => self::$itemB]);

        // Only one row should exist
        $deps = HLItemDependency::findByItemId(self::$db, self::$itemA);
        $this->assertCount(1, $deps);
    }
}
