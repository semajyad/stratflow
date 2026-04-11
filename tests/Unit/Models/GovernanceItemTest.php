<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Models;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\Database;
use StratFlow\Models\GovernanceItem;

/**
 * GovernanceItemTest
 *
 * Tests CRUD operations and status filtering against the `governance_queue` table.
 * setUp inserts a test org + user + project; tearDown removes all test data.
 */
class GovernanceItemTest extends TestCase
{
    // ===========================
    // SETUP / TEARDOWN
    // ===========================

    private static Database $db;
    private static int $orgId;
    private static int $projectId;
    private static int $userId;

    public static function setUpBeforeClass(): void
    {
        self::$db = new Database(getTestDbConfig());

        // Clean up any leftover data from a previous failed run
        self::$db->query(
            "DELETE p FROM projects p
             JOIN organisations o ON p.org_id = o.id
             WHERE o.name = 'Test Org - GovernanceItemTest'"
        );
        self::$db->query(
            "DELETE u FROM users u
             JOIN organisations o ON u.org_id = o.id
             WHERE o.name = 'Test Org - GovernanceItemTest'"
        );
        self::$db->query("DELETE FROM organisations WHERE name = 'Test Org - GovernanceItemTest'");

        self::$db->query(
            "INSERT INTO organisations (name) VALUES (?)",
            ['Test Org - GovernanceItemTest']
        );
        self::$orgId = (int) self::$db->lastInsertId();

        self::$db->query(
            "INSERT INTO users (org_id, email, password_hash, full_name, role)
             VALUES (?, ?, ?, ?, ?)",
            [self::$orgId, 'testuser_gov@test.invalid', password_hash('pass', PASSWORD_DEFAULT), 'Gov Test User', 'user']
        );
        self::$userId = (int) self::$db->lastInsertId();

        self::$db->query(
            "INSERT INTO projects (org_id, name, created_by) VALUES (?, ?, ?)",
            [self::$orgId, 'Test Project - GovernanceItemTest', self::$userId]
        );
        self::$projectId = (int) self::$db->lastInsertId();
    }

    public static function tearDownAfterClass(): void
    {
        self::$db->query("DELETE FROM governance_queue WHERE project_id = ?", [self::$projectId]);
        self::$db->query("DELETE FROM projects WHERE org_id = ?", [self::$orgId]);
        self::$db->query("DELETE FROM users WHERE org_id = ?", [self::$orgId]);
        self::$db->query("DELETE FROM organisations WHERE id = ?", [self::$orgId]);
    }

    protected function tearDown(): void
    {
        self::$db->query("DELETE FROM governance_queue WHERE project_id = ?", [self::$projectId]);
    }

    // ===========================
    // CREATE
    // ===========================

    #[Test]
    public function testCreateReturnsPositiveId(): void
    {
        $id = GovernanceItem::create(self::$db, [
            'project_id'          => self::$projectId,
            'change_type'         => 'new_story',
            'proposed_change_json' => ['title' => 'Add login page'],
        ]);

        $this->assertGreaterThan(0, $id);
    }

    // ===========================
    // READ — PENDING FILTER
    // ===========================

    #[Test]
    public function testFindPendingByProjectIdFiltersPending(): void
    {
        // Create one pending and one approved item
        GovernanceItem::create(self::$db, [
            'project_id'          => self::$projectId,
            'change_type'         => 'new_story',
            'proposed_change_json' => ['title' => 'Pending story'],
            'status'              => 'pending',
        ]);

        $approvedId = GovernanceItem::create(self::$db, [
            'project_id'          => self::$projectId,
            'change_type'         => 'scope_change',
            'proposed_change_json' => ['detail' => 'Approved change'],
            'status'              => 'approved',
        ]);

        $pending = GovernanceItem::findPendingByProjectId(self::$db, self::$projectId);

        $this->assertCount(1, $pending);
        $this->assertSame('pending', $pending[0]['status']);

        // Verify the approved item is not in the pending list
        $pendingIds = array_column($pending, 'id');
        $this->assertNotContains((string) $approvedId, $pendingIds);
    }

    // ===========================
    // READ — BY ID
    // ===========================

    #[Test]
    public function testFindByIdReturnsItem(): void
    {
        $id = GovernanceItem::create(self::$db, [
            'project_id'          => self::$projectId,
            'change_type'         => 'size_change',
            'proposed_change_json' => ['sprints' => 5],
        ]);

        $item = GovernanceItem::findById(self::$db, $id);

        $this->assertNotNull($item);
        $this->assertSame((string) $id, (string) $item['id']);
        $this->assertSame('size_change', $item['change_type']);
        $this->assertSame('pending', $item['status']); // default
    }

    // ===========================
    // UPDATE STATUS
    // ===========================

    #[Test]
    public function testUpdateStatusChanges(): void
    {
        $id = GovernanceItem::create(self::$db, [
            'project_id'          => self::$projectId,
            'change_type'         => 'dependency_change',
            'proposed_change_json' => ['dep' => 'auth-service'],
        ]);

        GovernanceItem::updateStatus(self::$db, $id, 'approved');

        $item = GovernanceItem::findById(self::$db, $id);
        $this->assertSame('approved', $item['status']);
    }

    #[Test]
    public function testUpdateStatusWithReviewer(): void
    {
        $id = GovernanceItem::create(self::$db, [
            'project_id'          => self::$projectId,
            'change_type'         => 'new_story',
            'proposed_change_json' => ['title' => 'Reviewed story'],
        ]);

        GovernanceItem::updateStatus(self::$db, $id, 'rejected', self::$userId);

        $item = GovernanceItem::findById(self::$db, $id);
        $this->assertSame('rejected', $item['status']);
        $this->assertSame((string) self::$userId, (string) $item['reviewed_by']);
    }
}
