<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Models;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\Database;
use StratFlow\Models\HLWorkItem;
use StratFlow\Models\StrategicBaseline;
use StratFlow\Models\UserStory;

/**
 * StrategicBaselineTest
 *
 * Tests CRUD operations for the StrategicBaseline model against the real Docker MySQL database.
 * setUp creates a test org + user + project; tearDown removes all test data in FK-safe order.
 */
class StrategicBaselineTest extends TestCase
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

        // Clean up any leftover data from a previous failed run
        self::$db->query(
            "DELETE p FROM projects p
             JOIN organisations o ON p.org_id = o.id
             WHERE o.name = 'Test Org - StrategicBaselineTest'"
        );
        self::$db->query(
            "DELETE u FROM users u
             JOIN organisations o ON u.org_id = o.id
             WHERE o.name = 'Test Org - StrategicBaselineTest'"
        );
        self::$db->query("DELETE FROM organisations WHERE name = 'Test Org - StrategicBaselineTest'");

        self::$db->query(
            "INSERT INTO organisations (name) VALUES (?)",
            ['Test Org - StrategicBaselineTest']
        );
        self::$orgId = (int) self::$db->lastInsertId();

        self::$db->query(
            "INSERT INTO users (org_id, email, password_hash, full_name, role)
             VALUES (?, ?, ?, ?, ?)",
            [self::$orgId, 'testuser_sb@test.invalid', password_hash('pass', PASSWORD_DEFAULT), 'Test User', 'user']
        );
        $userId = (int) self::$db->lastInsertId();

        self::$db->query(
            "INSERT INTO projects (org_id, name, created_by) VALUES (?, ?, ?)",
            [self::$orgId, 'Test Project - StrategicBaselineTest', $userId]
        );
        self::$projectId = (int) self::$db->lastInsertId();
    }

    public static function tearDownAfterClass(): void
    {
        // FK-safe order: strategic_baselines -> projects -> users -> orgs
        self::$db->query(
            "DELETE FROM strategic_baselines WHERE project_id = ?",
            [self::$projectId]
        );
        self::$db->query("DELETE FROM projects WHERE org_id = ?", [self::$orgId]);
        self::$db->query("DELETE FROM users WHERE org_id = ?", [self::$orgId]);
        self::$db->query("DELETE FROM organisations WHERE id = ?", [self::$orgId]);
    }

    protected function tearDown(): void
    {
        self::$db->query(
            "DELETE FROM strategic_baselines WHERE project_id = ?",
            [self::$projectId]
        );
    }

    // ===========================
    // CREATE
    // ===========================

    #[Test]
    public function testCreateReturnsPositiveId(): void
    {
        $id = StrategicBaseline::create(self::$db, [
            'project_id'    => self::$projectId,
            'snapshot_json' => json_encode(['work_items' => [], 'stories' => ['total_count' => 0, 'total_size' => 0]]),
        ]);

        $this->assertGreaterThan(0, $id);
    }

    // ===========================
    // READ
    // ===========================

    #[Test]
    public function testFindLatestByProjectIdReturnsNewest(): void
    {
        StrategicBaseline::create(self::$db, [
            'project_id'    => self::$projectId,
            'snapshot_json' => json_encode(['label' => 'older']),
        ]);

        // Small sleep ensures created_at differs (1-second resolution)
        sleep(1);

        $laterId = StrategicBaseline::create(self::$db, [
            'project_id'    => self::$projectId,
            'snapshot_json' => json_encode(['label' => 'newer']),
        ]);

        $latest = StrategicBaseline::findLatestByProjectId(self::$db, self::$projectId);

        $this->assertNotNull($latest);
        $this->assertSame($laterId, (int) $latest['id']);
    }

    #[Test]
    public function testFindByProjectIdReturnsAll(): void
    {
        StrategicBaseline::create(self::$db, [
            'project_id'    => self::$projectId,
            'snapshot_json' => json_encode(['v' => 1]),
        ]);
        StrategicBaseline::create(self::$db, [
            'project_id'    => self::$projectId,
            'snapshot_json' => json_encode(['v' => 2]),
        ]);

        $baselines = StrategicBaseline::findByProjectId(self::$db, self::$projectId);

        $this->assertCount(2, $baselines);
    }

    #[Test]
    public function testFindLatestReturnsNullWhenNone(): void
    {
        $latest = StrategicBaseline::findLatestByProjectId(self::$db, 999999);

        $this->assertNull($latest);
    }

    #[Test]
    public function testSnapshotJsonStoresCorrectly(): void
    {
        $payload = [
            'created_at' => '2025-01-01 12:00:00',
            'work_items' => [['id' => 1, 'title' => 'Item A']],
            'stories'    => ['total_count' => 5, 'total_size' => 21],
        ];

        $id = StrategicBaseline::create(self::$db, [
            'project_id'    => self::$projectId,
            'snapshot_json' => json_encode($payload),
        ]);

        $row      = StrategicBaseline::findLatestByProjectId(self::$db, self::$projectId);
        $decoded  = json_decode($row['snapshot_json'], true);

        $this->assertSame($id, (int) $row['id']);
        $this->assertSame('2025-01-01 12:00:00', $decoded['created_at']);
        $this->assertSame('Item A', $decoded['work_items'][0]['title']);
        $this->assertSame(5, $decoded['stories']['total_count']);
    }
}
